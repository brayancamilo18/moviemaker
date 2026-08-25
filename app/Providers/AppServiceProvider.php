<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\ImageGenerator;
use App\Contracts\TextToSpeech;
use App\Services\Image\PollinationsGenerator;
use App\Services\Llm\GeminiClient;
use App\Services\Tts\KokoroTts;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(GeminiClient::class, function (Application $app): GeminiClient {
            /** @var array{api_key: ?string, model: string, base_url: string, timeout: int, max_retries: int} $gemini */
            $gemini = $app->make('config')->get('stories.gemini');

            return new GeminiClient(
                http: $app->make(Factory::class),
                apiKey: (string) $gemini['api_key'],
                model: $gemini['model'],
                baseUrl: $gemini['base_url'],
                timeout: (int) $gemini['timeout'],
                maxRetries: (int) $gemini['max_retries'],
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
}
