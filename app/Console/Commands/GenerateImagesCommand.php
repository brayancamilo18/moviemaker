<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\DataObjects\PlannedShot;
use App\DataObjects\Shot;
use App\Enums\StoryMode;
use App\Enums\StoryStatus;
use App\Models\Story;
use App\Services\Pipeline\ImagesStep;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use JsonException;
use Throwable;

final class GenerateImagesCommand extends Command
{
    protected $signature = 'story:images
        {file : Ruta al JSON del guion generado en la Fase 1}
        {--only= : Planos a regenerar: 12, 40-45, 3,7,19}
        {--force : Ignora la caché y suma un offset al seed}
        {--redirect : Vuelve a dirigir los planos aunque el plan no haya cambiado}
        {--dry-run : Planifica e imprime los prompts sin generar imágenes}';

    protected $description = 'Planifica planos y genera las imágenes de un guion a partir de timings.json';

    private readonly string $outputDirectory;

    private readonly float $rateLimitSeconds;

    public function __construct(
        private ImagesStep $images,
        private Filesystem $files,
        Repository $config,
    ) {
        parent::__construct();

        $this->outputDirectory = storage_path('app/'.$config->get('stories.output_path'));
        $this->rateLimitSeconds = (float) $config->get('stories.images.rate_limit_seconds');
    }

