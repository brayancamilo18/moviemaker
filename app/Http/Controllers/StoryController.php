<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\StoryMode;
use App\Enums\StoryStatus;
use App\Models\Story;
use App\Services\Llm\ProviderHealth;
use App\Services\Pipeline\PipelineDispatcher;
use App\Services\Pipeline\PipelineProgress;
use App\Services\Story\StoryPromptBuilder;
use Illuminate\Contracts\Config\Repository;
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
        private readonly ProviderHealth $health,
        private readonly PipelineDispatcher $dispatcher,
        private readonly PipelineProgress $progress,
        private readonly Repository $config,
    ) {}

    public function create(): Response
    {
        return $this->inertia->render('NewStory', [
            'creatures' => $this->creatures(),
            'providers' => $this->health->check(live: false),
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
            'slug' => '',
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
        ]);
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

    private function loreName(string $slug): string
    {
        foreach ($this->prompts->loreEntries() as $entry) {
            if ($entry['slug'] === $slug) {
                return $entry['name'];
            }
        }

        throw new InvalidArgumentException("No hay una ficha de folklore con el slug '{$slug}'.");
    }
}
