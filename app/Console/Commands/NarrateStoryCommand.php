<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\StoryMode;
use App\Enums\StoryStatus;
use App\Models\Story;
use App\Services\Pipeline\NarrationStep;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use JsonException;
use Throwable;

final class NarrateStoryCommand extends Command
{
    protected $signature = 'story:narrate
        {file : Ruta al JSON del guion generado en la Fase 1}
        {--voice= : Voz de Kokoro}
        {--speed= : Velocidad de habla}
        {--no-cache : Ignora la caché de WAV}
        {--skip-timings : No genera timings.json}
        {--timings-only : Alinea un máster existente y escribe timings.json}';

    protected $description = 'Sintetiza la narración de un guion JSON y escribe el máster de audio';

    private readonly string $outputDirectory;

    public function __construct(
        private NarrationStep $narration,
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
            $result = $this->narration->run($story, $this->progressCallback(), [
                'voice' => $this->option('voice'),
                'speed' => $this->option('speed'),
                'skip_cache' => (bool) $this->option('no-cache'),
                'skip_timings' => (bool) $this->option('skip-timings'),
                'timings_only' => (bool) $this->option('timings-only'),
            ]);
        } catch (Throwable $exception) {
            $this->newLine();
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if (($result['ok'] ?? true) === false && ! isset($result['alignment'])) {
            if (($result['blank_line'] ?? false) === true) {
                $this->newLine();
            }

            $this->error((string) ($result['error'] ?? 'La narración falló.'));

            foreach ($result['hints'] ?? [] as $hint) {
                $this->line((string) $hint);
            }

            return self::FAILURE;
        }

        if ((bool) ($result['timings_only'] ?? false)) {
            $this->info('Alineando el máster existente con whisper.cpp…');
            $this->info('timings.json listo: '.(string) $result['timings_path']);

            return $this->renderAlignmentReport(
                $result['alignment'],
                $result['alignment_problems'] ?? [],
            );
        }

        $this->renderSummary(
            (float) $result['narration_seconds'],
            (int) $result['sentence_count'],
            (int) $result['cache_hits'],
            (float) $result['elapsed'],
        );

        if ($result['alignment'] === null) {
            return self::SUCCESS;
        }

        return $this->renderAlignmentReport(
            $result['alignment'],
            $result['alignment_problems'] ?? [],
        );
    }

    /**
     * @return (callable(string, int, int, ?string): void)
     */
    private function progressCallback(): callable
    {
        $bar = null;

        return function (string $label, int $done, int $total, ?string $stage = null) use (&$bar): void {
            if ($bar === null) {
                $bar = $this->output->createProgressBar(max(1, $total));
                $bar->setFormat('%current%/%max% [%bar%] %percent:3s%% %message%');
                $bar->setMessage('');
                $bar->start();
            }

            if ($label !== '') {
                $bar->setMessage($this->truncate($label));
            }

            if ($done > 0) {
                $bar->setProgress($done);
            }

            if ($done >= $total && $total > 0) {
                $bar->finish();
                $this->newLine();
            }
        };
    }

    /**
     * @param  array{sentences: int, textAligned: int, sequential: int, textRatio: float, speechEnd: float, narrationEnd: float, uncovered: float}  $report
     * @param  list<string>  $problems
     */
    private function renderAlignmentReport(array $report, array $problems): int
    {
        $this->newLine();
        $this->line(sprintf(
            '  Alineación: %d/%d frases por texto (%.0f%%), %d por posición',
            $report['textAligned'],
            $report['sentences'],
            $report['textRatio'] * 100,
            $report['sequential'],
        ));
        $this->line(sprintf(
            '  Habla hasta %.3f s de %.3f s (%.3f s sin cubrir)',
            $report['speechEnd'],
            $report['narrationEnd'],
            $report['uncovered'],
        ));

        if ($problems === []) {
            return self::SUCCESS;
        }

        $this->newLine();

        foreach ($problems as $problem) {
            $this->error($problem);
        }

        $this->line('El máster de audio es válido y se conserva; lo sospechoso son los timings.');
        $this->line('Vuelve a alinear con: story:narrate <guion> --timings-only');

        return self::FAILURE;
    }

    private function renderSummary(float $duration, int $sentenceCount, int $cacheHits, float $elapsed): void
    {
        $totalSeconds = (int) round($duration);
        $rtf = $duration > 0 ? $elapsed / $duration : 0.0;

        $this->newLine();
        $this->info('Narración lista.');
        $this->line(sprintf('  Duración: %02d:%02d', intdiv($totalSeconds, 60), $totalSeconds % 60));
        $this->line('  Frases: '.$sentenceCount);
        $this->line(sprintf('  Caché: %d/%d', $cacheHits, $sentenceCount));
        $this->line(sprintf('  Cómputo: %.1f s', $elapsed));
        $this->line(sprintf('  Factor tiempo real: %.2fx', $rtf));
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
        $candidates = [
            $file,
            $this->outputDirectory.DIRECTORY_SEPARATOR.basename($file),
        ];

        foreach ($candidates as $candidate) {
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

    private function truncate(string $text, int $width = 60): string
    {
        $text = preg_replace('/\s+/u', ' ', trim($text)) ?? trim($text);

        if (mb_strlen($text) <= $width) {
            return $text;
        }

        return mb_substr($text, 0, $width - 1).'…';
    }
}
