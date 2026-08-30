<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\StoryMode;
use App\Enums\StoryStatus;
use App\Jobs\RunPipelineStep;
use App\Models\Story;
use App\Models\StoryEvent;
use App\Services\Pipeline\PipelineProgress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class StoryControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_the_create_page_lists_creatures_with_usage_and_provider_status(): void
    {
        $used = Story::factory()->create([
            'lore_slug' => 'silbon',
            'lore_name' => 'El Silbón',
        ]);

        $this->get(route('stories.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page): Assert => $page
                ->component('NewStory')
                ->where('defaults.mode', 'folklore')
                ->has('providers.gemini.name')
                ->where('providers.gemini.reachable', null)
                ->has('providers.anthropic.name')
                ->has('creatures')
                ->where('creatures.0.name', fn (string $name): bool => $name !== '')
                ->has('creatures', fn (Assert $creatures): Assert => $creatures
                    ->each(fn (Assert $creature): Assert => $creature
                        ->has('slug')
                        ->has('name')
                        ->has('region')
                        ->has('usedCount')
                        ->has('lastUsedAt')
                        ->etc()
                    )
                )
                ->where(
                    'creatures',
                    fn (mixed $creatures): bool => $this->creatureNamed($creatures, 'El Silbón')['usedCount'] === 1
                        && $this->creatureNamed($creatures, 'El Silbón')['lastUsedAt'] !== null
                        && $this->namesAreSorted($creatures),
                )
            );

        $this->assertNotNull($used->created_at);
    }

    public function test_store_creates_a_draft_and_queues_script_without_chaining_by_default(): void
    {
        Bus::fake();

        $response = $this->post(route('stories.store'), [
            'mode' => StoryMode::Folklore->value,
            'lore_slug' => 'silbon',
            'premise' => 'A whistle recedes through the cane.',
        ]);

        $story = Story::query()->first();

        $this->assertInstanceOf(Story::class, $story);
        $this->assertSame('', $story->slug);
        $this->assertSame('', $story->title);
        $this->assertSame(StoryStatus::Draft, $story->status);
        $this->assertSame(StoryMode::Folklore, $story->mode);
        $this->assertSame('silbon', $story->lore_slug);
        $this->assertSame('El Silbón', $story->lore_name);
        $this->assertSame('A whistle recedes through the cane.', $story->premise);

        $event = $story->events()->where('type', 'created')->first();

        $this->assertInstanceOf(StoryEvent::class, $event);
        $this->assertSame(StoryStatus::Draft->value, $event->to_status);

        $response->assertRedirect(route('pipeline.show', $story));

        Bus::assertDispatched(
            RunPipelineStep::class,
            static fn (RunPipelineStep $job): bool => $job->storyId === $story->id
                && $job->step === 'script'
                && $job->chain === false,
        );
    }

    public function test_store_with_only_script_false_chains_the_pipeline(): void
    {
        Bus::fake();

        $this->post(route('stories.store'), [
            'mode' => StoryMode::Original->value,
            'only_script' => false,
        ])->assertRedirect();

        $story = Story::query()->first();

        $this->assertInstanceOf(Story::class, $story);
        $this->assertNull($story->lore_slug);
        $this->assertNull($story->lore_name);

        Bus::assertDispatched(
            RunPipelineStep::class,
            static fn (RunPipelineStep $job): bool => $job->chain === true,
        );
    }

    public function test_store_rejects_lore_on_an_original_story_and_requires_it_for_folklore(): void
    {
        Bus::fake();

        $this->from(route('stories.create'))
            ->post(route('stories.store'), [
                'mode' => StoryMode::Original->value,
                'lore_slug' => 'silbon',
            ])
            ->assertSessionHasErrors('lore_slug');

        $this->from(route('stories.create'))
            ->post(route('stories.store'), [
                'mode' => StoryMode::Folklore->value,
            ])
            ->assertSessionHasErrors('lore_slug');

        Bus::assertNothingDispatched();
        $this->assertSame(0, Story::query()->count());
    }

    public function test_the_pipeline_page_receives_the_story_and_its_progress(): void
    {
        $story = Story::factory()->create(['status' => StoryStatus::Draft]);

        $this->app->make(PipelineProgress::class)
            ->put($story->id, 'script', 'guion', 0, 1);

        $this->get(route('pipeline.show', $story))
            ->assertOk()
            ->assertInertia(fn (Assert $page): Assert => $page
                ->component('Pipeline')
                ->where('story.id', $story->id)
                ->where('progress.step', 'script')
                ->where('progress.label', 'guion')
                ->where('progress.done', 0)
                ->where('progress.total', 1)
            );
    }

    public function test_the_listing_pipeline_route_still_renders(): void
    {
        $this->get(route('pipeline'))
            ->assertOk()
            ->assertInertia(fn (Assert $page): Assert => $page->component('Pipeline'));
    }

    public function test_live_llm_health_returns_json_without_throwing(): void
    {
        Http::fake(['*' => Http::response(['unexpected' => true], 200)]);
        Http::preventStrayRequests();

        $this->post(route('llm.health'))
            ->assertOk()
            ->assertJsonStructure([
                'gemini' => ['name', 'configured', 'reachable', 'latencyMs', 'error'],
                'anthropic' => ['name', 'configured', 'reachable', 'latencyMs', 'error'],
            ]);
    }

    /**
     * @return array{slug: string, name: string, region: string, usedCount: int, lastUsedAt: string|null}
     */
    private function creatureNamed(mixed $creatures, string $name): array
    {
        foreach ($this->creatureList($creatures) as $creature) {
            if ($creature['name'] === $name) {
                return $creature;
            }
        }

        $this->fail("No apareció la criatura {$name}.");
    }

    private function namesAreSorted(mixed $creatures): bool
    {
        $names = array_column($this->creatureList($creatures), 'name');
        $sorted = $names;
        usort($sorted, static fn (string $left, string $right): int => strcasecmp($left, $right));

        return $names === $sorted;
    }

    /**
     * @return list<array{slug: string, name: string, region: string, usedCount: int, lastUsedAt: string|null}>
     */
    private function creatureList(mixed $creatures): array
    {
        if ($creatures instanceof Collection) {
            $creatures = $creatures->all();
        }

        $this->assertIsArray($creatures);

        /** @var list<array{slug: string, name: string, region: string, usedCount: int, lastUsedAt: string|null}> $creatures */
        return array_values($creatures);
    }
}
