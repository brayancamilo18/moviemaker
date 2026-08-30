<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\CheckProviderHealth;
use App\Services\Llm\AnthropicClient;
use App\Services\Llm\GeminiClient;
use App\Services\Llm\ProviderHealth;
use App\Services\Llm\ProviderHealthStore;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use RuntimeException;
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
        $this->assertNull($report['gemini']['errorClass']);
        $this->assertNull($report['gemini']['hint']);
        Http::assertNothingSent();
    }

    public function test_check_one_only_asks_that_provider(): void
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
            'api.anthropic.com/*' => Http::response(['error' => ['message' => 'no debía llamarse']], 401),
        ]);

        $report = $this->health()->checkOne('gemini', true);

        $this->assertTrue($report['reachable']);
        $this->assertSame(
            0,
            Http::recorded(
                static fn (Request $request): bool => str_contains($request->url(), 'api.anthropic.com'),
            )->count(),
        );
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

    public function test_a_429_or_saturation_points_to_the_daily_quota(): void
    {
        $this->assertSame(
            'Cuota diaria de Gemini agotada. Se renueva a medianoche del Pacífico, las 9:00 en España.',
            $this->health()->hintFor('Gemini está saturado (HTTP 429) tras varios reintentos.'),
        );
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

    public function test_the_health_job_stores_a_live_report_as_worker(): void
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

        $this->app->make(CheckProviderHealth::class)->handle(
            $this->health(),
            $this->app->make(ProviderHealthStore::class),
        );

        $stored = $this->app->make(ProviderHealthStore::class)->get();

        $this->assertNotNull($stored);
        $this->assertTrue($stored['report']['gemini']['reachable']);
        $this->assertFalse($stored['report']['anthropic']['reachable']);
    }

    public function test_a_failed_health_job_marks_both_providers_unreachable(): void
    {
        $this->app->make(CheckProviderHealth::class)->failed(
            new RuntimeException('El worker no pudo comprobar.'),
        );

        $stored = $this->app->make(ProviderHealthStore::class)->get();

        $this->assertNotNull($stored);
        $this->assertFalse($stored['report']['gemini']['reachable']);
        $this->assertFalse($stored['report']['anthropic']['reachable']);
        $this->assertSame('El worker no pudo comprobar.', $stored['report']['gemini']['error']);
        $this->assertSame('El worker no pudo comprobar.', $stored['report']['anthropic']['error']);
    }

    public function test_put_updates_one_provider_and_keeps_the_other(): void
    {
        $store = $this->app->make(ProviderHealthStore::class);
        $store->put([
            'gemini' => ['name' => 'gemini', 'reachable' => true],
            'anthropic' => ['name' => 'haiku', 'reachable' => false, 'error' => 'antes'],
        ], measuredBy: 'cli');

        $store->put([
            'gemini' => [
                'name' => 'gemini',
                'reachable' => false,
                'error' => 'saturado',
                'measuredBy' => 'pipeline',
            ],
        ], 'gemini');

        $stored = $store->get();

        $this->assertNotNull($stored);
        $this->assertFalse($stored['report']['gemini']['reachable']);
        $this->assertSame('saturado', $stored['report']['gemini']['error']);
        $this->assertSame('pipeline', $stored['report']['gemini']['measuredBy']);
        $this->assertFalse($stored['report']['anthropic']['reachable']);
        $this->assertSame('antes', $stored['report']['anthropic']['error']);
    }

    public function test_llm_health_command_stores_the_cli_measurement(): void
    {
        $this->withKeys();
        Http::fake();

        $this->artisan('llm:health')->assertSuccessful();

        $stored = $this->app->make(ProviderHealthStore::class)->get();

        $this->assertNotNull($stored);
        $this->assertArrayHasKey('gemini', $stored['report']);
        $this->assertArrayHasKey('anthropic', $stored['report']);
        $this->assertNull($stored['report']['gemini']['reachable']);
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
