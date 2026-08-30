<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ReviewVerdict;
use App\Enums\StoryMode;
use App\Enums\StoryStatus;
use App\Models\Story;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class QueueControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->travelTo(Carbon::parse('2026-08-15 12:00:00'));
    }

    public function test_attention_holds_only_pending_review_and_rest_excludes_discarded(): void
    {
        $olderPending = Story::factory()->create([
            'title' => 'La más antigua',
            'status' => StoryStatus::PendingReview,
            'updated_at' => now()->subHours(3),
        ]);
        $newerPending = Story::factory()->create([
            'title' => 'La más reciente',
            'status' => StoryStatus::PendingReview,
            'updated_at' => now()->subHour(),
        ]);
        $discarded = Story::factory()->create([
            'status' => StoryStatus::Discarded,
            'updated_at' => now()->subMinutes(10),
        ]);
        $draft = Story::factory()->create([
            'title' => '',
            'status' => StoryStatus::Draft,
            'master_seconds' => null,
            'updated_at' => now()->subMinutes(5),
        ]);
        $ready = Story::factory()->create([
            'status' => StoryStatus::ReadyToPublish,
            'updated_at' => now()->subMinutes(2),
        ]);

        $this->get(route('queue'))
            ->assertOk()
            ->assertInertia(fn (Assert $page): Assert => $page
                ->component('Queue')
                ->has('attention', 2)
                ->where('attention.0.id', $olderPending->id)
                ->where('attention.1.id', $newerPending->id)
                ->has('rest', 2)
                ->where('rest.0.id', $ready->id)
                ->where('rest.1.id', $draft->id)
                ->has('queue.likelyNoWorker')
            );
    }

    public function test_rows_map_short_keys_and_format_duration_and_date(): void
    {
        $pending = Story::factory()->create([
            'slug' => '2026-08-15-la-cadena-bajo-el-ingenio',
            'title' => 'La cadena bajo el ingenio',
            'mode' => StoryMode::Folklore,
            'lore_slug' => 'el-silbon',
            'lore_name' => 'El Silbón',
            'status' => StoryStatus::PendingReview,
            'verdict' => ReviewVerdict::Publish,
            'score' => 8.2,
            'master_seconds' => 662.4,
            'updated_at' => Carbon::parse('2026-08-30 09:15:00'),
        ]);
        Story::factory()->create([
            'lore_slug' => 'el-silbon',
            'lore_name' => 'El Silbón',
            'status' => StoryStatus::Discarded,
        ]);
        $draft = Story::factory()->create([
            'title' => '',
            'mode' => StoryMode::Original,
            'lore_slug' => null,
            'lore_name' => null,
            'status' => StoryStatus::Draft,
            'verdict' => null,
            'score' => null,
            'master_seconds' => null,
            'updated_at' => Carbon::parse('2026-08-30 11:00:00'),
        ]);

        $this->get(route('queue'))
            ->assertOk()
            ->assertInertia(fn (Assert $page): Assert => $page
                ->component('Queue')
                ->where('attention.0.id', $pending->id)
                ->where('attention.0.slug', '2026-08-15-la-cadena-bajo-el-ingenio')
                ->where('attention.0.t', 'La cadena bajo el ingenio')
                ->where('attention.0.mode', 'Folclore')
                ->where('attention.0.cr', 'El Silbón')
                ->where('attention.0.dur', '11:02')
                ->where('attention.0.st', 'pendiente de revisión')
                ->where('attention.0.stColor', StoryStatus::PendingReview->color())
                ->where('attention.0.v', 'publish')
                ->where('attention.0.sc', 8.2)
                ->where('attention.0.d', '30 ago')
                ->where('attention.0.usedCount', 2)
                ->where('attention.0.href', route('review.show', $pending))
                ->where('attention.0.tone', crc32('2026-08-15-la-cadena-bajo-el-ingenio') % 256)
                ->where('rest.0.id', $draft->id)
                ->where('rest.0.t', '')
                ->where('rest.0.mode', 'Original')
                ->where('rest.0.cr', '—')
                ->where('rest.0.dur', '—')
                ->where('rest.0.st', StoryStatus::Draft->label())
                ->where('rest.0.v', '—')
                ->where('rest.0.sc', 0)
                ->where('rest.0.d', '30 ago')
                ->where('rest.0.usedCount', 0)
                ->where('rest.0.href', route('pipeline.show', $draft))
            );
    }

    public function test_tone_is_stable_across_two_requests(): void
    {
        $story = Story::factory()->create([
            'slug' => '2026-08-15-tono-estable',
            'status' => StoryStatus::PendingReview,
        ]);

        $expected = crc32($story->slug) % 256;

        $this->get(route('queue'))
            ->assertOk()
            ->assertInertia(fn (Assert $page): Assert => $page
                ->where('attention.0.tone', $expected)
            );

        $this->get(route('queue'))
            ->assertOk()
            ->assertInertia(fn (Assert $page): Assert => $page
                ->where('attention.0.tone', $expected)
            );
    }

    public function test_stats_count_statuses_and_format_monthly_spend(): void
    {
        Story::factory()->create(['status' => StoryStatus::PendingReview]);
        Story::factory()->create(['status' => StoryStatus::PendingReview]);
        Story::factory()->create(['status' => StoryStatus::ReadyToPublish]);
        Story::factory()->create([
            'status' => StoryStatus::Published,
            'published_at' => now(),
            'llm_cost_usd' => 10.5,
            'used_fallback' => true,
        ]);
        Story::factory()->create([
            'status' => StoryStatus::Published,
            'published_at' => now()->subMonth(),
            'llm_cost_usd' => 4.32,
            'used_fallback' => false,
            'created_at' => now()->subMonth(),
        ]);
        Story::factory()->create([
            'status' => StoryStatus::Draft,
            'llm_cost_usd' => 4.32,
            'used_fallback' => false,
        ]);

        $this->get(route('queue'))
            ->assertOk()
            ->assertInertia(fn (Assert $page): Assert => $page
                ->component('Queue')
                ->where('stats.0.label', 'Pendientes de revisión')
                ->where('stats.0.value', 2)
                ->where('stats.0.note', 'reclaman a una persona')
                ->where('stats.1.label', 'Listas sin descargar')
                ->where('stats.1.value', 1)
                ->where('stats.1.note', 'aprobadas, aún en el disco')
                ->where('stats.2.label', 'Publicadas este mes')
                ->where('stats.2.value', 1)
                ->where('stats.2.note', 'agosto')
                ->where('stats.3.label', 'Gasto del mes')
                ->where('stats.3.value', '13,63 €')
                ->where('stats.3.note', 'respaldo Claude Haiku')
                ->where('stats.3.title', '0 llamadas · 0 tokens de entrada · 0 de salida · 14,82 $')
                ->where('monthlySpend', '13,63 €')
            );
    }

    public function test_llm_spend_lists_stories_and_steps_by_cost(): void
    {
        $expensive = Story::factory()->create([
            'title' => 'La más cara',
            'llm_cost_usd' => 2.0,
            'llm_input_tokens' => 1000,
            'llm_output_tokens' => 200,
        ]);
        $expensive->events()->create([
            'type' => 'llm_usage',
            'payload' => [
                'step' => 'script',
                'calls' => 2,
                'inputTokens' => 1000,
                'outputTokens' => 200,
                'costUsd' => 2.0,
                'byProvider' => ['anthropic' => ['calls' => 2, 'inputTokens' => 1000, 'outputTokens' => 200, 'costUsd' => 2.0]],
            ],
        ]);
        $cheap = Story::factory()->create([
            'title' => 'La barata',
            'llm_cost_usd' => 0.5,
        ]);
        $cheap->events()->create([
            'type' => 'llm_usage',
            'payload' => [
                'step' => 'images',
                'calls' => 1,
                'inputTokens' => 100,
                'outputTokens' => 20,
                'costUsd' => 0.5,
                'byProvider' => [],
            ],
        ]);

        $this->artisan('llm:spend', ['--month' => '2026-08'])
            ->expectsTable(
                ['Historia', 'Paso', 'Llamadas', 'Entrada', 'Salida', 'USD', 'EUR'],
                [
                    ['La más cara', 'guion', '2', '1.000', '200', '2,00 $', '1,84 €'],
                    ['La barata', 'imágenes', '1', '100', '20', '0,50 $', '0,46 €'],
                ],
            )
            ->expectsOutputToContain('Total:')
            ->assertSuccessful();
    }

    public function test_llm_spend_rejects_invalid_month(): void
    {
        $this->artisan('llm:spend', ['--month' => 'agosto'])
            ->expectsOutputToContain('YYYY-MM')
            ->assertFailed();
    }

    public function test_spend_note_is_sin_respaldo_when_no_story_used_fallback(): void
    {
        Story::factory()->create([
            'llm_cost_usd' => 1.2,
            'used_fallback' => false,
        ]);

        $this->get(route('queue'))
            ->assertOk()
            ->assertInertia(fn (Assert $page): Assert => $page
                ->where('stats.3.note', 'sin respaldo')
            );
    }
}
