<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\ImageGenerator;
use App\Contracts\JsonLlm;
use App\Contracts\TextToSpeech;
use App\Services\Audio\FreesoundClient;
use App\Services\Audio\SoundResolver;
use App\Services\Ffmpeg\FfmpegFilterScript;
use App\Services\Ffmpeg\FfmpegRunner;
use App\Services\Ffmpeg\MediaProbe;
use App\Services\Image\PollinationsGenerator;
use App\Services\Llm\AnthropicClient;
use App\Services\Llm\FailoverJsonLlm;
use App\Services\Llm\GeminiClient;
use App\Services\Llm\ProviderHealth;
use App\Services\Llm\ProviderHealthStore;
use App\Services\Tts\KokoroTts;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Singleton para que el cambio al respaldo valga para toda la ejecución: si cada servicio
        // recibiera su propia instancia, cada uno volvería a pagar los reintentos del principal.
        $this->app->bind(GeminiClient::class, fn (Application $app): GeminiClient => $this->gemini($app));
        $this->app->bind(AnthropicClient::class, fn (Application $app): AnthropicClient => $this->anthropic($app));

        $this->app->singleton(JsonLlm::class, function (Application $app): JsonLlm {
            $config = $app->make('config');
            $primary = $this->llmClient($app, (string) $config->get('stories.llm.provider'));
            $fallbackProvider = (string) $config->get('stories.llm.fallback');

            if ($fallbackProvider === '' || $fallbackProvider === (string) $config->get('stories.llm.provider')) {
                return $primary;
            }

            $fallback = $this->llmClient($app, $fallbackProvider);

            if (! $fallback->isAvailable()) {
                return $primary;
            }

            return new FailoverJsonLlm(
                primary: $primary,
                fallback: $fallback,
                logger: $app->make(LoggerInterface::class),
                store: $app->make(ProviderHealthStore::class),
                health: $app->make(ProviderHealth::class),
                truncationRetryCap: (int) $config->get('stories.llm.truncation_retry_cap'),
            );
        });

        $this->app->singleton(TextToSpeech::class, function (Application $app): TextToSpeech {
            /** @var array{base_url: string, voice: string, speed: float, timeout: int, cache_path: string} $tts */
            $tts = $app->make('config')->get('stories.tts');

            return new KokoroTts(
                http: $app->make(Factory::class),
                files: $app->make(Filesystem::class),
                baseUrl: rtrim($tts['base_url'], '/'),
                voice: $tts['voice'],
                speed: (float) $tts['speed'],
                timeout: (int) $tts['timeout'],
                cacheDirectory: storage_path('app/'.$tts['cache_path']),
            );
        });

        $this->app->singleton(FreesoundClient::class, function (Application $app): FreesoundClient {
            /** @var array{token: ?string, base_url: string, timeout: int, rate_limit_seconds: float, max_retries: int} $freesound */
            $freesound = $app->make('config')->get('stories.audio.freesound');

            return new FreesoundClient(
                http: $app->make(Factory::class),
                token: (string) ($freesound['token'] ?? ''),
                baseUrl: (string) $freesound['base_url'],
                timeout: (int) $freesound['timeout'],
                rateLimitSeconds: (float) $freesound['rate_limit_seconds'],
                maxRetries: (int) $freesound['max_retries'],
            );
        });

        $this->app->singleton(SoundResolver::class);

        // Singleton para que la detección de la opción de filter_complex se pague una sola vez.
        $this->app->singleton(FfmpegFilterScript::class);

        $this->app->singleton(FfmpegRunner::class);
        $this->app->singleton(MediaProbe::class);

        $this->app->singleton(ImageGenerator::class, function (Application $app): ImageGenerator {
            $provider = (string) $app->make('config')->get('stories.images.provider');

            return match ($provider) {
                'pollinations' => $app->make(PollinationsGenerator::class),
                default => throw new InvalidArgumentException(
                    "Proveedor de imágenes desconocido: {$provider}.",
                ),
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }

    private function llmClient(Application $app, string $provider): JsonLlm
    {
        return match ($provider) {
            'gemini' => $app->make(GeminiClient::class),
            'anthropic' => $app->make(AnthropicClient::class),
            default => throw new InvalidArgumentException(
                "Proveedor de LLM desconocido: {$provider}.",
            ),
        };
    }

    private function gemini(Application $app): GeminiClient
    {
        /** @var array{api_key: ?string, base_url: string, timeout: int, max_retries: int, models: array<string, string>} $gemini */
        $gemini = $app->make('config')->get('stories.llm.gemini');

        /** @var array<string, int> $maxTokens */
        $maxTokens = $app->make('config')->get('stories.llm.anthropic.max_tokens');

        return new GeminiClient(
            http: $app->make(Factory::class),
            apiKey: (string) $gemini['api_key'],
            models: $gemini['models'],
            maxTokens: $maxTokens,
            baseUrl: $gemini['base_url'],
            timeout: (int) $gemini['timeout'],
            maxRetries: (int) $gemini['max_retries'],
            logger: $app->make(LoggerInterface::class),
        );
    }

    private function anthropic(Application $app): AnthropicClient
    {
        /** @var array{api_key: ?string, base_url: string, version: string, beta: string, timeout: int, max_retries: int, models: array<string, string>, max_tokens: array<string, int>} $anthropic */
        $anthropic = $app->make('config')->get('stories.llm.anthropic');

        return new AnthropicClient(
            http: $app->make(Factory::class),
            apiKey: (string) $anthropic['api_key'],
            models: $anthropic['models'],
            maxTokens: $anthropic['max_tokens'],
            baseUrl: $anthropic['base_url'],
            version: (string) $anthropic['version'],
            beta: (string) $anthropic['beta'],
            timeout: (int) $anthropic['timeout'],
            maxRetries: (int) $anthropic['max_retries'],
            logger: $app->make(LoggerInterface::class),
        );
    }
}
