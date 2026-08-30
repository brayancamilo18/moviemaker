<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\LlmGenerationException;
use App\Exceptions\LlmTruncatedException;
use App\Services\Llm\AnthropicClient;
use App\Services\Llm\FailoverJsonLlm;
use App\Services\Llm\GeminiClient;
use App\Services\Llm\LlmTask;
use App\Services\Llm\ProviderHealth;
use App\Services\Llm\ProviderHealthStore;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

final class TokenBudgetTest extends TestCase
{
    /**
     * @var array<string, int>
     */
    private const TASK_BUDGETS = [
        'script' => 32000,
        'review' => 16000,
        'visual_bible' => 8000,
        'shot_direction' => 16000,
        'sfx_direction' => 8000,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
        Http::preventStrayRequests();

        $config = $this->app->make('config');
        $config->set('stories.llm.gemini.api_key', 'clave-de-gemini');
        $config->set('stories.llm.anthropic.api_key', 'clave-de-anthropic');
        $config->set('stories.llm.gemini.max_retries', 0);
        $config->set('stories.llm.anthropic.max_retries', 0);
    }

    public function test_each_task_sends_its_own_anthropic_token_budget_not_the_default(): void
    {
        $this->app->make('config')->set('stories.llm.anthropic.max_tokens', [
            'default' => 1111,
            ...self::TASK_BUDGETS,
        ]);

        Http::fake([
            'api.anthropic.com/*' => Http::response($this->anthropicOk(), 200),
        ]);

        $client = $this->app->make(AnthropicClient::class);

        foreach (LlmTask::cases() as $task) {
            $client->generateJson('s', 'u', $this->schema(), $task);
        }

        $sent = $this->recorded('api.anthropic.com');

        $this->assertCount(count(LlmTask::cases()), $sent);

        foreach (LlmTask::cases() as $index => $task) {
            $budget = $sent[$index]->data()['max_tokens'];

            $this->assertSame(self::TASK_BUDGETS[$task->value], $budget);
            $this->assertNotSame(1111, $budget);
        }
    }

    public function test_a_task_without_its_own_budget_falls_back_to_the_default(): void
    {
        $this->app->make('config')->set('stories.llm.anthropic.max_tokens', [
            'default' => 8000,
            'script' => 32000,
        ]);

        Http::fake([
            'api.anthropic.com/*' => Http::response($this->anthropicOk(), 200),
        ]);

        $this->app->make(AnthropicClient::class)->generateJson(
            's',
            'u',
            $this->schema(),
            LlmTask::Review,
        );

        Http::assertSent(static function (Request $request): bool {
            return str_contains($request->url(), 'api.anthropic.com')
                && $request->data()['max_tokens'] === 8000;
        });
    }

