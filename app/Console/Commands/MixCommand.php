<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\DataObjects\CoverageReport;
use App\DataObjects\Story;
use App\Services\Audio\AttributionWriter;
use App\Services\Audio\CoverageAuditor;
use App\Services\Audio\StoryMixer;
use App\Services\Audio\StorySoundManifest;
use App\Services\Storage\TempSweeper;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use InvalidArgumentException;
use JsonException;
use Throwable;

final class MixCommand extends Command
{
    protected $signature = 'story:mix
        {file : JSON del guion}
        {--no-music : Omite la música}
        {--no-sfx : Omite los efectos}
        {--no-ambience : Omite la cama de ambiente}
        {--dry-run : Imprime la tabla de pistas sin generar audio}';

    protected $description = 'Mezcla y masteriza el audio de una historia a partir de sounds.json';

    private const LUFS_TOLERANCE = 0.5;

    private readonly string $outputDirectory;

    private readonly float $targetLufs;

    private readonly float $targetTruePeak;

    public function __construct(
        private StoryMixer $mixer,
        private StorySoundManifest $manifest,
        private CoverageAuditor $auditor,
        private AttributionWriter $attribution,
        private TempSweeper $sweeper,
        private Filesystem $files,
        Repository $config,
    ) {
        parent::__construct();

        $this->outputDirectory = storage_path('app/'.$config->get('stories.output_path'));
        $this->targetLufs = (float) $config->get('stories.ffmpeg.loudnorm.I', -14.0);
        $this->targetTruePeak = (float) $config->get('stories.ffmpeg.loudnorm.TP', -1.5);
    }

