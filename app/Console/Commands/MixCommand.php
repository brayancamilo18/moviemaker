<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\DataObjects\CoverageReport;
use App\Enums\StoryMode;
use App\Enums\StoryStatus;
use App\Models\Story;
use App\Services\Audio\AttributionWriter;
use App\Services\Pipeline\SoundStep;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Filesystem\Filesystem;
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
        private SoundStep $sounds,
        private AttributionWriter $attribution,
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
        $story = $this->resolveStory((string) $this->argument('file'));

        if (! $story instanceof Story) {
            return self::FAILURE;
        }

        try {
            $result = $this->sounds->run($story, null, [
                'mix' => true,
                'no_music' => (bool) $this->option('no-music'),
                'no_sfx' => (bool) $this->option('no-sfx'),
                'no_ambience' => (bool) $this->option('no-ambience'),
                'dry_run' => (bool) $this->option('dry-run'),
            ]);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->renderSweep($result['swept'] ?? null);

        if (isset($result['coverage']) && $result['coverage'] instanceof CoverageReport) {
            $this->renderCoverage($result['coverage']);
        }

        if (($result['ok'] ?? true) === false) {
            $this->error((string) ($result['error'] ?? 'La mezcla falló.'));

            return self::FAILURE;
        }

        $this->renderTracks($result['tracks']);
        $this->renderSkippedSfx($result['sfx_skipped']);
        $this->line(sprintf(
            'Duración del máster: %.3f s (última frase %.3f s + cola %.1f s).',
            $result['master_seconds'],
            $result['last_transcribed_phrase_end'],
            $result['tail_seconds'],
        ));

        if ((bool) ($result['dry_run'] ?? false)) {
            $this->comment('Simulación: no se generó audio.');

            return self::SUCCESS;
        }

        $this->renderMeasurement($result['measurement']);
        $this->renderCredits($result['credits']);
        $this->info('Mezcla: '.$result['wav']);
        $this->line('Escucha: '.$result['mp3']);
        $this->line('Créditos: '.$result['credits_path']);

        return self::SUCCESS;
    }

    /**
     * @param  array{entries?: int, bytes?: int}|null  $swept
     */
    private function renderSweep(?array $swept): void
    {
        if ($swept === null || ($swept['entries'] ?? 0) === 0) {
            return;
        }

        $this->comment(sprintf(
            'Barrido: %d intermedios huérfanos borrados, %.1f MiB liberados.',
            $swept['entries'],
            ($swept['bytes'] ?? 0) / 1048576,
        ));
    }

    /**
     * @param  list<array<string, mixed>>  $skipped
     */
    private function renderSkippedSfx(array $skipped): void
    {
        if ($skipped === []) {
            return;
        }

        $this->warn(sprintf('%d efecto(s) sin colocar:', count($skipped)));

        foreach ($skipped as $entry) {
            $this->line(sprintf(
                '  · plano %s  %s  (%s)',
                (string) ($entry['shot'] ?? '?'),
                (string) ($entry['query'] ?? ''),
                match ((string) ($entry['reason'] ?? '')) {
                    'anchor_missing' => 'sin palabra ancla; vuelve a dirigir con story:sounds --refresh',
                    'anchor_not_found' => sprintf(
                        'la palabra «%s» no está alineada en ese plano',
                        (string) ($entry['anchorWord'] ?? ''),
                    ),
                    'shot_not_found' => 'el plano no existe en shots.json',
                    default => (string) ($entry['reason'] ?? 'motivo desconocido'),
                },
            ));
        }
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

    private function resolveStory(string $file): ?Story
    {
        $storyFile = $this->resolveStoryFile($file);

        if ($storyFile === null) {
            return null;
        }

        $payload = $this->readStoryPayload($storyFile);

        if ($payload === null) {
            return null;
        }

        return new Story([
            'slug' => pathinfo($storyFile, PATHINFO_FILENAME),
            'title' => is_string($payload['title'] ?? null) ? $payload['title'] : pathinfo($storyFile, PATHINFO_FILENAME),
            'mode' => StoryMode::Original,
            'status' => StoryStatus::Draft,
        ]);
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
