<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Llm\AnthropicClient;
use App\Services\Llm\GeminiClient;
use App\Services\Llm\ProviderHealth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;
use Throwable;

final class ProviderHealthTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
        Http::preventStrayRequests();
    }

    public function test_a_cold_check_makes_no_http_calls_and_leaves_reachable_null(): void
    {
        $this->withKeys();
        Http::fake();

        $report = $this->health()->check(false);

        $this->assertTrue($report['gemini']['configured']);
        $this->assertTrue($report['anthropic']['configured']);
        $this->assertNull($report['gemini']['reachable']);
        $this->assertNull($report['anthropic']['reachable']);
        Http::assertNothingSent();
    }

    public function test_a_live_check_marks_gemini_reachable_and_keeps_anthropic_error_without_throwing(): void
    {
        $this->withKeys();
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(
                [
                    'candidates' => [
                        [
                            'finishReason' => 'STOP',
                            'content' => [
                                'parts' => [
                                    ['text' => json_encode(['reply' => 'ok'], JSON_THROW_ON_ERROR)],
                                ],
                            ],
                        ],
                    ],
                ],
                200,
            ),
            'api.anthropic.com/*' => Http::response(
                ['error' => ['message' => 'invalid x-api-key']],
                401,
            ),
        ]);

        try {
            $report = $this->health()->check(true);
        } catch (Throwable $exception) {
            $this->fail('La comprobación en vivo no debe propagar excepciones: '.$exception->getMessage());
        }

        $this->assertTrue($report['gemini']['reachable']);
        $this->assertNull($report['gemini']['error']);
        $this->assertFalse($report['anthropic']['reachable']);
        $this->assertNotNull($report['anthropic']['error']);
        $this->assertNotSame('', $report['anthropic']['error']);
    }

    public function test_a_live_check_with_empty_keys_is_not_configured_and_makes_no_http_calls(): void
    {
        $this->withKeys(gemini: '', anthropic: '');
        Http::fake();

        $report = $this->health()->check(true);

        $this->assertFalse($report['gemini']['configured']);
        $this->assertFalse($report['anthropic']['configured']);
        Http::assertNothingSent();
    }

    private function withKeys(string $gemini = 'clave-de-gemini', string $anthropic = 'clave-de-anthropic'): void
    {
        $config = $this->app->make('config');
        $config->set('stories.llm.gemini.api_key', $gemini);
        $config->set('stories.llm.anthropic.api_key', $anthropic);

        $this->app->forgetInstance(GeminiClient::class);
        $this->app->forgetInstance(AnthropicClient::class);
        $this->app->forgetInstance(ProviderHealth::class);
    }

    private function health(): ProviderHealth
    {
        return $this->app->make(ProviderHealth::class);
    }
}
