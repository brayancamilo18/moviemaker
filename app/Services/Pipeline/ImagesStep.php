<?php

declare(strict_types=1);

namespace App\Services\Pipeline;

use App\Contracts\ImageGenerator;
use App\Contracts\JsonLlm;
use App\DataObjects\PlannedShot;
use App\DataObjects\Shot;
use App\DataObjects\ShotPlan;
use App\DataObjects\Story as StoryScript;
use App\DataObjects\VisualBible;
use App\Models\Story;
use App\Services\Audio\NarrationClock;
use App\Services\Image\ShotDirector;
use App\Services\Image\ShotPlanner;
use App\Services\Image\ShotPlanRepository;
use App\Services\Image\ShotPromptBuilder;
use App\Services\Image\VisualBibleGenerator;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use InvalidArgumentException;
use JsonException;
use RuntimeException;
use Throwable;

final class ImagesStep
{
    private readonly string $outputDirectory;

    public function __construct(
        private ImageGenerator $images,
        private ShotPlanner $planner,
        private ShotDirector $director,
        private ShotPromptBuilder $prompts,
        private VisualBibleGenerator $bibles,
        private ShotPlanRepository $plans,
        private NarrationClock $clock,
        private JsonLlm $llm,
        private Filesystem $files,
        Repository $config,
    ) {
        $this->outputDirectory = storage_path('app/'.$config->get('stories.output_path'));
    }

    /**
     * @param  (callable(string, int, int): void)|null  $onProgress
     * @param  array{only?: list<int>|null, force?: bool, redirect?: bool, dry_run?: bool}  $options
     * @return array<string, mixed>
     */
    public function run(Story $story, ?callable $onProgress = null, array $options = []): array
    {
        $only = $options['only'] ?? null;
        $force = (bool) ($options['force'] ?? false);
        $redirect = (bool) ($options['redirect'] ?? false);
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $slug = $story->slug;
        $storyFile = $this->scriptPath($slug);
        $storyDirectory = $this->outputDirectory.DIRECTORY_SEPARATOR.$slug;
        $timingsPath = $storyDirectory.DIRECTORY_SEPARATOR.'timings.json';
        $narrationPath = $storyDirectory.DIRECTORY_SEPARATOR.'narration.wav';

        $payload = $this->readJson($storyFile);

        if ($payload === null || ! isset($payload['scenes']) || ! is_array($payload['scenes'])) {
            return ['ok' => false, 'error' => 'El JSON no contiene un guion de historia.'];
        }

        $timings = $this->readTimings($timingsPath);

        if (isset($timings['ok']) && $timings['ok'] === false) {
            return $timings;
        }

        $script = StoryScript::fromArray($payload);

        try {
            $duration = $this->clock->narrationEnd($narrationPath);
        } catch (InvalidArgumentException|RuntimeException $exception) {
            return [
                'ok' => false,
                'error' => $exception->getMessage(),
                'exception' => $exception,
                'hints' => ['Ejecuta story:narrate primero.'],
            ];
        }

        $shots = $this->planner->plan($timings, $script, $duration);

        if ($shots === []) {
            return ['ok' => false, 'error' => 'El planificador no produjo ningún plano.'];
        }

        $persisted = $this->plans->read($slug);
        $samePlan = $persisted instanceof ShotPlan && $persisted->describes($shots) ? $persisted : null;

        $bibleResult = $this->ensureVisualBible($storyFile, $payload, $script);

        if (($bibleResult['ok'] ?? true) === false) {
            return $bibleResult;
        }

        /** @var StoryScript $script */
        $script = $bibleResult['script'];
        $bible = $script->visualBible;

        if (! $bible instanceof VisualBible) {
            return ['ok' => false, 'error' => 'La historia no tiene biblia visual.'];
        }

        try {
            $directed = $this->directShots($shots, $script, $bible, $samePlan, $redirect);
            $shots = $directed['shots'];
            $prompts = [];

            foreach ($shots as $shot) {
                $prompts[] = $this->prompts->build($shot, $bible);
            }
        } catch (Throwable $exception) {
            return ['ok' => false, 'error' => $exception->getMessage(), 'exception' => $exception];
        }

        $selected = $this->selectedShots($shots, $only);

        if ($selected['shots'] === []) {
            return [
                'ok' => false,
                'error' => 'Ningún plano de --only coincide con el plan.',
                'warnings' => $selected['missing'] === []
                    ? []
                    : ['No existen estos planos: '.implode(', ', $selected['missing']).'.'],
            ];
        }

        $warnings = [];

        if ($selected['missing'] !== []) {
            $warnings[] = 'No existen estos planos: '.implode(', ', $selected['missing']).'.';
        }

        if ($samePlan instanceof ShotPlan && $samePlan->plannerVersion !== ShotPlanner::VERSION) {
            $warnings[] = sprintf(
                'shots.json viene del planificador v%d y ahora es v%d: se vuelve a dirigir.',
                $samePlan->plannerVersion,
                ShotPlanner::VERSION,
            );
        }

        $result = [
            'ok' => true,
            'shot_count' => count($shots),
            'figure_ratio' => $this->subjectRatio($shots, 'threat'),
            'detail_ratio' => $this->subjectRatio($shots, 'detail'),
            'dry_run' => $dryRun,
            'bible_generated' => (bool) $bibleResult['generated'],
            'direction_reused' => (bool) $directed['reused'],
            'llm_name' => $this->llm->name(),
            'fallback_notice' => $this->llm->fallbackNotice(),
            'warnings' => $warnings,
            'selected' => $selected['shots'],
            'shots' => $shots,
            'prompts' => $prompts,
            'slug' => $slug,
            'stats' => $this->planner->stats($shots),
            'shots_path' => $this->plans->pathFor($slug),
            'provider_available' => true,
            'rows' => [],
        ];

        if ($dryRun) {
            return $result;
        }

        $result['provider_available'] = $this->images->isAvailable();

        $rows = $this->baselineRows($shots, $prompts, $samePlan, $slug);

        try {
            $rows = $this->generateShots($shots, $selected['shots'], $rows, $prompts, $slug, $force, $onProgress);
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'error' => $exception->getMessage(),
                'exception' => $exception,
                'partial' => true,
                'shots_path' => $this->plans->pathFor($slug),
            ];
        }

