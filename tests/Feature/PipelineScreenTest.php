<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\StoryStatus;
use App\Models\Story;
use App\Services\Pipeline\PipelineDispatcher;
use App\Services\Pipeline\PipelineProgress;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class PipelineScreenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->travelTo(Carbon::parse('2026-08-30 12:00:00'));
    }

    public function test_active_contains_only_draft_and_narrated_when_the_rest_are_inactive(): void
    {
        $draft = Story::factory()->create([
            'title' => 'Borrador',
            'status' => StoryStatus::Draft,
            'updated_at' => now()->subMinutes(1),
        ]);
        $narrated = Story::factory()->create([
            'title' => 'Narrada',
            'status' => StoryStatus::Narrated,
            'updated_at' => now()->subMinutes(2),
        ]);
        Story::factory()->create([
            'title' => 'Pendiente',
            'status' => StoryStatus::PendingReview,
            'updated_at' => now()->subMinutes(3),
        ]);
        Story::factory()->create([
            'title' => 'Publicada',
            'status' => StoryStatus::Published,
            'updated_at' => now()->subMinutes(4),
        ]);

        $this->getJson(route('pipeline.state'))
            ->assertOk()
            ->assertJsonPath('active.0.id', $draft->id)
            ->assertJsonPath('active.1.id', $narrated->id)
            ->assertJsonCount(2, 'active');
    }

    public function test_mixed_story_uses_row_six_as_current_row(): void
    {
        $story = Story::factory()->create([
            'title' => 'Mezclada',
            'status' => StoryStatus::Mixed,
        ]);

        $this->getJson(route('pipeline.state', ['story' => $story->id]))
            ->assertOk()
            ->assertJsonPath('selected.story.id', $story->id)
            ->assertJsonPath('selected.story.status', StoryStatus::Mixed->value)
            ->assertJsonPath('active.0.currentRow', 6);
    }

    public function test_images_ready_story_uses_row_six_because_images_are_done_and_sound_is_next(): void
    {
        $story = Story::factory()->create([
            'title' => 'Imágenes listas',
            'status' => StoryStatus::ImagesReady,
        ]);

        $this->getJson(route('pipeline.state', ['story' => $story->id]))
            ->assertOk()
            ->assertJsonPath('selected.story.id', $story->id)
            ->assertJsonPath('active.0.currentRow', 6);
    }

    public function test_narrated_story_with_plan_progress_marks_row_four_running_and_row_five_waiting(): void
    {
        $story = Story::factory()->create([
            'title' => 'Narrada',
            'status' => StoryStatus::Narrated,
        ]);

        $this->app->make(PipelineProgress::class)
            ->put($story->id, 'images', 'planificación', 4, 10, 'plan');

        $this->getJson(route('pipeline.state', ['story' => $story->id]))
            ->assertOk()
            ->assertJsonPath('selected.rows.3.state', 'en curso')
            ->assertJsonPath('selected.rows.4.state', 'en espera');
    }

    public function test_narrated_story_with_direct_progress_marks_row_four_done_and_row_five_running(): void
    {
        $story = Story::factory()->create([
            'title' => 'Narrada',
            'status' => StoryStatus::Narrated,
        ]);

        $this->app->make(PipelineProgress::class)
            ->put($story->id, 'images', 'dirección', 4, 10, 'direct');

        $this->getJson(route('pipeline.state', ['story' => $story->id]))
            ->assertOk()
            ->assertJsonPath('selected.rows.3.state', 'hecho')
            ->assertJsonPath('selected.rows.4.state', 'en curso');
    }

    public function test_a_queued_step_reads_as_en_cola_and_never_as_en_curso(): void
    {
        Queue::fake();

        $story = Story::factory()->create([
            'title' => 'Encolada',
            'status' => StoryStatus::ScriptReady,
        ]);

        $this->app->make(PipelineDispatcher::class)->advance($story);

        $response = $this->getJson(route('pipeline.state', ['story' => $story->id]))
            ->assertOk()
            ->assertJsonPath('selected.rows.2.state', 'en cola')
            // A step that has not begun reports no clock and no unit of its own.
            ->assertJsonPath('selected.rows.2.time', '—')
            ->assertJsonPath('selected.rows.2.unit', '—');

        $states = array_column($response->json('selected.rows'), 'state');
        $this->assertNotContains('en curso', $states);
    }

    public function test_a_story_with_no_job_at_all_has_no_row_in_progress(): void
    {
        $story = Story::factory()->create([
            'title' => 'Parada',
            'status' => StoryStatus::ScriptReady,
        ]);

        $response = $this->getJson(route('pipeline.state', ['story' => $story->id]))->assertOk();
        $states = array_column($response->json('selected.rows'), 'state');

        $this->assertNotContains('en curso', $states);
        $this->assertNotContains('en cola', $states);
        $this->assertSame('en espera', $states[2]);
    }

    public function test_only_the_story_a_worker_picked_up_shows_a_row_en_curso(): void
    {
        Queue::fake();

        $running = Story::factory()->create(['title' => 'Corriendo', 'status' => StoryStatus::Narrated]);
        $waiting = Story::factory()->create(['title' => 'Esperando', 'status' => StoryStatus::ScriptReady]);

        $this->app->make(PipelineProgress::class)->put($running->id, 'images', 'planificación', 4, 10, 'plan');
        $this->app->make(PipelineDispatcher::class)->advance($waiting);

        $this->getJson(route('pipeline.state', ['story' => $running->id]))
            ->assertOk()
            ->assertJsonPath('selected.rows.3.state', 'en curso');

        $states = array_column(
            $this->getJson(route('pipeline.state', ['story' => $waiting->id]))->json('selected.rows'),
            'state',
        );
        $this->assertNotContains('en curso', $states);
    }

    public function test_the_running_row_times_the_work_and_not_the_wait_that_preceded_it(): void
    {
        $story = Story::factory()->create([
            'title' => 'Rescatada',
            'status' => StoryStatus::Narrated,
        ]);

        $event = $story->events()->create([
            'type' => 'status_changed',
            'from_status' => StoryStatus::ScriptReady->value,
            'to_status' => StoryStatus::Narrated->value,
        ]);
        // The story sat here for two days because no worker was alive.
        $event->forceFill(['created_at' => now()->subHours(45)])->save();

        $this->app->make(PipelineProgress::class)
            ->put($story->id, 'images', 'planificación', 1, 10, 'plan');

        $this->travel(35)->minutes();

        $this->getJson(route('pipeline.state', ['story' => $story->id]))
            ->assertOk()
            ->assertJsonPath('selected.rows.3.state', 'en curso')
            ->assertJsonPath('selected.rows.3.time', '35:00');
    }

    public function test_the_running_clock_survives_later_progress_ticks(): void
    {
        $story = Story::factory()->create([
            'title' => 'Con ticks',
            'status' => StoryStatus::Narrated,
        ]);

        $progress = $this->app->make(PipelineProgress::class);
        $progress->put($story->id, 'images', 'planificación', 1, 10, 'plan');

        $this->travel(10)->minutes();
        $progress->put($story->id, 'images', 'planificación', 6, 10, 'plan');

        $this->getJson(route('pipeline.state', ['story' => $story->id]))
            ->assertOk()
            ->assertJsonPath('selected.rows.3.time', '10:00');
    }

    public function test_clocks_past_an_hour_are_written_with_an_hours_field(): void
    {
        $story = Story::factory()->create([
            'title' => 'Larga',
            'status' => StoryStatus::ScriptReady,
            'created_at' => now()->subHours(50),
        ]);

        $event = $story->events()->create(['type' => 'created', 'to_status' => StoryStatus::Draft->value]);
        $event->forceFill(['created_at' => now()->subHours(50)->subMinutes(41)->subSeconds(12)])->save();

        $this->getJson(route('pipeline.state', ['story' => $story->id]))
            ->assertOk()
            ->assertJsonPath('selected.elapsed', '50:41:12');
    }

    public function test_failed_story_with_sound_step_marks_the_failed_row_and_keeps_later_rows_waiting(): void
    {
        $story = Story::factory()->create([
            'title' => 'Fallida',
            'status' => StoryStatus::Failed,
            'failed_step' => 'sound',
            'failed_message' => 'Fallo de mezcla.',
        ]);

        $this->getJson(route('pipeline.state', ['story' => $story->id]))
            ->assertOk()
            ->assertJsonPath('selected.rows.5.state', 'fallido')
            ->assertJsonPath('selected.rows.5.error', 'Fallo de mezcla.')
            ->assertJsonPath('selected.rows.0.state', 'hecho')
            ->assertJsonPath('selected.rows.1.state', 'hecho')
            ->assertJsonPath('selected.rows.6.state', 'en espera');
    }

    public function test_rows_always_contain_exactly_seven_entries_for_any_state(): void
    {
        $story = Story::factory()->create([
            'title' => 'Con filas',
            'status' => StoryStatus::Draft,
        ]);

        $this->getJson(route('pipeline.state', ['story' => $story->id]))
            ->assertOk()
            ->assertJsonCount(7, 'selected.rows');

        $story->forceFill(['status' => StoryStatus::Failed, 'failed_step' => 'sound'])->save();

        $this->getJson(route('pipeline.state', ['story' => $story->id]))
            ->assertOk()
            ->assertJsonCount(7, 'selected.rows');
    }

    public function test_explicit_story_query_selects_the_requested_active_story(): void
    {
        $older = Story::factory()->create([
            'title' => 'Vieja',
            'status' => StoryStatus::Narrated,
            'updated_at' => now()->subHour(),
        ]);
        $newer = Story::factory()->create([
            'title' => 'Nueva',
            'status' => StoryStatus::Draft,
            'updated_at' => now(),
        ]);

        $this->getJson(route('pipeline.state', ['story' => $older->id]))
            ->assertOk()
            ->assertJsonPath('selected.story.id', $older->id)
            ->assertJsonPath('active.0.id', $newer->id);
    }

    public function test_invalid_story_query_falls_back_to_the_first_active_story_without_error(): void
    {
        Story::factory()->create([
            'title' => 'Primera',
            'status' => StoryStatus::Draft,
            'updated_at' => now()->subMinutes(2),
        ]);
        $second = Story::factory()->create([
            'title' => 'Segunda',
            'status' => StoryStatus::Narrated,
            'updated_at' => now()->subMinutes(1),
        ]);

        $this->getJson(route('pipeline.state', ['story' => 999999]))
            ->assertOk()
            ->assertJsonPath('selected.story.id', $second->id);
    }

    public function test_null_selected_when_no_story_is_active_and_response_is_still_200(): void
    {
        Story::factory()->create([
            'title' => 'Pendiente',
            'status' => StoryStatus::PendingReview,
        ]);
        Story::factory()->create([
            'title' => 'Publicada',
            'status' => StoryStatus::Published,
        ]);

        $this->getJson(route('pipeline.state'))
            ->assertOk()
            ->assertJsonPath('active', [])
            ->assertJsonPath('selected', null);
    }

    public function test_row_time_is_counted_from_story_event_timestamps_in_minutes_and_seconds(): void
    {
        $story = Story::factory()->create([
            'title' => 'Cronometrada',
            'status' => StoryStatus::ScriptReady,
            'created_at' => now()->subMinutes(10),
        ]);

        $created = $story->events()->create([
            'type' => 'created',
            'to_status' => StoryStatus::Draft->value,
        ]);
        $created->forceFill(['created_at' => now()->subMinutes(10)])->save();

        $changed = $story->events()->create([
            'type' => 'status_changed',
            'from_status' => StoryStatus::Draft->value,
            'to_status' => StoryStatus::ScriptReady->value,
        ]);
        $changed->forceFill(['created_at' => now()->subMinutes(8)->subSeconds(20)])->save();

        $this->getJson(route('pipeline.state', ['story' => $story->id]))
            ->assertOk()
            ->assertJsonPath('selected.rows.0.time', '01:40')
            ->assertJsonPath('selected.rows.1.time', '01:40');
    }

    public function test_elapsed_is_the_difference_between_the_first_event_and_now(): void
    {
        $story = Story::factory()->create([
            'title' => 'Elapsed',
            'status' => StoryStatus::ScriptReady,
            'created_at' => now()->subMinutes(5),
        ]);

        $first = $story->events()->create([
            'type' => 'created',
            'to_status' => StoryStatus::Draft->value,
        ]);
        $first->forceFill(['created_at' => now()->subMinutes(5)])->save();

        $this->getJson(route('pipeline.state', ['story' => $story->id]))
            ->assertOk()
            ->assertJsonPath('selected.elapsed', '05:00');
    }
}
