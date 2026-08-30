<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\RunPipelineStep;
use App\Models\Story;
use App\Models\StoryEvent;
use App\Services\Llm\AnthropicClient;
use App\Services\Llm\LlmTask;
use App\Services\Llm\LlmUsageMeter;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;
use Throwable;

final class LlmUsageMeterTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_haiku_call_costs_exactly_two_cents(): void
    {
        $meter = $this->app->make(LlmUsageMeter::class);
        $meter->record('anthropic', 'claude-haiku-4-5', LlmTask::Script, 10_000, 2_000);

        $this->assertSame(0.02, $meter->summary()['costUsd']);
    }

    public function test_a_model_without_its_own_rate_uses_the_provider_default(): void
    {
        $this->app->make('config')->set('stories.llm.pricing.anthropic', [
            'claude-haiku-4-5' => ['input' => 1.00, 'output' => 5.00],
            'default' => ['input' => 2.00, 'output' => 8.00],
        ]);

        $meter = new LlmUsageMeter($this->app->make('config'));
        $meter->record('anthropic', 'claude-sonnet-4', LlmTask::Review, 1_000_000, 1_000_000);

        $this->assertSame(10.0, $meter->summary()['costUsd']);
    }

    public function test_gemini_at_zero_tariff_costs_nothing_but_still_counts_tokens(): void
    {
        $meter = $this->app->make(LlmUsageMeter::class);
        $meter->record('gemini', 'gemini-3.6-flash', LlmTask::Script, 10_000, 2_000);

        $summary = $meter->summary();

        $this->assertSame(0.0, $summary['costUsd']);
        $this->assertSame(10_000, $summary['inputTokens']);
        $this->assertSame(2_000, $summary['outputTokens']);
        $this->assertSame(1, $summary['calls']);
    }

    public function test_summary_aggregates_three_calls_from_two_providers(): void
    {
        $meter = $this->app->make(LlmUsageMeter::class);
        $meter->record('anthropic', 'claude-haiku-4-5', LlmTask::Script, 10_000, 2_000);
        $meter->record('anthropic', 'claude-haiku-4-5', LlmTask::Review, 1_000, 400);
        $meter->record('gemini', 'gemini-3.6-flash', LlmTask::VisualBible, 5_000, 1_000);

        $summary = $meter->summary();

        $this->assertSame(3, $summary['calls']);
        $this->assertSame(16_000, $summary['inputTokens']);
        $this->assertSame(3_400, $summary['outputTokens']);
        $this->assertEqualsWithDelta(0.023, $summary['costUsd'], 0.000001);
        $this->assertSame(2, $summary['byProvider']['anthropic']['calls']);
        $this->assertSame(1, $summary['byProvider']['gemini']['calls']);
        $this->assertSame(11_000, $summary['byProvider']['anthropic']['inputTokens']);
        $this->assertSame(5_000, $summary['byProvider']['gemini']['inputTokens']);
        $this->assertSame(0.0, $summary['byProvider']['gemini']['costUsd']);
    }

    public function test_reset_empties_the_accumulator(): void
    {
        $meter = $this->app->make(LlmUsageMeter::class);
        $meter->record('anthropic', 'claude-haiku-4-5', LlmTask::Script, 10_000, 2_000);
        $meter->reset();

        $summary = $meter->summary();

        $this->assertSame(0, $summary['calls']);
        $this->assertSame(0, $summary['inputTokens']);
        $this->assertSame(0, $summary['outputTokens']);
        $this->assertSame(0.0, $summary['costUsd']);
        $this->assertSame([], $summary['byProvider']);
    }

    public function test_a_failure_while_recording_usage_does_not_propagate(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'stop_reason' => 'end_turn',
                'content' => [
                    ['type' => 'text', 'text' => '{"ok":true}'],
                ],
                'usage' => [
                    'input_tokens' => 10_000,
                    'output_tokens' => 2_000,
                ],
            ], 200),
        ]);

        $config = Mockery::mock(Repository::class);
        $config->shouldReceive('get')->andThrow(new RuntimeException('medidor roto'));
        $this->app->instance(LlmUsageMeter::class, new LlmUsageMeter($config));

        $this->app->make('config')->set('stories.llm.anthropic.api_key', 'clave-de-anthropic');
        $this->app->make('config')->set('stories.llm.anthropic.max_retries', 0);
        $this->app->forgetInstance(AnthropicClient::class);

        try {
            $decoded = $this->app->make(AnthropicClient::class)->generateJson('s', 'u', [
                'type' => 'object',
                'properties' => ['ok' => ['type' => 'boolean']],
            ], LlmTask::Script);
        } catch (Throwable $exception) {
            $this->fail('El fallo del medidor no debe tumbar la generación: '.$exception->getMessage());
        }

        $this->assertSame(['ok' => true], $decoded);
    }

    public function test_a_job_that_spends_tokens_writes_the_summary_onto_the_story(): void
    {
        $story = Story::factory()->create([
            'llm_cost_usd' => 0,
            'llm_input_tokens' => 0,
            'llm_output_tokens' => 0,
        ]);
        $meter = $this->app->make(LlmUsageMeter::class);
        $meter->record('anthropic', 'claude-haiku-4-5', LlmTask::Script, 10_000, 2_000);
        $summary = $meter->summary();

        $this->recordUsage($story, $meter, 'script');

        $fresh = $story->fresh();

        $this->assertInstanceOf(Story::class, $fresh);
        $this->assertEqualsWithDelta($summary['costUsd'], (float) $fresh->llm_cost_usd, 0.000001);
        $this->assertSame($summary['inputTokens'], $fresh->llm_input_tokens);
        $this->assertSame($summary['outputTokens'], $fresh->llm_output_tokens);
        $this->assertSame(0.02, (float) $fresh->llm_cost_usd);
        $this->assertSame(10_000, $fresh->llm_input_tokens);
        $this->assertSame(2_000, $fresh->llm_output_tokens);
    }

    public function test_a_second_job_on_the_same_story_adds_to_the_previous_total(): void
    {
        $story = Story::factory()->create([
            'llm_cost_usd' => 0,
            'llm_input_tokens' => 0,
            'llm_output_tokens' => 0,
        ]);
        $meter = $this->app->make(LlmUsageMeter::class);

        $meter->record('anthropic', 'claude-haiku-4-5', LlmTask::Script, 10_000, 2_000);
        $this->recordUsage($story, $meter, 'script');

        $meter->record('anthropic', 'claude-haiku-4-5', LlmTask::Review, 10_000, 2_000);
        $this->recordUsage($story->fresh() ?? $story, $meter, 'images');

        $fresh = $story->fresh();

        $this->assertInstanceOf(Story::class, $fresh);
        $this->assertEqualsWithDelta(0.04, (float) $fresh->llm_cost_usd, 0.000001);
        $this->assertSame(20_000, $fresh->llm_input_tokens);
        $this->assertSame(4_000, $fresh->llm_output_tokens);
        $this->assertSame(2, $fresh->events()->where('type', 'llm_usage')->count());
    }

    public function test_a_job_that_fails_after_spending_tokens_still_persists_the_cost(): void
    {
        $story = Story::factory()->create([
            'llm_cost_usd' => 0,
            'llm_input_tokens' => 0,
            'llm_output_tokens' => 0,
        ]);
        $this->app->make(LlmUsageMeter::class)->record(
            'anthropic',
            'claude-haiku-4-5',
            LlmTask::Script,
            10_000,
            2_000,
        );

        (new RunPipelineStep($story->id, 'script'))->failed(new RuntimeException('sin sitio'));

        $fresh = $story->fresh();

        $this->assertInstanceOf(Story::class, $fresh);
        $this->assertEqualsWithDelta(0.02, (float) $fresh->llm_cost_usd, 0.000001);
        $this->assertSame(10_000, $fresh->llm_input_tokens);
        $this->assertSame(2_000, $fresh->llm_output_tokens);
    }

    public function test_a_job_writes_an_llm_usage_event_with_the_breakdown(): void
    {
        $story = Story::factory()->create();
        $meter = $this->app->make(LlmUsageMeter::class);
        $meter->record('anthropic', 'claude-haiku-4-5', LlmTask::Script, 10_000, 2_000);
        $meter->record('gemini', 'gemini-3.6-flash', LlmTask::Review, 1_000, 200);

        $this->recordUsage($story, $meter, 'script');

        $event = $story->fresh()?->events()->where('type', 'llm_usage')->first();

        $this->assertInstanceOf(StoryEvent::class, $event);
        $this->assertSame('script', $event->payload['step'] ?? null);
        $this->assertSame(2, $event->payload['calls'] ?? null);
        $this->assertSame(11_000, $event->payload['inputTokens'] ?? null);
        $this->assertSame(2_200, $event->payload['outputTokens'] ?? null);
        $this->assertEqualsWithDelta(0.02, (float) ($event->payload['costUsd'] ?? 0), 0.000001);
        $this->assertSame(1, $event->payload['byProvider']['anthropic']['calls'] ?? null);
        $this->assertSame(1, $event->payload['byProvider']['gemini']['calls'] ?? null);
        $this->assertSame(10_000, $event->payload['byProvider']['anthropic']['inputTokens'] ?? null);
        $this->assertSame(1_000, $event->payload['byProvider']['gemini']['inputTokens'] ?? null);
    }

    private function recordUsage(Story $story, LlmUsageMeter $meter, string $step): void
    {
        $record = new ReflectionMethod(RunPipelineStep::class, 'recordUsage');
        $record->invoke(new RunPipelineStep($story->id, $step), $story, $meter);
    }
}
