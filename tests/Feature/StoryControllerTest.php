<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ReviewVerdict;
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
        $this->assertStringStartsWith('draft-', $story->slug);
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
                ->where('snapshot.status', StoryStatus::Draft->value)
                ->where('snapshot.progress.step', 'script')
                ->where('snapshot.progress.label', 'guion')
                ->where('queue.likelyNoWorker', false)
                ->where('snapshot.queue.likelyNoWorker', false)
            );
    }

    public function test_progress_json_includes_status_label_color_and_metrics(): void
    {
        $story = Story::factory()->create([
            'status' => StoryStatus::ScriptReady,
            'title' => 'The mill chain',
            'verdict' => ReviewVerdict::Publish,
            'score' => 8.4,
            'scene_count' => 12,
            'used_fallback' => true,
        ]);

        $this->get(route('stories.progress', $story))
            ->assertOk()
            ->assertJsonPath('status', StoryStatus::ScriptReady->value)
            ->assertJsonPath('status_label', StoryStatus::ScriptReady->label())
            ->assertJsonPath('status_color', StoryStatus::ScriptReady->color())
            ->assertJsonPath('progress', null)
            ->assertJsonPath('title', 'The mill chain')
            ->assertJsonPath('verdict', 'publish')
            ->assertJsonPath('scene_count', 12)
            ->assertJsonPath('used_fallback', true)
            ->assertJsonPath('failed_step', null)
            ->assertJsonPath('queue.pending', 0)
            ->assertJsonPath('queue.oldestPendingSeconds', null)
            ->assertJsonPath('queue.failed', 0)
            ->assertJsonPath('queue.likelyNoWorker', false);
    }

    public function test_retry_queues_the_failed_step_without_chaining(): void
    {
        Bus::fake();

        $story = Story::factory()->create([
            'status' => StoryStatus::Failed,
            'failed_step' => 'images',
            'failed_message' => 'Pollinations no respondió.',
        ]);

        $this->post(route('stories.retry', $story))
            ->assertRedirect(route('pipeline.show', $story));

        Bus::assertDispatched(
            RunPipelineStep::class,
            static fn (RunPipelineStep $job): bool => $job->storyId === $story->id
                && $job->step === 'images'
                && $job->chain === false,
        );

        $this->assertSame(
            'images',
            $this->app->make(PipelineProgress::class)->get($story->id)['step'] ?? null,
        );
    }

    public function test_continue_advances_from_script_ready_with_chaining(): void
    {
        Bus::fake();

        $story = Story::factory()->create(['status' => StoryStatus::ScriptReady]);

        $this->post(route('stories.continue', $story))
            ->assertRedirect(route('pipeline.show', $story));

        Bus::assertDispatched(
            RunPipelineStep::class,
            static fn (RunPipelineStep $job): bool => $job->storyId === $story->id
                && $job->step === 'narration'
                && $job->chain === true,
        );
    }

    public function test_discard_from_failed_returns_to_the_queue(): void
    {
        $story = Story::factory()->create(['status' => StoryStatus::Failed]);

        $this->post(route('stories.discard', $story))
            ->assertRedirect(route('queue'));

        $this->assertSame(StoryStatus::Discarded, $story->fresh()?->status);
    }

    public function test_the_listing_pipeline_route_still_renders(): void
    {
        $this->get(route('pipeline'))
            ->assertOk()
            ->assertInertia(fn (Assert $page): Assert => $page
                ->component('Pipeline')
                ->where('queue.likelyNoWorker', false)
                ->where('queue.pending', 0));
    }

    public function test_the_queue_page_includes_queue_health(): void
    {
        $this->get(route('queue'))
            ->assertOk()
            ->assertInertia(fn (Assert $page): Assert => $page
                ->component('Queue')
                ->where('queue.likelyNoWorker', false)
                ->where('queue.pending', 0)
                ->where('queue.failed', 0));
    }

    public function test_live_llm_health_returns_json_without_throwing(): void
    {
        Http::fake(['*' => Http::response(['unexpected' => true], 200)]);
        Http::preventStrayRequests();

        $this->post(route('llm.health'))
            ->assertOk()
            ->assertJsonStructure([
                'gemini' => ['name', 'configured', 'reachable', 'latencyMs', 'error', 'errorClass', 'hint'],
                'anthropic' => ['name', 'configured', 'reachable', 'latencyMs', 'error', 'errorClass', 'hint'],
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
