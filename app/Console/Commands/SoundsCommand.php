<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\DataObjects\CoverageReport;
use App\DataObjects\ResolvedSound;
use App\Enums\StoryMode;
use App\Enums\StoryStatus;
use App\Models\Story;
use App\Services\Audio\CoverageAuditor;
use App\Services\Pipeline\SoundStep;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Filesystem\Filesystem;
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
        private SoundStep $sounds,
        private CoverageAuditor $auditor,
        private Filesystem $files,
        Repository $config,
    ) {
        parent::__construct();

        $this->outputDirectory = storage_path('app/'.$config->get('stories.output_path'));
    }

    public function handle(): int
    {
        $story = $this->resolveStory((string) $this->argument('file'));

        if (! $story instanceof Story) {
            return self::FAILURE;
        }

        try {
            $result = $this->sounds->run($story, null, [
                'refresh' => (bool) $this->option('refresh'),
                'refresh_cue' => trim((string) $this->option('refresh-cue')),
                'audit' => (bool) $this->option('audit'),
            ]);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if (($result['ok'] ?? true) === false && ! isset($result['cues'])) {
            $this->error((string) ($result['error'] ?? 'La resolución de sonido falló.'));

            return self::FAILURE;
        }

        $notice = $result['fallback_notice'] ?? null;

        if (is_string($notice) && $notice !== '') {
            $this->warn($notice);
        }

        $this->renderTable($result['cues']);
        $this->renderSummary($result['cues']);
        $this->line('sounds.json: '.(string) $result['path']);

        if ((bool) ($result['audit'] ?? false)) {
            $report = $result['coverage'];
            $this->renderCoverage($report);

            return $report instanceof CoverageReport && $report->passed ? self::SUCCESS : self::FAILURE;
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

    private function renderCoverage(mixed $report): void
    {
        if (! $report instanceof CoverageReport) {
            return;
        }

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
