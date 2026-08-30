<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\StoryStatus;
use App\Models\Story;
use App\Services\Pipeline\PipelineProgress;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class PipelineControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->travelTo(Carbon::parse('2026-08-30 12:00:00'));
    }

    public function test_index_lists_only_active_stories_newest_first(): void
    {
        $failed = Story::factory()->create([
            'title' => 'La fallida',
            'status' => StoryStatus::Failed,
            'failed_step' => 'images',
            'updated_at' => now()->subMinutes(2),
        ]);
        $draft = Story::factory()->create([
            'title' => '',
            'status' => StoryStatus::Draft,
            'updated_at' => now()->subMinute(),
        ]);
        Story::factory()->create(['status' => StoryStatus::PendingReview]);
        Story::factory()->create(['status' => StoryStatus::Published]);
        Story::factory()->create(['status' => StoryStatus::Discarded]);

        $this->get(route('pipeline'))
            ->assertOk()
            ->assertInertia(fn (Assert $page): Assert => $page
                ->component('Pipeline')
                ->has('active', 2)
                ->where('active.0.id', $draft->id)
                ->where('active.0.title', 'Sin título')
                ->where('active.0.currentRow', 1)
                ->where('active.0.failed', false)
                ->where('active.0.tone', crc32($draft->slug) % 256)
                ->where('active.1.id', $failed->id)
                ->where('active.1.failed', true)
                ->where('active.1.currentRow', 4)
                ->where('selected.story.id', $draft->id)
                ->where('selected.rows.0.num', '01')
                ->where('selected.rows.6.num', '07')
                ->has('selected.rows', 7)
                ->where('queue.likelyNoWorker', false)
            );
    }

    public function test_index_selects_the_requested_active_story(): void
    {
        $older = Story::factory()->create([
            'status' => StoryStatus::Narrated,
            'updated_at' => now()->subHour(),
        ]);
        Story::factory()->create([
            'status' => StoryStatus::Draft,
            'updated_at' => now(),
        ]);

        $this->get(route('pipeline', ['story' => $older->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page): Assert => $page
                ->where('selected.story.id', $older->id)
                ->where('selected.story.status', StoryStatus::Narrated->value)
            );
    }

    public function test_index_has_null_selected_when_nothing_is_active(): void
    {
        Story::factory()->create(['status' => StoryStatus::ReadyToPublish]);

        $this->get(route('pipeline'))
            ->assertOk()
            ->assertInertia(fn (Assert $page): Assert => $page
                ->where('active', [])
                ->where('selected', null)
            );
    }

    public function test_show_forces_selected_even_when_the_story_is_not_active(): void
    {
        $published = Story::factory()->create([
            'status' => StoryStatus::Published,
            'scene_count' => 12,
            'score' => 8.0,
            'sentence_count' => 40,
            'shot_count' => 14,
            'effect_count' => 9,
            'lufs' => -14.0,
            'video_seconds' => 662.4,
        ]);
        $draft = Story::factory()->create(['status' => StoryStatus::Draft]);

        $this->get(route('pipeline.show', $published))
            ->assertOk()
            ->assertInertia(fn (Assert $page): Assert => $page
                ->where('selected.story.id', $published->id)
                ->where('active.0.id', $draft->id)
                ->where('selected.rows.0.state', 'hecho')
                ->where('selected.rows.0.unit', '12 escenas')
                ->where('selected.rows.1.unit', '8 / 10')
                ->where('selected.rows.2.unit', '40 frases')
                ->where('selected.rows.3.unit', '14 planos')
                ->where('selected.rows.4.unit', '14 imágenes')
                ->where('selected.rows.5.unit', '9 efectos · -14 LUFS')
                ->where('selected.rows.6.unit', '11:02')
                ->where('selected.rows.6.state', 'hecho')
            );
    }

    public function test_running_images_direct_marks_plan_done_and_uses_progress_units(): void
    {
        $story = Story::factory()->create(['status' => StoryStatus::Narrated]);
        $this->app->make(PipelineProgress::class)
            ->put($story->id, 'images', 'plano 3', 3, 10, 'direct');

        $this->get(route('pipeline.show', $story))
            ->assertOk()
            ->assertInertia(fn (Assert $page): Assert => $page
                ->where('selected.rows.3.state', 'hecho')
                ->where('selected.rows.4.state', 'en curso')
                ->where('selected.rows.4.progress', 0.3)
                ->where('selected.rows.4.unit', '3 / 10 imágenes')
                ->where('selected.rows.5.state', 'en espera')
                ->where('selected.rows.5.unit', '—')
                ->where('active.0.currentRow', 5)
            );
    }

    public function test_script_review_stage_marks_generate_done(): void
    {
        $story = Story::factory()->create(['status' => StoryStatus::Draft]);
        $this->app->make(PipelineProgress::class)
            ->put($story->id, 'script', 'revisión', 0, 1, 'review');

        $this->get(route('pipeline', ['story' => $story->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page): Assert => $page
                ->where('selected.rows.0.state', 'hecho')
                ->where('selected.rows.1.state', 'en curso')
                ->where('selected.rows.1.unit', '0 / 1 puntos')
                ->where('active.0.currentRow', 2)
            );
    }

    public function test_selected_exposes_real_fallback_cost_and_tokens(): void
    {
        $story = Story::factory()->create([
            'status' => StoryStatus::Draft,
            'used_fallback' => true,
            'llm_cost_usd' => 2.41,
            'llm_input_tokens' => 1_500_000,
            'llm_output_tokens' => 340_000,
        ]);

        $this->get(route('pipeline.show', $story))
            ->assertOk()
            ->assertInertia(fn (Assert $page): Assert => $page
                ->where('selected.story.used_fallback', true)
                ->where('selected.backupCost', '2,22 €')
                ->where('selected.backupTokens', '1,84 M tokens · Haiku')
            );
    }

    public function test_failed_row_exposes_the_message_and_keeps_later_rows_waiting(): void
    {
        $story = Story::factory()->create([
            'status' => StoryStatus::Failed,
            'failed_step' => 'narration',
            'failed_message' => 'Kokoro no responde.',
            'scene_count' => 11,
            'score' => 7.5,
        ]);

        $this->get(route('pipeline.show', $story))
            ->assertOk()
            ->assertInertia(fn (Assert $page): Assert => $page
                ->where('selected.rows.0.state', 'hecho')
                ->where('selected.rows.1.state', 'hecho')
                ->where('selected.rows.2.state', 'fallido')
                ->where('selected.rows.2.error', 'Kokoro no responde.')
                ->where('selected.rows.2.unit', '—')
                ->where('selected.rows.3.state', 'en espera')
                ->where('selected.rows.0.error', null)
            );
    }

    public function test_row_time_uses_status_changed_events_and_elapsed_starts_at_the_first_event(): void
    {
        $story = Story::factory()->create([
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
        $changed->forceFill(['created_at' => now()->subMinutes(3)])->save();

        $this->get(route('pipeline.show', $story->fresh()))
            ->assertOk()
            ->assertInertia(fn (Assert $page): Assert => $page
                ->where('selected.rows.0.time', '07:00')
                ->where('selected.rows.1.time', '07:00')
                ->where('selected.rows.2.time', '03:00')
                ->where('selected.rows.3.time', '—')
                ->where('selected.elapsed', '10:00')
            );
    }
}
