<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\StoryMode;
use App\Enums\StoryStatus;
use App\Models\Story;
use App\Services\Pipeline\RenderStep;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use JsonException;
use Throwable;

final class RenderVideoCommand extends Command
{
    private const STEPS = ['clips', 'scenes', 'assemble', 'encode'];

    protected $signature = 'story:render
        {file : JSON del guion}
        {--from= : Reinicia desde clips, scenes, assemble o encode}
        {--keep-intermediates : Conserva clips, escenas y vídeo mudo}
        {--no-grade : Codifica sin corrección de color, para comparar}
        {--dry-run : Imprime el plan y lo compara con el audio, sin renderizar}';

    protected $description = 'Monta el vídeo de una historia a partir de shots.json y el mix de audio';

    private readonly string $outputDirectory;

    public function __construct(
        private RenderStep $render,
        private Filesystem $files,
        Repository $config,
    ) {
        parent::__construct();

        $this->outputDirectory = storage_path('app/'.$config->get('stories.output_path'));
    }

    public function handle(): int
    {
        $from = $this->resolveFrom();

        if ($from === false) {
            return self::FAILURE;
        }

        $story = $this->resolveStory((string) $this->argument('file'));

        if (! $story instanceof Story) {
            return self::FAILURE;
        }

        try {
            $result = $this->render->run($story, $this->progressCallback(), [
                'from' => $from,
                'keep_intermediates' => (bool) $this->option('keep-intermediates'),
                'no_grade' => (bool) $this->option('no-grade'),
                'dry_run' => (bool) $this->option('dry-run'),
            ]);
        } catch (Throwable $exception) {
            $this->newLine();
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->renderSweep($result['swept'] ?? null);

        if (isset($result['validation']) && ! $this->renderValidation($result['validation'])) {
            return self::FAILURE;
        }

        if (($result['ok'] ?? true) === false) {
            if (($result['blank_line'] ?? false) === true) {
                $this->newLine();
            }

            $this->error((string) ($result['error'] ?? 'El render falló.'));

            foreach ($result['hints'] ?? [] as $hint) {
                $this->line((string) $hint);
            }

            return self::FAILURE;
        }

        if ((bool) ($result['dry_run'] ?? false)) {
            $this->printPlan($result);

            return self::SUCCESS;
        }

        if (($result['skipped_clips'] ?? 0) > 0) {
            $this->comment('  Omitidos '.$result['skipped_clips'].' clips ya válidos.');
        }

        if (($result['skipped_scenes'] ?? 0) > 0) {
            $this->comment('  Omitidas '.$result['skipped_scenes'].' escenas ya válidas.');
        }

        if (($result['skipped_assemble'] ?? false) === true) {
            $this->comment('  Vídeo mudo ya válido, se omite.');
        }

        if (($result['skipped_encode'] ?? false) === true) {
            $this->comment('  Vídeo final ya válido, se omite.');
        }

        if (is_string($result['subtitle_warning'] ?? null)) {
            $this->warn((string) $result['subtitle_warning']);
        } elseif (is_string($result['subtitles_path'] ?? null)) {
            $this->line('Subtítulos: '.$result['subtitles_path']);
        }

        if (! (bool) ($result['kept_intermediates'] ?? false)) {
            $this->comment('Intermedios borrados.');
        }

        $this->printSummary(
            (float) $result['video_seconds'],
            (float) $result['audio_duration'],
            (int) $result['bytes'],
            (float) $result['elapsed'],
            (string) $result['video_path'],
            (float) $result['outro_seconds'],
            (float) $result['expected_duration'],
            (float) $result['sync_tolerance'],
        );

        return self::SUCCESS;
    }

    /**
     * @return (callable(string, int, int): void)
     */
    private function progressCallback(): callable
    {
        $bar = null;
        $currentTotal = 0;

        return function (string $label, int $done, int $total) use (&$bar, &$currentTotal): void {
            if ($bar === null || $total !== $currentTotal) {
                if ($bar !== null && $currentTotal > 0) {
                    $bar->finish();
                    $this->newLine();
                }

                $currentTotal = $total;
                $bar = $this->output->createProgressBar(max(1, $total));
                $bar->setFormat('%current%/%max% [%bar%] %percent:3s%%  %message%');
                $bar->setMessage($label);
                $bar->start();

                if ($label === 'clips') {
                    $this->info('Clips');
                } elseif ($label === 'escenas') {
                    $this->info('Escenas');
                } elseif ($label === 'concat + outro') {
                    $this->info('Ensamblado');
                } elseif (str_contains($label, 'encode')) {
                    $this->info('Codificación final');
                }
            }

            if ($label !== '') {
                $bar->setMessage($label);
            }

            if ($done > 0) {
                $bar->setProgress($done);
            }

            if ($done >= $total && $total > 0) {
                $bar->finish();
                $this->newLine();
                $bar = null;
            }
        };
    }

    /**
     * @return 'clips'|'scenes'|'assemble'|'encode'|null|false
     */
    private function resolveFrom(): string|false|null
    {
        $from = $this->option('from');

        if ($from === null || $from === '') {
            return null;
        }

        $from = trim((string) $from);

        if (! in_array($from, self::STEPS, true)) {
            $this->error('--from debe ser clips, scenes, assemble o encode.');

            return false;
        }

        return $from;
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
     * @param  array{passed: bool, checks: list<array{id: string, label: string, status: string, detail: string, blocking: bool}>}  $report
     */
    private function renderValidation(array $report): bool
    {
        $rows = [];

        foreach ($report['checks'] as $check) {
            $status = match ($check['status']) {
                'fail' => $check['blocking'] ? '<fg=red>FALLO</>' : 'FALLO',
                'warn' => '<fg=yellow>AVISO</>',
                default => 'OK',
            };
            $rows[] = [$check['label'], $status, $check['detail']];
        }

        $this->table(['Comprobación', 'Estado', 'Detalle'], $rows);

        if ($report['passed']) {
            return true;
        }

        $this->error('Validación: hay bloqueantes. No se renderiza.');

        foreach ($report['checks'] as $check) {
            if ($check['blocking'] && $check['status'] === 'fail') {
                $this->error('FALLO  '.$check['detail']);
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function printPlan(array $result): void
    {
        $this->warn('Modo simulación: no se renderizará.');
        $rows = [];

        foreach ($result['shot_rows'] as $shot) {
            $rows[] = [
                '#'.$shot['order'],
                $shot['sceneOrder'],
                $shot['motion'],
                sprintf('%.3f', $shot['real']),
                sprintf('%.3f', $shot['clip']),
                sprintf('%+.3f', $shot['extra']),
                $shot['join'],
            ];
        }

        $this->table(['Plano', 'Escena', 'Motion', 'Real s', 'Clip s', 'Extra s', 'Empalme'], $rows);

        $sceneRows = [];

        foreach ($result['scene_rows'] as $scene) {
            $sceneRows[] = [
                $scene['order'],
                $scene['shots'],
                sprintf('%.3f', $scene['duration']),
                implode(', ', $scene['joins']) ?: '—',
            ];
        }

        $this->table(['Escena', 'Planos', 'Duración s', 'Empalmes'], $sceneRows);

        $audioDuration = (float) $result['audio_duration'];
        $outroSeconds = (float) $result['outro_seconds'];
        $tailSeconds = (float) $result['tail_seconds'];
        $plan = $result['plan'];
        $body = round($audioDuration - $tailSeconds, 3);
        $delta = $plan['body'] - $body;
        $master = round($body + $outroSeconds, 3);
        $this->newLine();
        $this->line('Planos: '.count($result['shots']).'  Escenas: '.count($result['grouped']));
        $this->line(sprintf('Cuerpo previsto: %s (%.3f s)', $this->formatClock($plan['body']), $plan['body']));
        $this->line(sprintf('Outro: %.1f s  Vídeo mudo previsto: %s', $outroSeconds, $this->formatClock($plan['silent'])));
        $this->line(sprintf('Audio: %s (%.3f s)', $this->formatClock($audioDuration), $audioDuration));
        $this->line(sprintf('Habla: %s (%.3f s, el resto es la cola de %.1f s)', $this->formatClock($body), $body, $tailSeconds));
        $this->line(sprintf('Máster previsto: %s (%.3f s)', $this->formatClock($master), $master));
        $this->line($this->deltaLine($delta, 'cuerpo−habla', (float) $result['sync_tolerance']));
        $this->comment(sprintf(
            'El outro arranca al acabar el habla, así que la cola de %.1f s de la mezcla suena sobre él y al audio solo se le añaden %.1f s de silencio.',
            $tailSeconds,
            $outroSeconds - $tailSeconds,
        ));
    }

    private function printSummary(
        float $videoDuration,
        float $audioDuration,
        int $bytes,
        float $elapsed,
        string $videoPath,
        float $outroSeconds,
        float $expected,
        float $syncTolerance,
    ): void {
        $delta = $videoDuration - $expected;

        $this->newLine();
        $this->info('Render listo.');
        $this->line('  Vídeo: '.$this->formatClock($videoDuration).sprintf(' (%.3f s)', $videoDuration));
        $this->line('  Audio: '.$this->formatClock($audioDuration).sprintf(' (%.3f s)', $audioDuration));
        $this->line(sprintf('  Outro: %.1f s  Esperado: %s (%.3f s)', $outroSeconds, $this->formatClock($expected), $expected));
        $this->line('  '.$this->deltaLine($delta, 'vídeo−(habla+outro)', $syncTolerance));
        $this->line(sprintf('  Tamaño: %.1f MiB', $bytes / 1048576));
        $this->line(sprintf('  Tiempo: %.1f s', $elapsed));
        $this->line(sprintf('  Factor tiempo real: %.2fx', $elapsed / max($videoDuration, 0.001)));
        $this->line('  Fichero: '.$videoPath);
    }

    private function deltaLine(float $delta, string $label, float $syncTolerance): string
    {
        $text = sprintf('Desfase %s: %+.3f s', $label, $delta);

        if (abs($delta) > $syncTolerance) {
            return '<fg=red>'.$text.'</>';
        }

        return $text;
    }

    private function formatClock(float $seconds): string
    {
        $total = (int) round(max(0.0, $seconds));
        $hours = intdiv($total, 3600);
        $minutes = intdiv($total % 3600, 60);
        $secs = $total % 60;

        if ($hours > 0) {
            return sprintf('%d:%02d:%02d', $hours, $minutes, $secs);
        }

        return sprintf('%02d:%02d', $minutes, $secs);
    }

    private function resolveStory(string $file): ?Story
    {
        $storyFile = $this->resolveStoryFile($file);

        if ($storyFile === null) {
            return null;
        }

        $payload = $this->readJson($storyFile, 'El guion no es un JSON válido.');

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
    private function readJson(string $path, string $invalidMessage): ?array
    {
        try {
            $decoded = json_decode($this->files->get($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->error($invalidMessage);

            return null;
        }

        if (! is_array($decoded)) {
            $this->error($invalidMessage);

            return null;
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