    public function test_anthropic_max_tokens_throws_truncated_exception_with_task_and_budget(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response($this->anthropicStop('max_tokens'), 200),
        ]);

        try {
            $this->app->make(AnthropicClient::class)->generateJson(
                's',
                'u',
                $this->schema(),
                LlmTask::Review,
            );
            $this->fail('Se esperaba LlmTruncatedException.');
        } catch (LlmTruncatedException $exception) {
            $this->assertSame(LlmTask::Review, $exception->task);
            $this->assertSame(16000, $exception->budget);
            $this->assertStringContainsString('review', $exception->getMessage());
            $this->assertStringContainsString('16000', $exception->getMessage());
        }
    }

    public function test_anthropic_refusal_throws_generation_exception_not_truncated(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response($this->anthropicStop('refusal'), 200),
        ]);

        try {
            $this->app->make(AnthropicClient::class)->generateJson(
                's',
                'u',
                $this->schema(),
                LlmTask::Script,
            );
            $this->fail('Se esperaba LlmGenerationException.');
        } catch (LlmTruncatedException) {
            $this->fail('refusal no se arregla con más tokens.');
        } catch (LlmGenerationException $exception) {
            $this->assertStringContainsString('refusal', $exception->getMessage());
            $this->assertStringContainsString('script', $exception->getMessage());
        }
    }

    public function test_gemini_max_tokens_throws_truncated_and_safety_throws_generation(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::sequence()
                ->push($this->geminiEnvelope('MAX_TOKENS'), 200)
                ->push($this->geminiEnvelope('SAFETY'), 200),
        ]);

        $client = $this->app->make(GeminiClient::class);

        try {
            $client->generateJson('s', 'u', $this->schema(), LlmTask::ShotDirection);
            $this->fail('Se esperaba LlmTruncatedException.');
        } catch (LlmTruncatedException $exception) {
            $this->assertSame(LlmTask::ShotDirection, $exception->task);
            $this->assertSame(16000, $exception->budget);
            $this->assertStringContainsString('shot_direction', $exception->getMessage());
            $this->assertStringContainsString('16000', $exception->getMessage());
        }

        try {
            $client->generateJson('s', 'u', $this->schema(), LlmTask::VisualBible);
            $this->fail('Se esperaba LlmGenerationException.');
        } catch (LlmTruncatedException) {
            $this->fail('SAFETY no se arregla con más tokens.');
        } catch (LlmGenerationException $exception) {
            $this->assertStringContainsString('SAFETY', $exception->getMessage());
            $this->assertStringContainsString('visual_bible', $exception->getMessage());
        }
    }

    public function test_a_truncated_reply_retries_the_same_provider_with_double_budget(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::sequence()
                ->push($this->geminiEnvelope('MAX_TOKENS'), 200)
                ->push($this->geminiEnvelope('STOP'), 200),
            'api.anthropic.com/*' => Http::response($this->anthropicOk(), 200),
        ]);

        $llm = $this->failover();
        $result = $llm->generateJson('s', 'u', $this->schema(), LlmTask::Review);

        $this->assertSame(['ok' => true], $result);
        $this->assertSame(2, $this->sentTo('generativelanguage.googleapis.com'));
        $this->assertSame(0, $this->sentTo('api.anthropic.com'));

        $sent = $this->recorded('generativelanguage.googleapis.com');
        $this->assertSame(16000, $sent[0]->data()['generationConfig']['maxOutputTokens']);
        $this->assertSame(32000, $sent[1]->data()['generationConfig']['maxOutputTokens']);
    }

    public function test_two_truncations_propagate_after_exactly_two_requests(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response($this->geminiEnvelope('MAX_TOKENS'), 200),
            'api.anthropic.com/*' => Http::response($this->anthropicOk(), 200),
        ]);

        $llm = $this->failover();

        try {
            $llm->generateJson('s', 'u', $this->schema(), LlmTask::SfxDirection);
            $this->fail('Se esperaba LlmTruncatedException.');
        } catch (LlmTruncatedException $exception) {
            $this->assertSame(LlmTask::SfxDirection, $exception->task);
        }

        $this->assertSame(2, $this->sentTo('generativelanguage.googleapis.com'));
        $this->assertSame(0, $this->sentTo('api.anthropic.com'));
    }

    public function test_a_truncation_does_not_trip_the_circuit_breaker(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::sequence()
                ->push($this->geminiEnvelope('MAX_TOKENS'), 200)
                ->push($this->geminiEnvelope('MAX_TOKENS'), 200)
                ->push($this->geminiEnvelope('STOP'), 200),
            'api.anthropic.com/*' => Http::response($this->anthropicOk(), 200),
        ]);

        $llm = $this->failover();

        try {
            $llm->generateJson('s', 'u', $this->schema(), LlmTask::Script);
            $this->fail('Se esperaba LlmTruncatedException.');
        } catch (LlmTruncatedException) {
        }

        $this->assertSame(['ok' => true], $llm->generateJson('s', 'u', $this->schema(), LlmTask::Script));
        $this->assertSame(3, $this->sentTo('generativelanguage.googleapis.com'));
        $this->assertSame(0, $this->sentTo('api.anthropic.com'));
    }

    public function test_a_truncation_does_not_change_the_fallback_notice(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::sequence()
                ->push($this->geminiEnvelope('MAX_TOKENS'), 200)
                ->push($this->geminiEnvelope('STOP'), 200),
            'api.anthropic.com/*' => Http::response($this->anthropicOk(), 200),
        ]);

        $llm = $this->failover();
        $llm->generateJson('s', 'u', $this->schema(), LlmTask::Review);

        $this->assertNull($llm->fallbackNotice());
    }

    public function test_the_doubled_budget_never_exceeds_the_retry_cap(): void
    {
        $this->app->make('config')->set('stories.llm.anthropic.max_tokens.script', 40000);

        Http::fake([
            'api.anthropic.com/*' => Http::sequence()
                ->push($this->anthropicStop('max_tokens'), 200)
                ->push($this->anthropicOk(), 200),
            'generativelanguage.googleapis.com/*' => Http::response($this->geminiEnvelope('STOP'), 200),
        ]);

        $llm = new FailoverJsonLlm(
            primary: $this->app->make(AnthropicClient::class),
            fallback: $this->app->make(GeminiClient::class),
            logger: $this->app->make(LoggerInterface::class),
            store: $this->app->make(ProviderHealthStore::class),
            health: $this->app->make(ProviderHealth::class),
            truncationRetryCap: 64000,
        );

        $this->assertSame(['ok' => true], $llm->generateJson('s', 'u', $this->schema(), LlmTask::Script));

        $sent = $this->recorded('api.anthropic.com');
        $this->assertCount(2, $sent);
        $this->assertSame(40000, $sent[0]->data()['max_tokens']);
        $this->assertSame(64000, $sent[1]->data()['max_tokens']);
        $this->assertSame(0, $this->sentTo('generativelanguage.googleapis.com'));
    }

    private function failover(): FailoverJsonLlm
    {
        return new FailoverJsonLlm(
            primary: $this->app->make(GeminiClient::class),
            fallback: $this->app->make(AnthropicClient::class),
            logger: $this->app->make(LoggerInterface::class),
            store: $this->app->make(ProviderHealthStore::class),
            health: $this->app->make(ProviderHealth::class),
            truncationRetryCap: 64000,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function schema(): array
    {
        return [
            'type' => 'OBJECT',
            'properties' => [
                'ok' => ['type' => 'BOOLEAN'],
            ],
            'required' => ['ok'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function anthropicOk(): array
    {
        return [
            'stop_reason' => 'end_turn',
            'content' => [
                ['type' => 'text', 'text' => '{"ok":true}'],
            ],
            'usage' => [
                'input_tokens' => 10,
                'output_tokens' => 4,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function anthropicStop(string $reason): array
    {
        return [
            'stop_reason' => $reason,
            'content' => [
                ['type' => 'text', 'text' => '{"ok":'],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function geminiEnvelope(string $finishReason): array
    {
        return [
            'candidates' => [
                [
                    'finishReason' => $finishReason,
                    'content' => [
                        'parts' => [
                            ['text' => '{"ok":true}'],
                        ],
                    ],
                ],
            ],
            'usageMetadata' => [
                'promptTokenCount' => 10,
                'candidatesTokenCount' => 4,
            ],
        ];
    }

    /**
     * @return list<Request>
     */
    private function recorded(string $host): array
    {
        return Http::recorded(
            static fn (Request $request): bool => str_contains($request->url(), $host),
        )->map(static fn (array $pair): Request => $pair[0])->all();
    }

    private function sentTo(string $host): int
    {
        return count($this->recorded($host));
    }
}
