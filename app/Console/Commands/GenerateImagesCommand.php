<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Contracts\ImageGenerator;
use App\DataObjects\PlannedShot;
use App\DataObjects\Shot;
use App\DataObjects\ShotPlan;
use App\DataObjects\Story;
use App\DataObjects\VisualBible;
use App\Services\Audio\NarrationClock;
use App\Services\Image\ShotDirector;
use App\Services\Image\ShotPlanner;
use App\Services\Image\ShotPlanRepository;
use App\Services\Image\ShotPromptBuilder;
use App\Services\Image\VisualBibleGenerator;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use InvalidArgumentException;
use JsonException;
use RuntimeException;
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
        private ImageGenerator $images,
        private ShotPlanner $planner,
        private ShotDirector $director,
        private ShotPromptBuilder $prompts,
        private VisualBibleGenerator $bibles,
        private ShotPlanRepository $plans,
        private NarrationClock $clock,
        private Filesystem $files,
        Repository $config,
    ) {
        parent::__construct();

        $this->outputDirectory = storage_path('app/'.$config->get('stories.output_path'));
        $this->rateLimitSeconds = (float) $config->get('stories.images.rate_limit_seconds');
    }

    public function handle(): int
    {
        $storyFile = $this->resolveStoryFile((string) $this->argument('file'));

        if ($storyFile === null) {
            return self::FAILURE;
        }

        $payload = $this->readJson($storyFile, 'El guion no es un JSON válido.');

        if ($payload === null) {
            return self::FAILURE;
        }

        if (! isset($payload['scenes']) || ! is_array($payload['scenes'])) {
            $this->error('El JSON no contiene un guion de historia.');

            return self::FAILURE;
        }

        $only = $this->parseOnly((string) $this->option('only'));

        if ($only === false) {
            return self::FAILURE;
        }

        $force = (bool) $this->option('force');
        $redirect = (bool) $this->option('redirect');
        $dryRun = (bool) $this->option('dry-run');
        $slug = pathinfo($storyFile, PATHINFO_FILENAME);
        $storyDirectory = $this->outputDirectory.DIRECTORY_SEPARATOR.$slug;
        $timingsPath = $storyDirectory.DIRECTORY_SEPARATOR.'timings.json';
        $narrationPath = $storyDirectory.DIRECTORY_SEPARATOR.'narration.wav';

        $timings = $this->readTimings($timingsPath);

        if ($timings === null) {
            return self::FAILURE;
        }

        $story = Story::fromArray($payload);

        try {
            $duration = $this->clock->narrationEnd($narrationPath);
        } catch (InvalidArgumentException|RuntimeException $exception) {
            $this->error($exception->getMessage());
            $this->line('Ejecuta story:narrate primero.');

            return self::FAILURE;
        }

        $shots = $this->planner->plan($timings, $story, $duration);

        if ($shots === []) {
            $this->error('El planificador no produjo ningún plano.');

            return self::FAILURE;
        }

        $persisted = $this->plans->read($slug);
        $samePlan = $persisted instanceof ShotPlan && $persisted->describes($shots) ? $persisted : null;

        $story = $this->ensureVisualBible($storyFile, $payload, $story);

        if ($story === null) {
            return self::FAILURE;
        }

        $bible = $story->visualBible;

        if (! $bible instanceof VisualBible) {
            $this->error('La historia no tiene biblia visual.');

            return self::FAILURE;
        }

        try {
            $shots = $this->directShots($shots, $story, $bible, $samePlan, $redirect);
            $prompts = [];

            foreach ($shots as $shot) {
                $prompts[] = $this->prompts->build($shot, $bible);
            }
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $selected = $this->selectedShots($shots, $only);

        if ($selected === []) {
            return self::FAILURE;
        }

        if ($dryRun) {
            $this->warn('Modo simulación: no se generarán imágenes.');
            $this->printPrompts($selected, $shots, $prompts, $slug);

            return self::SUCCESS;
        }

        if (! $this->images->isAvailable()) {
            $this->warn('El proveedor de imágenes no responde. Los planos nuevos pueden acabar en marcador.');
        }

        $this->printEstimate(count($selected));

        $rows = $this->baselineRows($shots, $prompts, $samePlan, $slug);

        try {
            $rows = $this->generateShots($shots, $selected, $rows, $prompts, $slug, $force);
        } catch (Throwable $exception) {
            $this->newLine();
            $this->error($exception->getMessage());
            $this->line('Los planos ya generados quedan guardados en '.$this->plans->pathFor($slug).'.');

            return self::FAILURE;
        }

        $this->plans->write($slug, $this->planFrom($rows));
        $this->renderSummary($this->planner->stats($shots), $rows, $this->plans->pathFor($slug));

        return self::SUCCESS;
    }

    /**
     * Con un plan equivalente ya dirigido, rehidrata la dirección persistida en lugar de volver
     * a llamar al director: si la description cambiara, cambiaría el prompt y con él la caché.
     *
     * @param  list<Shot>  $shots
     * @return list<Shot>
     */
    private function directShots(
        array $shots,
        Story $story,
        VisualBible $bible,
        ?ShotPlan $samePlan,
        bool $redirect,
    ): array {
        if ($samePlan instanceof ShotPlan && $samePlan->plannerVersion !== ShotPlanner::VERSION) {
            $this->warn(sprintf(
                'shots.json viene del planificador v%d y ahora es v%d: se vuelve a dirigir.',
                $samePlan->plannerVersion,
                ShotPlanner::VERSION,
            ));

            $samePlan = null;
        }

        if ($redirect || ! $samePlan instanceof ShotPlan || ! $samePlan->isDirected()) {
            return $this->director->direct($shots, $story, $bible);
        }

        $this->line('El plan no ha cambiado: se reutiliza la dirección de shots.json (--redirect para volver a dirigir).');

        $stored = $samePlan->byOrder();
        $directed = [];

        foreach ($shots as $shot) {
            $row = $stored[$shot->order];

            $directed[] = new Shot(
                order: $shot->order,
                sceneOrder: $shot->sceneOrder,
                start: $shot->start,
                end: $shot->end,
                sourceText: $shot->sourceText,
                framing: $row->shot->framing,
                motion: $shot->motion,
                subject: $row->shot->subject,
                threatStage: $row->shot->threatStage,
                description: $row->shot->description,
                characterSlugs: $row->shot->characterSlugs,
                imagePath: $shot->imagePath,
            );
        }

        return $directed;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function ensureVisualBible(string $storyFile, array $payload, Story $story): ?Story
    {
        if ($story->visualBible instanceof VisualBible) {
            return $story;
        }

        $this->info('No hay biblia visual. Generándola…');

        try {
            $bible = $this->bibles->generate($story);
        } catch (Throwable $exception) {
            $this->error('No se pudo generar la biblia visual: '.$exception->getMessage());

            return null;
        }

        $payload['visualBible'] = $bible->toArray();
        $this->writeJson($storyFile, $payload);

        return $story->withVisualBible($bible);
    }

    /**
     * @param  list<Shot>  $shots
     * @param  list<int>|null  $only
     * @return list<Shot>
     */
    private function selectedShots(array $shots, ?array $only): array
    {
        if ($only === null) {
            return $shots;
        }

        $byOrder = [];

        foreach ($shots as $shot) {
            $byOrder[$shot->order] = true;
        }

        $missing = [];

        foreach ($only as $order) {
            if (! isset($byOrder[$order])) {
                $missing[] = $order;
            }
        }

        if ($missing !== []) {
            $this->warn('No existen estos planos: '.implode(', ', $missing).'.');
        }

        $wanted = array_fill_keys($only, true);
        $selected = [];

        foreach ($shots as $shot) {
            if (isset($wanted[$shot->order])) {
                $selected[] = $shot;
            }
        }

        if ($selected === []) {
            $this->error('Ningún plano de --only coincide con el plan.');
        }

        return $selected;
    }

    /**
     * Punto de partida de shots.json: las filas ya persistidas se conservan tal cual (una fila que
     * --only no toca debe seguir describiendo la imagen a la que apunta) y el resto nace sin imagen.
     *
     * @param  list<Shot>  $shots
     * @param  list<string>  $prompts
     * @return list<PlannedShot>
     */
    private function baselineRows(array $shots, array $prompts, ?ShotPlan $samePlan, string $slug): array
    {
        $stored = $samePlan?->byOrder() ?? [];
        $rows = [];

        foreach ($shots as $index => $shot) {
            $rows[] = $stored[$shot->order] ?? PlannedShot::fromShot(
                $shot,
                $prompts[$index] ?? '',
                $this->baseSeed($slug, $shot->order),
            );
        }

        return $rows;
    }

    /**
     * @param  list<Shot>  $shots
     * @param  list<Shot>  $selected
     * @param  list<PlannedShot>  $rows
     * @param  list<string>  $prompts
     * @return list<PlannedShot>
     */
    private function generateShots(
        array $shots,
        array $selected,
        array $rows,
        array $prompts,
        string $slug,
        bool $force,
    ): array {
        $generateOrders = [];

        foreach ($selected as $shot) {
            $generateOrders[$shot->order] = true;
        }

        $bar = $this->output->createProgressBar(count($selected));
        $bar->setFormat('%current%/%max% [%bar%] %percent:3s%% restante %remaining%  %message%');
        $bar->setMessage('');
        $bar->start();

        try {
            foreach ($shots as $index => $shot) {
                if (! isset($generateOrders[$shot->order])) {
                    continue;
                }

                $bar->setMessage($this->truncate('#'.$shot->order.' '.$shot->framing));

                $prompt = $prompts[$index] ?? '';
                $seed = $this->seedFor($slug, $shot, $rows[$index] ?? null, $force);
                $path = $this->images->generate($prompt, $seed);

                $rows[$index] = PlannedShot::fromShot($shot, $prompt, $seed, $path);
                $this->plans->write($slug, $this->planFrom($rows));

                $bar->advance();
            }
        } finally {
            $bar->finish();
            $this->newLine();
        }

        return $rows;
    }

    /**
     * @param  list<PlannedShot>  $rows
     */
    private function planFrom(array $rows): ShotPlan
    {
        return new ShotPlan(
            version: ShotPlan::VERSION,
            plannerVersion: ShotPlanner::VERSION,
            shots: $rows,
        );
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

    private function seedFor(string $slug, Shot $shot, ?PlannedShot $previous, bool $force): int
    {
        $base = $this->baseSeed($slug, $shot->order);

        if (! $force) {
            return $base;
        }

        $previousSeed = $previous instanceof PlannedShot && $previous->seed > 0
            ? $previous->seed
            : $base;

        $seed = ($previousSeed + 1) & 0x7FFFFFFF;

        return $seed === 0 ? 1 : $seed;
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

    /**
     * @return array<string, mixed>|null
     */
    private function readTimings(string $path): ?array
    {
        if (! $this->files->isFile($path)) {
            $this->error('No hay timings.json.');

            $wav = dirname($path).DIRECTORY_SEPARATOR.'narration.wav';
            $slug = basename(dirname($path));

            if ($this->files->isFile($wav)) {
                $this->line('El máster de audio sí está. Alinea con:');
                $this->line("  php artisan story:narrate {$slug}.json --timings-only");
                $this->line('Hace falta whisper.cpp y WHISPER_MODEL (ruta a un ggml-*.bin).');
            } else {
                $this->line('Ejecuta story:narrate primero (sin --skip-timings).');
            }

            return null;
        }

        $timings = $this->readJson($path, 'timings.json no es un JSON válido.');

        if ($timings === null || ! isset($timings['sentences']) || ! is_array($timings['sentences'])) {
            $this->error('timings.json no tiene el esquema esperado.');

            return null;
        }

        return $timings;
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

    /**
     * @param  array<string, mixed>  $payload
     */
    private function writeJson(string $path, array $payload): void
    {
        $this->files->ensureDirectoryExists(dirname($path));

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        if ($json === false) {
            throw new RuntimeException('No se pudo serializar el JSON.');
        }

        $this->files->put($path, $json."\n");
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