    public function handle(): int
    {
        $story = $this->resolveStory((string) $this->argument('file'));

        if (! $story instanceof Story) {
            return self::FAILURE;
        }

        $only = $this->parseOnly((string) $this->option('only'));

        if ($only === false) {
            return self::FAILURE;
        }

        try {
            $result = $this->images->run($story, $this->progressCallback(), [
                'only' => $only,
                'force' => (bool) $this->option('force'),
                'redirect' => (bool) $this->option('redirect'),
                'dry_run' => (bool) $this->option('dry-run'),
            ]);
        } catch (Throwable $exception) {
            $this->newLine();
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        foreach ($result['warnings'] ?? [] as $warning) {
            $this->warn((string) $warning);
        }

        if (($result['ok'] ?? true) === false) {
            if (($result['partial'] ?? false) === true) {
                $this->newLine();
            }

            $this->error((string) ($result['error'] ?? 'La generación de imágenes falló.'));

            foreach ($result['hints'] ?? [] as $hint) {
                $this->line((string) $hint);
            }

            if (($result['partial'] ?? false) === true) {
                $this->line('Los planos ya generados quedan guardados en '.(string) $result['shots_path'].'.');
            }

            return self::FAILURE;
        }

        if ((bool) ($result['bible_generated'] ?? false)) {
            $this->info('No hay biblia visual. Generándola…');
            $this->line('Biblia visual escrita con '.(string) $result['llm_name'].'.');
            $this->warnAboutFallback($result['fallback_notice'] ?? null);
        }

        if ((bool) ($result['direction_reused'] ?? false)) {
            $this->line('El plan no ha cambiado: se reutiliza la dirección de shots.json (--redirect para volver a dirigir).');
        } else {
            $this->line('Planos dirigidos con '.(string) $result['llm_name'].'.');
            $this->warnAboutFallback($result['fallback_notice'] ?? null);
        }

        if ((bool) ($result['dry_run'] ?? false)) {
            $this->warn('Modo simulación: no se generarán imágenes.');
            $this->printPrompts(
                $result['selected'],
                $result['shots'],
                $result['prompts'],
                (string) $result['slug'],
            );

            return self::SUCCESS;
        }

        if (! (bool) ($result['provider_available'] ?? true)) {
            $this->warn('El proveedor de imágenes no responde. Los planos nuevos pueden acabar en marcador.');
        }

        $this->printEstimate(count($result['selected']));
        $this->renderSummary($result['stats'], $result['rows'], (string) $result['shots_path']);

        return self::SUCCESS;
    }

    /**
     * @return (callable(string, int, int): void)
     */
    private function progressCallback(): callable
    {
        $bar = null;

        return function (string $label, int $done, int $total) use (&$bar): void {
            if ($bar === null) {
                $bar = $this->output->createProgressBar(max(1, $total));
                $bar->setFormat('%current%/%max% [%bar%] %percent:3s%% restante %remaining%  %message%');
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

    private function warnAboutFallback(mixed $notice): void
    {
        if (is_string($notice) && $notice !== '') {
            $this->warn($notice);
        }
    }

    /**
     * @param  list<Shot>  $selected
     * @param  list<Shot>  $shots
     * @param  list<string>  $prompts
     */
    private function printPrompts(array $selected, array $shots, array $prompts, string $slug): void
    {
        $byOrder = [];

        foreach ($shots as $index => $shot) {
            $byOrder[$shot->order] = $prompts[$index] ?? '';
        }

        $this->newLine();

        foreach ($selected as $shot) {
            $prompt = $byOrder[$shot->order] ?? '';
            $seed = $this->baseSeed($slug, $shot->order);
            $duration = $shot->end - $shot->start;

            $this->line(sprintf(
                '<info>#%d</info>  escena %d  %.2f–%.2fs (%.2fs)  %s  seed %d',
                $shot->order,
                $shot->sceneOrder,
                $shot->start,
                $shot->end,
                $duration,
                $shot->framing,
                $seed,
            ));
            $this->line('  '.$prompt);
            $this->newLine();
        }
    }

    private function baseSeed(string $slug, int $order): int
    {
        $crc = (int) sprintf('%u', crc32($slug.':'.$order));
        $seed = $crc & 0x7FFFFFFF;

        return $seed === 0 ? 1 : $seed;
    }

    /**
     * @return list<int>|null|false
     */
    private function parseOnly(string $raw): array|null|false
    {
        $raw = trim($raw);

        if ($raw === '') {
            return null;
        }

        $selected = [];

        foreach (preg_split('/\s*,\s*/', $raw) ?: [] as $part) {
            if ($part === '') {
                continue;
            }

            if (preg_match('/^(\d+)\s*-\s*(\d+)$/', $part, $matches) === 1) {
                $from = (int) $matches[1];
                $to = (int) $matches[2];

                if ($from > $to) {
                    [$from, $to] = [$to, $from];
                }

                for ($order = $from; $order <= $to; $order++) {
                    $selected[$order] = $order;
                }

                continue;
            }

            if (preg_match('/^\d+$/', $part) === 1) {
                $order = (int) $part;
                $selected[$order] = $order;

                continue;
            }

            $this->error("No se pudo interpretar --only '{$raw}'. Usa números o rangos: 12,40-45,3,7,19.");

            return false;
        }

        if ($selected === []) {
            $this->error("No se pudo interpretar --only '{$raw}'. Usa números o rangos: 12,40-45,3,7,19.");

            return false;
        }

        ksort($selected);

        return array_values($selected);
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

        if (! isset($payload['scenes']) || ! is_array($payload['scenes'])) {
            $this->error('El JSON no contiene un guion de historia.');

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

    private function printEstimate(int $count): void
    {
        $seconds = $count * $this->rateLimitSeconds;
        $estimate = $seconds < 60
            ? sprintf('unos %.0f s', $seconds)
            : sprintf('unos %.0f min', ceil($seconds / 60));

        $this->line(sprintf(
            '%d planos a generar. Con el rate limit de %.0f s, %s si no hay caché.',
            $count,
            $this->rateLimitSeconds,
            $estimate,
        ));
    }

    /**
     * @param  array{count: int, meanDuration: float, minDuration: float, maxDuration: float, framing: array<string, int>, subject: array<string, int>, threatStage: array<string, int>}  $stats
     * @param  list<PlannedShot>  $rows
     */
    private function renderSummary(array $stats, array $rows, string $shotsPath): void
    {
        $this->newLine();
        $this->info('Imágenes listas.');
        $this->line('  Planos: '.$stats['count']);
        $this->line(sprintf('  Duración media: %.2f s', $stats['meanDuration']));
        $this->line(sprintf('  Mín/máx: %.2f / %.2f s', $stats['minDuration'], $stats['maxDuration']));
        $this->line('  Framing:');

        foreach ($stats['framing'] as $framing => $count) {
            if ($count === 0) {
                continue;
            }

            $this->line("    {$framing}: {$count}");
        }

        $this->line('  Subject:');

        foreach ($stats['subject'] as $subject => $count) {
            if ($count === 0) {
                continue;
            }

            $this->line("    {$subject}: {$count}");
        }

        $this->line('  Amenaza:');

        foreach ($stats['threatStage'] as $stage => $count) {
            if ($count === 0) {
                continue;
            }

            $this->line("    {$stage}: {$count}");
        }

        $this->line('  Plan: '.$shotsPath);

        $withoutImage = [];
        $placeholders = [];

        foreach ($rows as $row) {
            if ($row->placeholder) {
                $placeholders[] = $row->shot->order;
            }

            if ($row->shot->imagePath === null) {
                $withoutImage[] = $row->shot->order;
            }
        }

        if ($withoutImage !== []) {
            $this->warn('Planos sin imagen: #'.implode('  #', $withoutImage).'. Ejecuta sin --only para completarlos.');
        }

        if ($placeholders === []) {
            return;
        }

        $this->newLine();
        $this->line('<fg=red>Marcadores (regenerar con --only y, si hace falta, --force):</>');
        $this->line('<fg=red>  #'.implode('  #', $placeholders).'</>');
        $this->line('<fg=red>  php artisan story:images {file} --only='.implode(',', $placeholders).'</>');
    }

    private function truncate(string $text, int $width = 48): string
    {
        $text = preg_replace('/\s+/u', ' ', trim($text)) ?? trim($text);

        if (mb_strlen($text) <= $width) {
            return $text;
        }

        return mb_substr($text, 0, $width - 1).'…';
    }
}
