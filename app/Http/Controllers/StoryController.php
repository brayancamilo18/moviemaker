<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\StoryMode;
use App\Enums\StoryStatus;
use App\Models\Story;
use App\Services\Llm\AnthropicClient;
use App\Services\Llm\GeminiClient;
use App\Services\Llm\ProviderHealthStore;
use App\Services\Pipeline\PipelineDispatcher;
use App\Services\Pipeline\PipelineProgress;
use App\Services\Pipeline\QueueHealth;
use App\Services\Story\StoryPromptBuilder;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Response;
use Inertia\ResponseFactory;
use InvalidArgumentException;

final class StoryController extends Controller
{
    public function __construct(
        private readonly ResponseFactory $inertia,
        private readonly StoryPromptBuilder $prompts,
        private readonly ProviderHealthStore $store,
        private readonly GeminiClient $gemini,
        private readonly AnthropicClient $anthropic,
        private readonly PipelineDispatcher $dispatcher,
        private readonly PipelineProgress $progress,
        private readonly QueueHealth $queue,
        private readonly Repository $config,
    ) {}

    public function create(): Response
    {
        $stored = $this->store->get();

        return $this->inertia->render('NewStory', [
            'creatures' => $this->creatures(),
            'providers' => $this->providers($stored),
            'health' => $stored === null ? null : [
                'measuredAt' => $stored['measuredAt'],
                'ageSeconds' => $stored['ageSeconds'],
                'measuredBy' => $stored['measuredBy'],
            ],
            'defaults' => [
                'mode' => (string) $this->config->get('stories.story.default_mode'),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $modeValues = array_map(
            static fn (StoryMode $mode): string => $mode->value,
            StoryMode::cases(),
        );
        $loreSlugs = array_map(
            static fn (array $entry): string => $entry['slug'],
            $this->prompts->loreEntries(),
        );

        $validated = $request->validate([
            'mode' => ['required', 'string', Rule::in($modeValues)],
            'lore_slug' => [
                Rule::requiredIf($request->input('mode') === StoryMode::Folklore->value),
                Rule::prohibitedIf($request->input('mode') === StoryMode::Original->value),
                'nullable',
                'string',
                Rule::in($loreSlugs),
            ],
            'premise' => ['nullable', 'string', 'max:500'],
            'only_script' => ['sometimes', 'boolean'],
        ]);

        $mode = StoryMode::from($validated['mode']);
        $onlyScript = $request->boolean('only_script', true);
        $loreSlug = $mode === StoryMode::Folklore ? (string) $validated['lore_slug'] : null;

        $story = Story::query()->create([
            'slug' => Story::provisionalSlug(),
            'title' => '',
            'mode' => $mode,
            'lore_slug' => $loreSlug,
            'lore_name' => $loreSlug === null ? null : $this->loreName($loreSlug),
            'premise' => $validated['premise'] ?? null,
            'status' => StoryStatus::Draft,
        ]);

        $story->events()->create([
            'type' => 'created',
            'to_status' => StoryStatus::Draft->value,
        ]);

        $this->dispatcher->runFrom($story, 'script', chain: ! $onlyScript);

        return redirect()->route('pipeline.show', $story);
    }

    public function pipeline(Story $story): Response
    {
        return $this->inertia->render('Pipeline', [
            'story' => $story,
            'progress' => $this->progress->get($story->id),
            'snapshot' => $this->snapshot($story),
            'queue' => $this->queue->status(),
        ]);
    }

    public function review(Story $story): Response
    {
        return $this->inertia->render('Review', [
            'story' => $story,
            'empty' => false,
            'status_label' => $story->status->label(),
            'status_color' => $story->status->color(),
        ]);
    }

    public function reviewEntry(): RedirectResponse|Response
    {
        $story = Story::query()
            ->pendingReview()
            ->orderBy('updated_at')
            ->first();

        if ($story instanceof Story) {
            return redirect()->route('review.show', $story);
        }

        return $this->inertia->render('Review', [
            'story' => null,
            'empty' => true,
        ]);
    }

    public function progress(Story $story): JsonResponse
    {
        return response()->json($this->snapshot($story->fresh() ?? $story));
    }

    public function retry(Story $story): RedirectResponse
    {
        if ($story->status !== StoryStatus::Failed) {
            abort(422, 'La historia no está fallida.');
        }

        $step = (string) $story->failed_step;

        if (! in_array($step, PipelineDispatcher::STEPS, true)) {
            abort(422, 'No hay un paso fallido que reintentar.');
        }

        $this->dispatcher->runFrom($story, $step, chain: false);

        return redirect()->route('pipeline.show', $story);
    }

    public function continuePipeline(Story $story): RedirectResponse
    {
        if ($story->status !== StoryStatus::ScriptReady) {
            abort(422, 'El pipeline solo se continúa con el guion listo.');
        }

        $this->dispatcher->advance($story, chain: true);

        return redirect()->route('pipeline.show', $story);
    }

    public function discard(Story $story): RedirectResponse
    {
        if (! $story->status->canTransitionTo(StoryStatus::Discarded)) {
            abort(422, 'Esta historia no se puede descartar.');
        }

        $story->transitionTo(StoryStatus::Discarded);

        return redirect()->route('queue');
    }

    /**
     * @return list<array{slug: string, name: string, region: string, usedCount: int, lastUsedAt: string|null}>
     */
    private function creatures(): array
    {
        $usage = Story::query()
            ->select('lore_slug')
            ->selectRaw('COUNT(*) as used_count')
            ->selectRaw('MAX(created_at) as last_used_at')
            ->whereNotNull('lore_slug')
            ->where('lore_slug', '!=', '')
            ->groupBy('lore_slug')
            ->get()
            ->keyBy('lore_slug');

        $creatures = [];

        foreach ($this->prompts->loreEntries() as $entry) {
            $row = $usage->get($entry['slug']);
            $lastUsed = $row === null ? null : $row->getAttribute('last_used_at');

            $creatures[] = [
                'slug' => $entry['slug'],
                'name' => $entry['name'],
                'region' => $entry['region'],
                'usedCount' => $row === null ? 0 : (int) $row->getAttribute('used_count'),
                'lastUsedAt' => is_string($lastUsed) && $lastUsed !== ''
                    ? $lastUsed
                    : null,
            ];
        }

        usort(
            $creatures,
            static fn (array $left, array $right): int => strcasecmp($left['name'], $right['name']),
        );

        return $creatures;
    }

    /**
     * @param  array{report: array<string, mixed>, measuredAt: string, ageSeconds: int, measuredBy: string}|null  $stored
     * @return array{gemini: array<string, mixed>, anthropic: array<string, mixed>}
     */
    private function providers(?array $stored): array
    {
        $providers = [
            'gemini' => $this->unmeasured($this->gemini),
            'anthropic' => $this->unmeasured($this->anthropic),
        ];

        if ($stored === null) {
            return $providers;
        }

        foreach (['gemini', 'anthropic'] as $key) {
            $entry = $stored['report'][$key] ?? null;

            if (! is_array($entry)) {
                continue;
            }

            $providers[$key] = array_merge($providers[$key], $entry);
            $providers[$key]['ageSeconds'] = $stored['ageSeconds'];
            $providers[$key]['measuredAt'] = $stored['measuredAt'];

            if (! isset($providers[$key]['measuredBy']) || $providers[$key]['measuredBy'] === null || $providers[$key]['measuredBy'] === '') {
                $providers[$key]['measuredBy'] = $stored['measuredBy'];
            }
        }

        return $providers;
    }

    /**
     * @return array{name: string, configured: bool, reachable: null, latencyMs: null, error: null, errorClass: null, hint: null, measuredBy: null, ageSeconds: null, measuredAt: null}
     */
    private function unmeasured(GeminiClient|AnthropicClient $client): array
    {
        return [
            'name' => $client->name(),
            'configured' => $client->isAvailable(),
            'reachable' => null,
            'latencyMs' => null,
            'error' => null,
            'errorClass' => null,
            'hint' => null,
            'measuredBy' => null,
            'ageSeconds' => null,
            'measuredAt' => null,
        ];
    }

    private function loreName(string $slug): string
    {
        foreach ($this->prompts->loreEntries() as $entry) {
            if ($entry['slug'] === $slug) {
                return $entry['name'];
            }
        }

        throw new InvalidArgumentException("No hay una ficha de folklore con el slug '{$slug}'.");
    }

    /**
     * @return array{status: string, status_label: string, status_color: string, progress: array{step: string, label: string, done: int, total: int}|null, failed_step: string|null, failed_message: string|null, title: string, verdict: string|null, score: float|null, scene_count: int|null, used_fallback: bool, created_at: string|null, stale_draft_seconds: int, queue: array{pending: int, waiting: int, running: int, oldestWaitingSeconds: int|null, failed: int, likelyNoWorker: bool, workerBusy: bool}}
     */
    private function snapshot(Story $story): array
    {
        $status = $story->status;

        return [
            'status' => $status->value,
            'status_label' => $status->label(),
            'status_color' => $status->color(),
            'progress' => $this->progress->get($story->id),
            'failed_step' => $story->failed_step,
            'failed_message' => $story->failed_message,
            'title' => $story->title,
            'verdict' => $story->verdict?->value,
            'score' => $story->score,
            'scene_count' => $story->scene_count,
            'used_fallback' => (bool) $story->used_fallback,
            'created_at' => $story->created_at?->toIso8601String(),
            'stale_draft_seconds' => (int) $this->config->get('stories.pipeline.stale_draft_seconds'),
            'queue' => $this->queue->status(),
        ];
    }
}
