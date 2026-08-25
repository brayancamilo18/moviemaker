<?php

declare(strict_types=1);

namespace App\Services\Tts;

use App\Contracts\TextToSpeech;
use App\Exceptions\TtsUnavailableException;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\RequestException;
use Throwable;

final class KokoroTts implements TextToSpeech
{
    public const START_COMMAND = 'cd tts-service && source venv/bin/activate && uvicorn main:app --host 127.0.0.1 --port 8020 --workers 1';

    public function __construct(
        private Factory $http,
        private Filesystem $files,
        private string $baseUrl,
        private string $voice,
        private float $speed,
        private int $timeout,
        private string $cacheDirectory,
    ) {}

    /**
     * @param  array{voice?: string, speed?: float|int|string, skip_cache?: bool}  $options
     */
    public function synthesize(string $text, array $options = []): string
    {
        $voice = $options['voice'] ?? $this->voice;
        $speed = (float) ($options['speed'] ?? $this->speed);
        $path = $this->cachePath($text, $voice, $speed);

        if (! ($options['skip_cache'] ?? false) && $this->files->exists($path)) {
            return $path;
        }

        $wav = $this->requestWav($text, $voice, $speed);
        $this->files->ensureDirectoryExists($this->cacheDirectory);
        $this->files->put($path, $wav);

        return $path;
    }

    /**
     * @param  array{voice?: string, speed?: float|int|string}  $options
     */
    public function isCached(string $text, array $options = []): bool
    {
        $voice = $options['voice'] ?? $this->voice;
        $speed = (float) ($options['speed'] ?? $this->speed);

        return $this->files->exists($this->cachePath($text, $voice, $speed));
    }

    public function isAvailable(): bool
    {
        try {
            $response = $this->http
                ->timeout(3)
                ->acceptJson()
                ->get($this->baseUrl.'/health');
        } catch (ConnectionException) {
            return false;
        }

        return $response->successful()
            && $response->json('status') === 'ok'
            && $response->json('model_loaded') === true;
    }

    private function requestWav(string $text, string $voice, float $speed): string
    {
        try {
            $response = $this->http
                ->timeout($this->timeout)
                ->retry(2, 250, static fn (Throwable $exception): bool => $exception instanceof ConnectionException)
                ->accept('audio/wav')
                ->asJson()
                ->post($this->baseUrl.'/synthesize', [
                    'text' => $text,
                    'voice' => $voice,
                    'speed' => $speed,
                ]);
        } catch (ConnectionException $exception) {
            throw $this->unavailable($exception);
        } catch (RequestException $exception) {
            throw $this->unavailable($exception);
        }

        if (! $response->successful() || $response->body() === '') {
            throw $this->unavailable();
        }

        return $response->body();
    }

    private function cachePath(string $text, string $voice, float $speed): string
    {
        $key = sha1($text.$voice.(string) $speed);

        return $this->cacheDirectory.DIRECTORY_SEPARATOR.$key.'.wav';
    }

    private function unavailable(?Throwable $previous = null): TtsUnavailableException
    {
        return new TtsUnavailableException(
            'No se pudo conectar con el sidecar de Kokoro en '.$this->baseUrl.'. '
            .'Arráncalo con: '.self::START_COMMAND,
            previous: $previous,
        );
    }
}