    public function handle(): int
    {
        $this->sweepOrphans();

        $storyFile = $this->resolveStoryFile((string) $this->argument('file'));

        if ($storyFile === null) {
            return self::FAILURE;
        }

        $payload = $this->readStoryPayload($storyFile);

        if ($payload === null) {
            return self::FAILURE;
        }

        $slug = pathinfo($storyFile, PATHINFO_FILENAME);
        $story = Story::fromArray($payload);
        $dryRun = (bool) $this->option('dry-run');
        $directory = $this->outputDirectory.DIRECTORY_SEPARATOR.$slug;
        $narration = $directory.DIRECTORY_SEPARATOR.'narration.wav';
        $timingsPath = $directory.DIRECTORY_SEPARATOR.'timings.json';

        if (! $this->files->isFile($narration)) {
            $this->error('No hay narration.wav. Ejecuta story:narrate primero.');

            return self::FAILURE;
        }

        if (! $this->files->isFile($timingsPath)) {
            $this->error('No hay timings.json. Ejecuta story:narrate primero.');

            return self::FAILURE;
        }

        try {
            if (! $this->manifest->exists($slug)) {
                $this->manifest->sync($slug, $story, $this->readTimings($timingsPath));
            }

            $cues = $this->manifest->load($slug)['cues'];
            $report = $this->auditor->audit($story, $cues, $narration);
            $this->renderCoverage($report);

            if (! $report->passed) {
                $this->error('Hay bloqueantes. No se mezcla.');

                return self::FAILURE;
            }

            $result = $this->mixer->mix($slug, $story, [
                'noAmbience' => (bool) $this->option('no-ambience'),
                'noSfx' => (bool) $this->option('no-sfx'),
                'noMusic' => (bool) $this->option('no-music'),
                'dryRun' => $dryRun,
            ]);
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->renderTracks($result['tracks']);
        $this->line(sprintf(
            'Duración del máster: %.3f s (última frase %.3f s + cola %.1f s).',
            $result['duration'],
            $result['lastTranscribedPhraseEnd'],
            $result['tailSeconds'],
        ));

        if ($dryRun) {
            $this->comment('Simulación: no se generó audio.');

            return self::SUCCESS;
        }

        $this->renderMeasurement($result['measurement']);
        $credits = $this->attribution->cueCredits($result['usedCues']);
        $this->renderCredits($credits);
        $this->info('Mezcla: '.$result['wav']);
        $this->line('Escucha: '.$result['mp3']);
        $this->line('Créditos: '.$this->writeCredits($slug, $credits));

        return self::SUCCESS;
    }

    private function sweepOrphans(): void
    {
        $swept = $this->sweeper->sweep();

        if ($swept['entries'] === 0) {
            return;
        }

        $this->comment(sprintf(
            'Barrido: %d intermedios huérfanos borrados, %.1f MiB liberados.',
            $swept['entries'],
            $swept['bytes'] / 1048576,
        ));
    }

    private function renderCoverage(CoverageReport $report): void
    {
        foreach ($report->warnings as $warning) {
            $this->warn($warning);
        }

        if ($report->passed) {
            $this->info('Auditoría de cobertura: sin bloqueantes.');

            return;
        }

        $this->error('Auditoría de cobertura: hay bloqueantes.');

        foreach ($report->blocking as $blocking) {
            $this->error('  · '.$blocking);
        }
    }

    /**
     * @return array{scenes?: list<array<string, mixed>>, sentences?: list<array<string, mixed>>}
     */
    private function readTimings(string $path): array
    {
        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($this->files->get($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        return $decoded;
    }

    /**
     * @param  list<array{role: string, startAt: float, endAt: ?float, gainDb: float, duckable: bool, file: string}>  $tracks
     */
    private function renderTracks(array $tracks): void
    {
        $rows = [];

        foreach ($tracks as $track) {
            $end = $track['endAt'];
            $rows[] = [
                $track['role'],
                sprintf('%.2f', $track['startAt']),
                $end === null ? '—' : sprintf('%.2f', $end),
                sprintf('%+.1f dB', $track['gainDb']),
                $track['duckable'] ? 'sí' : 'no',
                $this->truncate(basename($track['file']), 40),
            ];
        }

        $this->table(['Pista', 'Inicio', 'Fin', 'Nivel', 'Duck', 'Fichero'], $rows);
    }

    /**
     * @param  array{lufs: float, truePeak: float, lra: float}|null  $measurement
     */
    private function renderMeasurement(?array $measurement): void
    {
        if ($measurement === null) {
            return;
        }

        $this->newLine();
        $this->line($this->metricLine(
            sprintf('LUFS integrado: %.1f', $measurement['lufs']),
            abs($measurement['lufs'] - $this->targetLufs) > self::LUFS_TOLERANCE,
        ));
        $this->line($this->metricLine(
            sprintf('True peak: %.1f dBTP', $measurement['truePeak']),
            $measurement['truePeak'] > $this->targetTruePeak,
        ));
        $this->line(sprintf('Rango dinámico: %.1f LU', $measurement['lra']));
    }

    private function metricLine(string $text, bool $alert): string
    {
        return $alert ? '<fg=red>'.$text.'</>' : $text;
    }

    /**
     * @param  list<array{file: string, author: string, sourceUrl: string, license: string}>  $credits
     */
    private function renderCredits(array $credits): void
    {
        $this->newLine();

        if ($credits === []) {
            $this->comment('No hay créditos de atribución en los ficheros usados.');

            return;
        }

        $this->info('Créditos (solo ficheros usados en esta historia):');

        foreach ($this->attribution->lines($credits) as $line) {
            $this->line($line);
        }
    }

    /**
     * @param  list<array{file: string, author: string, sourceUrl: string, license: string}>  $credits
     */
    private function writeCredits(string $slug, array $credits): string
    {
        $path = $this->outputDirectory.DIRECTORY_SEPARATOR.$slug.DIRECTORY_SEPARATOR.'credits.txt';
        $this->attribution->write($path, $this->attribution->storyDocument($slug, $credits));

        return $path;
    }

    private function resolveStoryFile(string $file): ?string
    {
        foreach ([$file, $this->outputDirectory.DIRECTORY_SEPARATOR.basename($file)] as $candidate) {
            if ($this->files->isFile($candidate)) {
                return realpath($candidate) ?: $candidate;
            }
        }

        $this->error("No se encontró el guion '{$file}'.");

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readStoryPayload(string $path): ?array
    {
        try {
            $decoded = json_decode($this->files->get($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->error('El guion no es un JSON válido.');

            return null;
        }

        if (! is_array($decoded) || ! isset($decoded['scenes']) || ! is_array($decoded['scenes'])) {
            $this->error('El JSON no contiene un guion de historia.');

            return null;
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private function truncate(string $value, int $limit): string
    {
        if (mb_strlen($value) <= $limit) {
            return $value;
        }

        return mb_substr($value, 0, $limit - 1).'…';
    }
}
