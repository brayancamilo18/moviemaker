<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Contracts\JsonLlm;
use App\DataObjects\CoverageReport;
use App\DataObjects\ResolvedSound;
use App\DataObjects\Story;
use App\Services\Audio\CoverageAuditor;
use App\Services\Audio\StorySoundManifest;
use App\Services\Image\ShotPlanner;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use InvalidArgumentException;
use JsonException;
use Throwable;

final class SoundsCommand extends Command
{
    protected $signature = 'story:sounds
        {file : JSON del guion}
        {--refresh : Vuelve a resolver todas las señales}
        {--refresh-cue= : Fuerza la resolución de una señal concreta}
        {--audit : Solo audita lo ya resuelto, sin tocar nada}';

    protected $description = 'Resuelve las señales de audio de una historia y escribe sounds.json';

    private readonly string $outputDirectory;

    public function __construct(
        private StorySoundManifest $manifest,
        private CoverageAuditor $auditor,
        private JsonLlm $llm,
        private Filesystem $files,
        Repository $config,
    ) {
        parent::__construct();

        $this->outputDirectory = storage_path('app/'.$config->get('stories.output_path'));
    }

    public function handle(): int
    {
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
        $timings = $this->readTimings($slug);
        $refresh = (bool) $this->option('refresh');
        $refreshCue = trim((string) $this->option('refresh-cue'));
        $auditOnly = (bool) $this->option('audit');

        if (! $auditOnly && ! $this->assertDirectedShots($slug)) {
            return self::FAILURE;
        }

        try {
            if ($auditOnly) {
                if (! $this->manifest->exists($slug)) {
                    $this->error('No hay sounds.json. Ejecuta story:sounds sin --audit primero.');

                    return self::FAILURE;
                }

                $manifest = $this->manifest->load($slug);
            } else {
                $manifest = $this->manifest->sync(
                    $slug,
                    $story,
                    $timings,
                    $refresh,
                    $refreshCue !== '' ? $refreshCue : null,
                );
            }
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $notice = $this->llm->fallbackNotice();

        if ($notice !== null) {
            $this->warn($notice);
        }

        $this->renderTable($manifest['cues']);
        $this->renderSummary($manifest['cues']);
        $this->line('sounds.json: '.$this->manifest->pathFor($slug));

        if ($auditOnly) {
            $report = $this->auditor->audit(
                $story,
                $manifest['cues'],
                $this->outputDirectory.DIRECTORY_SEPARATOR.$slug.DIRECTORY_SEPARATOR.'narration.wav',
            );
            $this->renderCoverage($report);

            return $report->passed ? self::SUCCESS : self::FAILURE;
        }

        $this->comment('Si el algoritmo elige mal, edita la ruta en sounds.json y vuelve a mezclar.');

        return self::SUCCESS;
    }

    /**
     * @param  list<array<string, mixed>>  $cues
     */
    private function renderTable(array $cues): void
    {
        $rows = [];

        foreach ($cues as $cue) {
            $source = (string) ($cue['source'] ?? '');
            $rows[] = [
                (string) ($cue['id'] ?? ''),
                $this->truncate((string) ($cue['query'] ?? ''), 36),
                $this->truncate(basename((string) ($cue['file'] ?? '')), 32),
                $this->colorSource($source),
                $this->ladderLabel($cue['ladderLevel'] ?? null),
                sprintf('%.2f', (float) ($cue['score'] ?? 0)),
                sprintf('%+.1f dB', (float) ($cue['gainDb'] ?? 0)),
            ];
        }

        $this->table(['Señal', 'Query', 'Fichero', 'Origen', 'Escalera', 'Puntuación', 'Nivel'], $rows);
    }

    /**
     * @param  list<array<string, mixed>>  $cues
     */
    private function renderSummary(array $cues): void
    {
        $sources = $this->auditor->sourceBreakdown($cues);
        $ladder = $this->auditor->ladderBreakdown($cues);

        $this->newLine();
        $this->line(sprintf(
            'Reparto por origen: caché=%d  descarga=%d  respaldo=%d  synth=%d',
            $sources[ResolvedSound::SOURCE_CACHE],
            $sources[ResolvedSound::SOURCE_DOWNLOAD],
            $sources[ResolvedSound::SOURCE_FALLBACK],
            $sources[ResolvedSound::SOURCE_SYNTH],
        ));
        $this->line(sprintf(
            'Reparto por escalera: 1=%d  2=%d  3=%d  4=%d',
            $ladder[1],
            $ladder[2],
            $ladder[3],
            $ladder[4],
        ));
    }

    private function renderCoverage(CoverageReport $report): void
    {
        $this->newLine();

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

    private function ladderLabel(mixed $level): string
    {
        return is_int($level) && $level > 0 ? (string) $level : '—';
    }

    private function colorSource(string $source): string
    {
        return match ($source) {
            ResolvedSound::SOURCE_FALLBACK => '<fg=yellow>respaldo</>',
            ResolvedSound::SOURCE_SYNTH => '<fg=red>synth</>',
            ResolvedSound::SOURCE_CACHE => 'caché',
            ResolvedSound::SOURCE_DOWNLOAD => 'descarga',
            default => $source,
        };
    }

    /**
     * @return array{scenes?: list<array<string, mixed>>, sentences?: list<array<string, mixed>>}
     */
    private function readTimings(string $slug): array
    {
        $path = $this->outputDirectory.DIRECTORY_SEPARATOR.$slug.DIRECTORY_SEPARATOR.'timings.json';

        if (! $this->files->isFile($path)) {
            return [];
        }

        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($this->files->get($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        return $decoded;
    }

    private function assertDirectedShots(string $slug): bool
    {
        $path = $this->outputDirectory.DIRECTORY_SEPARATOR.$slug.DIRECTORY_SEPARATOR.'shots.json';

        if (! $this->files->isFile($path)) {
            $this->error('No hay shots.json. Ejecuta story:images primero.');

            return false;
        }

        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($this->files->get($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->error('shots.json no es un JSON válido.');

            return false;
        }

        if (! isset($decoded['shots']) || ! is_array($decoded['shots']) || $decoded['shots'] === []) {
            $this->error('shots.json no contiene planos.');

            return false;
        }

        $version = array_key_exists('plannerVersion', $decoded) ? (int) $decoded['plannerVersion'] : 0;

        if ($version < ShotPlanner::VERSION) {
            $seen = array_key_exists('plannerVersion', $decoded) ? (string) $version : 'ausente';
            $this->error(sprintf(
                'shots.json tiene plannerVersion %s; hace falta %d. Regenera con story:images.',
                $seen,
                ShotPlanner::VERSION,
            ));

            return false;
        }

        return true;
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
