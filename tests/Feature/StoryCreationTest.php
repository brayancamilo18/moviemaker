<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\StoryMode;
use App\Enums\StoryStatus;
use App\Jobs\RunPipelineStep;
use App\Models\Story;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class StoryCreationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Http::fake();
        Http::preventStrayRequests();
        Bus::fake();
    }

    public function test_folklore_with_a_creature_creates_a_draft_and_queues_script(): void
    {
        $this->post(route('stories.store'), [
            'mode' => StoryMode::Folklore->value,
            'lore_slug' => 'silbon',
        ])->assertRedirect();

        $story = Story::query()->first();

        $this->assertInstanceOf(Story::class, $story);
        $this->assertSame(StoryStatus::Draft, $story->status);
        $this->assertSame('silbon', $story->lore_slug);
        $this->assertSame('El Silbón', $story->lore_name);

        Bus::assertDispatched(
            RunPipelineStep::class,
            static fn (RunPipelineStep $job): bool => $job->storyId === $story->id
                && $job->step === 'script',
        );
        Http::assertNothingSent();
    }

    public function test_folklore_without_a_creature_fails_validation_on_lore_slug(): void
    {
        $this->from(route('stories.create'))
            ->post(route('stories.store'), [
                'mode' => StoryMode::Folklore->value,
            ])
            ->assertSessionHasErrors('lore_slug');

        $this->assertSame(0, Story::query()->count());
        Bus::assertNothingDispatched();
    }

    public function test_original_with_a_creature_rejects_lore_slug(): void
    {
        $this->from(route('stories.create'))
            ->post(route('stories.store'), [
                'mode' => StoryMode::Original->value,
                'lore_slug' => 'silbon',
            ])
            ->assertSessionHasErrors('lore_slug');

        $this->assertSame(0, Story::query()->count());
        Bus::assertNothingDispatched();
    }

    public function test_only_script_true_queues_the_job_without_chaining(): void
    {
        $this->post(route('stories.store'), [
            'mode' => StoryMode::Original->value,
            'only_script' => true,
        ])->assertRedirect();

        Bus::assertDispatched(
            RunPipelineStep::class,
            static fn (RunPipelineStep $job): bool => $job->step === 'script' && $job->chain === false,
        );
    }

    public function test_only_script_false_queues_the_job_with_chaining(): void
    {
        $this->post(route('stories.store'), [
            'mode' => StoryMode::Original->value,
            'only_script' => false,
        ])->assertRedirect();

        Bus::assertDispatched(
            RunPipelineStep::class,
            static fn (RunPipelineStep $job): bool => $job->step === 'script' && $job->chain === true,
        );
    }

    public function test_a_premise_longer_than_500_characters_fails_validation(): void
    {
        $this->from(route('stories.create'))
            ->post(route('stories.store'), [
                'mode' => StoryMode::Original->value,
                'premise' => str_repeat('a', 501),
            ])
            ->assertSessionHasErrors('premise');

        $this->assertSame(0, Story::query()->count());
        Bus::assertNothingDispatched();
    }

    public function test_the_create_page_lists_twenty_nine_creatures_with_usage(): void
    {
        Story::factory()->count(2)->create([
            'lore_slug' => 'silbon',
            'lore_name' => 'El Silbón',
        ]);

        $this->get(route('stories.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page): Assert => $page
                ->component('NewStory')
                ->has('creatures', 29)
                ->where(
                    'creatures',
                    fn (mixed $creatures): bool => $this->usedCount($creatures, 'silbon') === 2,
                )
            );
    }

    private function usedCount(mixed $creatures, string $slug): int
    {
        if ($creatures instanceof Collection) {
            $creatures = $creatures->all();
        }

        $this->assertIsArray($creatures);

        foreach ($creatures as $creature) {
            $this->assertIsArray($creature);

            if (($creature['slug'] ?? null) === $slug) {
                return (int) $creature['usedCount'];
            }
        }

        $this->fail("No apareció la criatura {$slug}.");
    }
}
