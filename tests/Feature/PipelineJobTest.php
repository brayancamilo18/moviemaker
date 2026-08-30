<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\StoryStatus;
use App\Jobs\RunPipelineStep;
use App\Models\Story;
use App\Models\StoryEvent;
use App\Services\Pipeline\PipelineDispatcher;
use App\Services\Pipeline\PipelineProgress;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;
use Throwable;

final class PipelineJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_step_exception_marks_the_story_failed_and_records_the_step(): void
    {
        $story = $this->runFailingStep();

        $this->assertSame(StoryStatus::Failed, $story->status);
        $this->assertSame('images', $story->failed_step);
        $this->assertNotNull($story->failed_message);
        $this->assertNotSame('', $story->failed_message);

        $event = $story->events()->where('type', 'step_failed')->first();

        $this->assertInstanceOf(StoryEvent::class, $event);
        $this->assertSame(StoryStatus::Draft->value, $event->from_status);
        $this->assertSame(StoryStatus::Failed->value, $event->to_status);
        $this->assertSame('images', $event->payload['step'] ?? null);
    }

    public function test_a_failed_step_is_not_retried(): void
    {
        $job = new RunPipelineStep(0, 'images');

        $this->assertSame(1, $job->tries);

        $story = $this->runFailingStep();

        $this->assertSame(1, $story->events()->where('type', 'step_failed')->count());
    }

    public function test_progress_stores_and_returns_label_done_and_total(): void
    {
        $progress = $this->app->make(PipelineProgress::class);

        $progress->put(17, 'images', 'plano 3', 3, 10);

        $this->assertSame(
            [
                'step' => 'images',
                'label' => 'plano 3',
                'done' => 3,
                'total' => 10,
            ],
            $progress->get(17),
        );
    }

    public function test_advance_from_narrated_queues_the_images_step(): void
    {
        Bus::fake();

        $story = Story::factory()->create(['status' => StoryStatus::Narrated]);

        $this->app->make(PipelineDispatcher::class)->advance($story);

        Bus::assertDispatched(
            RunPipelineStep::class,
            static fn (RunPipelineStep $job): bool => $job->storyId === $story->id && $job->step === 'images',
        );
    }

    private function runFailingStep(): Story
    {
        $story = Story::factory()->create(['status' => StoryStatus::Draft]);

        try {
            $this->app->make(Dispatcher::class)->dispatch(new RunPipelineStep($story->id, 'images'));
        } catch (Throwable) {
            // El driver sync relanza tras failed(); el aserto es el estado de la historia.
        }

        $fresh = $story->fresh();

        $this->assertInstanceOf(Story::class, $fresh);

        return $fresh;
    }
}
