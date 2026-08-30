<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\StoryStatus;
use App\Exceptions\InvalidStoryTransition;
use App\Models\Story;
use App\Models\StoryEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class StoryStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_draft_may_become_script_ready(): void
    {
        $story = Story::factory()->create(['status' => StoryStatus::Draft]);

        $story->transitionTo(StoryStatus::ScriptReady);

        $this->assertSame(StoryStatus::ScriptReady, $story->fresh()?->status);
    }

    public function test_draft_cannot_jump_to_published(): void
    {
        $story = Story::factory()->create(['status' => StoryStatus::Draft]);

        $this->expectException(InvalidStoryTransition::class);

        $story->transitionTo(StoryStatus::Published);
    }

    public function test_a_valid_transition_records_a_status_changed_event(): void
    {
        $story = Story::factory()->create(['status' => StoryStatus::Draft]);

        $story->transitionTo(StoryStatus::ScriptReady);

        $event = $story->events()->where('type', 'status_changed')->first();

        $this->assertInstanceOf(StoryEvent::class, $event);
        $this->assertSame(StoryStatus::Draft->value, $event->from_status);
        $this->assertSame(StoryStatus::ScriptReady->value, $event->to_status);
    }

    public function test_published_admits_no_transitions(): void
    {
        $this->assertTerminal(StoryStatus::Published);
    }

    public function test_discarded_admits_no_transitions(): void
    {
        $this->assertTerminal(StoryStatus::Discarded);
    }

    public function test_failed_may_return_to_pipeline_steps(): void
    {
        foreach ([
            StoryStatus::Narrated,
            StoryStatus::ImagesReady,
            StoryStatus::Mixed,
            StoryStatus::Rendered,
        ] as $next) {
            $story = Story::factory()->create(['status' => StoryStatus::Failed]);

            $story->transitionTo($next);

            $this->assertSame($next, $story->fresh()?->status);
        }
    }

    public function test_pending_review_may_go_back_to_script_ready(): void
    {
        $story = Story::factory()->create(['status' => StoryStatus::PendingReview]);

        $story->transitionTo(StoryStatus::ScriptReady);

        $this->assertSame(StoryStatus::ScriptReady, $story->fresh()?->status);
    }

    public function test_pending_review_cannot_jump_to_published(): void
    {
        $story = Story::factory()->create(['status' => StoryStatus::PendingReview]);

        $this->expectException(InvalidStoryTransition::class);

        $story->transitionTo(StoryStatus::Published);
    }

    private function assertTerminal(StoryStatus $status): void
    {
        $story = Story::factory()->create(['status' => $status]);

        foreach (StoryStatus::cases() as $next) {
            $this->assertFalse(
                $status->canTransitionTo($next),
                "«{$status->value}» no debería poder pasar a «{$next->value}».",
            );

            try {
                $story->transitionTo($next);
                $this->fail("«{$status->value}» aceptó la transición a «{$next->value}».");
            } catch (InvalidStoryTransition) {
                $this->assertSame($status, $story->fresh()?->status);
            }
        }
    }
}
