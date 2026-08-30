<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\JsonLlm;
use App\Exceptions\LlmGenerationException;
use App\Exceptions\LlmUnavailableException;
use App\Services\Llm\FailoverJsonLlm;
use App\Services\Llm\LlmTask;
use App\Services\Llm\ProviderHealth;
use App\Services\Llm\ProviderHealthStore;
use App\Services\Story\StoryGenerator;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Tests\TestCase;
use Throwable;

final class LlmFailoverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
        Http::preventStrayRequests();
        // El aviso del respaldo es una línea larga y la consola la parte por el ancho del terminal.
        putenv('COLUMNS=200');

        $config = $this->app->make('config');
        $config->set('stories.llm.provider', 'gemini');
        $config->set('stories.llm.fallback', 'anthropic');
        $config->set('stories.llm.gemini.api_key', 'clave-de-gemini');
        $config->set('stories.llm.gemini.models.default', 'gemini-3.6-flash');
        $config->set('stories.llm.anthropic.api_key', 'clave-de-anthropic');
        $config->set('stories.llm.anthropic.models.default', 'claude-haiku-4-5');

        $this->app->forgetInstance(JsonLlm::class);
    }

    public function test_a_saturated_gemini_hands_the_story_to_anthropic(): void
    {
        $fixture = $this->fixture();
        $this->fakeBoth(geminiStatus: 429, anthropicStory: $fixture);

        $story = $this->app->make(StoryGenerator::class)->generate();

        $this->assertSame($fixture['title'], $story->title);
        $this->assertSame(1, $this->sentTo('api.anthropic.com'));
    }

    public function test_a_gemini_without_credential_is_not_even_asked(): void
    {
        $fixture = $this->fixture();
        $this->app->make('config')->set('stories.llm.gemini.api_key', '');
        $this->app->forgetInstance(JsonLlm::class);
        $this->fakeBoth(geminiStatus: 429, anthropicStory: $fixture);

        $story = $this->app->make(StoryGenerator::class)->generate();

        $this->assertSame($fixture['title'], $story->title);
        $this->assertSame(0, $this->sentTo('generativelanguage.googleapis.com'));
    }

    public function test_an_invalid_request_stays_with_gemini_instead_of_burning_credit(): void
    {
        // Un HTTP 400 es nuestro schema o nuestro prompt: en el respaldo fallaría igual y solo
        // gastaría crédito de Anthropic.
        $this->fakeBoth(geminiStatus: 400, anthropicStory: $this->fixture());

        $this->expectException(LlmGenerationException::class);
        $this->expectExceptionMessage('HTTP 400');

        try {
            $this->app->make(StoryGenerator::class)->generate();
        } finally {
            $this->assertSame(0, $this->sentTo('api.anthropic.com'));
        }
    }

    public function test_the_change_of_provider_lasts_the_whole_run(): void
    {
        $fixture = $this->fixture();
        $this->fakeBoth(geminiStatus: 503, anthropicStory: $fixture);

        $generator = $this->app->make(StoryGenerator::class);
        $generator->generate();
        $askedGemini = $this->sentTo('generativelanguage.googleapis.com');
        $generator->generate();

        $this->assertGreaterThan(0, $askedGemini);
        $this->assertSame(
            $askedGemini,
            $this->sentTo('generativelanguage.googleapis.com'),
            'Tras caerse, no se le vuelve a preguntar a Gemini en la misma ejecución.',
        );
        $this->assertSame(2, $this->sentTo('api.anthropic.com'));
    }

    public function test_the_console_says_who_wrote_the_story_and_why(): void
    {
        $this->fakeBoth(geminiStatus: 429, anthropicStory: $this->fixture());

        // Una expectativa por línea escrita: dos cadenas de la misma línea casarían con la misma
        // escritura y solo se daría por cumplida la primera.
        $this->artisan('story:generate', ['--count' => 1, '--no-review' => true, '--dry-run' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('Escrito con claude-haiku-4-5.')
            ->expectsOutputToContain(
                'gemini-3.6-flash no atendió: Gemini está saturado (HTTP 429) tras varios '
                .'reintentos. Lo que queda de esta ejecución lo hace claude-haiku-4-5.',
            );
    }

    public function test_a_real_call_records_only_the_provider_that_answered(): void
    {
        $store = $this->app->make(ProviderHealthStore::class);
        $store->put([
            'gemini' => ['name' => 'viejo', 'reachable' => false],
            'anthropic' => ['name' => 'haiku', 'reachable' => false, 'error' => 'antes'],
        ], measuredBy: 'cli');

        $llm = new FailoverJsonLlm(
            primary: $this->fakeLlm('gemini-3.6-flash', true, ['reply' => 'ok']),
            fallback: $this->fakeLlm('claude-haiku-4-5', true, ['reply' => 'no']),
            logger: $this->app->make(LoggerInterface::class),
            store: $store,
            health: $this->app->make(ProviderHealth::class),
        );

        $this->assertSame(['reply' => 'ok'], $llm->generateJson('s', 'u', []));

        $stored = $store->get();
        $this->assertNotNull($stored);
        $this->assertTrue($stored['report']['gemini']['reachable']);
        $this->assertSame('pipeline', $stored['report']['gemini']['measuredBy']);
        $this->assertFalse($stored['report']['anthropic']['reachable']);
        $this->assertSame('antes', $stored['report']['anthropic']['error']);
    }

    public function test_an_unavailable_primary_records_gemini_and_then_the_fallback(): void
    {
        $store = $this->app->make(ProviderHealthStore::class);
        $llm = new FailoverJsonLlm(
            primary: $this->fakeLlm('gemini-3.6-flash', true, new LlmUnavailableException('saturado')),
            fallback: $this->fakeLlm('claude-haiku-4-5', true, ['reply' => 'ok']),
            logger: $this->app->make(LoggerInterface::class),
            store: $store,
            health: $this->app->make(ProviderHealth::class),
        );

        $this->assertSame(['reply' => 'ok'], $llm->generateJson('s', 'u', []));

        $stored = $store->get();
        $this->assertNotNull($stored);
        $this->assertFalse($stored['report']['gemini']['reachable']);
        $this->assertSame('saturado', $stored['report']['gemini']['error']);
        $this->assertSame(
            'Cuota diaria de Gemini agotada. Se renueva a medianoche del Pacífico, las 9:00 en España.',
            $stored['report']['gemini']['hint'],
        );
        $this->assertSame('pipeline', $stored['report']['gemini']['measuredBy']);
        $this->assertTrue($stored['report']['anthropic']['reachable']);
        $this->assertSame('pipeline', $stored['report']['anthropic']['measuredBy']);
    }

    public function test_a_dead_health_store_does_not_stop_generation(): void
    {
        $cache = $this->createMock(Repository::class);
        $cache->method('get')->willThrowException(new RuntimeException('caché caída'));
        $cache->method('put')->willThrowException(new RuntimeException('caché caída'));

        $llm = new FailoverJsonLlm(
            primary: $this->fakeLlm('gemini-3.6-flash', true, ['reply' => 'ok']),
            fallback: $this->fakeLlm('claude-haiku-4-5', true, ['reply' => 'no']),
            logger: $this->app->make(LoggerInterface::class),
            store: new ProviderHealthStore($cache),
            health: $this->app->make(ProviderHealth::class),
        );

        $this->assertSame(['reply' => 'ok'], $llm->generateJson('s', 'u', []));
    }

    public function test_without_fallback_the_saturation_is_still_an_error(): void
    {
        $this->app->make('config')->set('stories.llm.fallback', '');
        $this->app->forgetInstance(JsonLlm::class);
        $this->fakeBoth(geminiStatus: 429, anthropicStory: $this->fixture());

        $this->expectException(LlmGenerationException::class);
        $this->expectExceptionMessage('saturado');

        $this->app->make(StoryGenerator::class)->generate();
    }

    /**
     * @param  array<string, mixed>|Throwable  $result
     */
    private function fakeLlm(string $name, bool $available, array|Throwable $result): JsonLlm
    {
        return new class($name, $available, $result) implements JsonLlm
        {
            /**
             * @param  array<string, mixed>|Throwable  $result
             */
            public function __construct(
                private string $modelName,
                private bool $available,
                private array|Throwable $result,
            ) {}

            public function generateJson(
                string $systemInstruction,
                string $userPrompt,
                array $schema,
                LlmTask $task = LlmTask::Script,
                float $temperature = 1.0,
            ): array {
                if ($this->result instanceof Throwable) {
                    throw $this->result;
                }

                return $this->result;
            }

            public function isAvailable(): bool
            {
                return $this->available;
            }

            public function name(): string
            {
                return $this->modelName;
            }

            public function fallbackNotice(): ?string
            {
                return null;
            }
        };
    }

    /**
     * @param  array<string, mixed>  $anthropicStory
     */
    private function fakeBoth(int $geminiStatus, array $anthropicStory): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(
                ['error' => ['message' => 'no disponible']],
                $geminiStatus,
            ),
            'api.anthropic.com/*' => Http::response([
                'stop_reason' => 'end_turn',
                'content' => [
                    ['type' => 'text', 'text' => json_encode($anthropicStory, JSON_UNESCAPED_UNICODE)],
                ],
            ], 200),
        ]);
    }

    private function sentTo(string $host): int
    {
        return Http::recorded(
            static fn (Request $request): bool => str_contains($request->url(), $host),
        )->count();
    }

    /**
     * @return array<string, mixed>
     */
    private function fixture(): array
    {
        $json = file_get_contents(base_path('tests/Fixtures/story-response.json'));

        $this->assertNotFalse($json);

        /** @var array<string, mixed> $data */
        $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        return $data;
    }
}