        $this->plans->write($slug, $this->planFrom($rows));

        $result['rows'] = $rows;
        $result['figure_ratio'] = $this->subjectRatio($shots, 'threat');
        $result['detail_ratio'] = $this->subjectRatio($shots, 'detail');

        return $result;
    }

    /**
     * @param  list<Shot>  $shots
     * @return array{shots: list<Shot>, reused: bool}
     */
    private function directShots(
        array $shots,
        StoryScript $story,
        VisualBible $bible,
        ?ShotPlan $samePlan,
        bool $redirect,
    ): array {
        if ($samePlan instanceof ShotPlan && $samePlan->plannerVersion !== ShotPlanner::VERSION) {
            $samePlan = null;
        }

        if ($redirect || ! $samePlan instanceof ShotPlan || ! $samePlan->isDirected()) {
            return [
                'shots' => $this->director->direct($shots, $story, $bible),
                'reused' => false,
            ];
        }

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
                journeyLeg: $row->shot->journeyLeg,
                lightStage: $row->shot->lightStage,
                description: $row->shot->description,
                characterSlugs: $row->shot->characterSlugs,
                imagePath: $shot->imagePath,
                isOutro: $shot->isOutro,
            );
        }

        return ['shots' => $directed, 'reused' => true];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function ensureVisualBible(string $storyFile, array $payload, StoryScript $story): array
    {
        if ($story->visualBible instanceof VisualBible) {
            return ['ok' => true, 'script' => $story, 'generated' => false];
        }

        try {
            $bible = $this->bibles->generate($story);
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'error' => 'No se pudo generar la biblia visual: '.$exception->getMessage(),
                'exception' => $exception,
            ];
        }

        $payload['visualBible'] = $bible->toArray();
        $this->writeJson($storyFile, $payload);

        return ['ok' => true, 'script' => $story->withVisualBible($bible), 'generated' => true];
    }

    /**
     * @param  list<Shot>  $shots
     * @param  list<int>|null  $only
     * @return array{shots: list<Shot>, missing: list<int>}
     */
    private function selectedShots(array $shots, ?array $only): array
    {
        if ($only === null) {
            return ['shots' => $shots, 'missing' => []];
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

        $wanted = array_fill_keys($only, true);
        $selected = [];

        foreach ($shots as $shot) {
            if (isset($wanted[$shot->order])) {
                $selected[] = $shot;
            }
        }

        return ['shots' => $selected, 'missing' => $missing];
    }

    /**
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
        ?callable $onProgress,
    ): array {
        $generateOrders = [];

        foreach ($selected as $shot) {
            $generateOrders[$shot->order] = true;
        }

        $total = count($selected);
        $done = 0;
        $this->progress($onProgress, '', 0, $total);

        foreach ($shots as $index => $shot) {
            if (! isset($generateOrders[$shot->order])) {
                continue;
            }

            $prompt = $prompts[$index] ?? '';
            $seed = $this->seedFor($slug, $shot, $rows[$index] ?? null, $force);
            $path = $this->images->generate($prompt, $seed);

            $rows[$index] = PlannedShot::fromShot($shot, $prompt, $seed, $path);
            $this->plans->write($slug, $this->planFrom($rows));

            $done++;
            $this->progress($onProgress, '#'.$shot->order.' '.$shot->framing, $done, $total);
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
     * @return array<string, mixed>|array{ok: false, error: string, hints?: list<string>}
     */
    private function readTimings(string $path): array
    {
        if (! $this->files->isFile($path)) {
            $wav = dirname($path).DIRECTORY_SEPARATOR.'narration.wav';
            $slug = basename(dirname($path));
            $hints = $this->files->isFile($wav)
                ? [
                    'El máster de audio sí está. Alinea con:',
                    "  php artisan story:narrate {$slug}.json --timings-only",
                    'Hace falta whisper.cpp y WHISPER_MODEL (ruta a un ggml-*.bin).',
                ]
                : ['Ejecuta story:narrate primero (sin --skip-timings).'];

            return ['ok' => false, 'error' => 'No hay timings.json.', 'hints' => $hints];
        }

        $timings = $this->readJson($path);

        if ($timings === null || ! isset($timings['sentences']) || ! is_array($timings['sentences'])) {
            return ['ok' => false, 'error' => 'timings.json no tiene el esquema esperado.'];
        }

        return $timings;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readJson(string $path): ?array
    {
        try {
            $decoded = json_decode($this->files->get($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! is_array($decoded)) {
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

    /**
     * @param  list<Shot>  $shots
     */
    private function subjectRatio(array $shots, string $subject): float
    {
        $counted = [];

        foreach ($shots as $shot) {
            if ($shot->isOutro) {
                continue;
            }

            $counted[] = $shot;
        }

        if ($counted === []) {
            return 0.0;
        }

        $hits = 0;

        foreach ($counted as $shot) {
            if ($shot->subject === $subject) {
                $hits++;
            }
        }

        return $hits / count($counted);
    }

    private function scriptPath(string $slug): string
    {
        return $this->outputDirectory.DIRECTORY_SEPARATOR.$slug.'.json';
    }

    /**
     * @param  (callable(string, int, int): void)|null  $onProgress
     */
    private function progress(?callable $onProgress, string $label, int $done, int $total): void
    {
        if ($onProgress !== null) {
            $onProgress($label, $done, $total);
        }
    }
}
