<?php

declare(strict_types=1);

namespace App\Services\Image;

use App\Contracts\ImageGenerator;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Sleep;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

final class PollinationsGenerator implements ImageGenerator
{
    private const LOCK_KEY = 'images:pollinations:lock';

    private const AVAILABLE_AT_KEY = 'images:pollinations:available_at';

    private readonly string $baseUrl;

    private readonly string $model;

    private readonly int $width;

    private readonly int $height;

    private readonly float $rateLimitSeconds;

    private readonly int $maxRetries;

    private readonly int $timeout;

    private readonly string $cacheDirectory;

    public function __construct(
        private Factory $http,
        private Filesystem $files,
        private CacheRepository $cache,
        private LoggerInterface $logger,
        ConfigRepository $config,
    ) {
        $this->baseUrl = rtrim((string) $config->get('stories.images.pollinations.base_url'), '/');
        $this->model = (string) $config->get('stories.images.pollinations.model');
        $this->width = (int) $config->get('stories.images.width');
        $this->height = (int) $config->get('stories.images.height');
        $this->rateLimitSeconds = (float) $config->get('stories.images.rate_limit_seconds');
        $this->maxRetries = (int) $config->get('stories.images.max_retries');
        $this->timeout = (int) $config->get('stories.images.timeout', 120);
        $this->cacheDirectory = storage_path('app/'.(string) $config->get('stories.images.cache_path', 'image-cache'));
    }

    public function generate(string $prompt, int $seed): string
    {
        $path = $this->cachePath($prompt, $seed);

        if ($this->files->exists($path) && $this->isValidImageFile($path)) {
            return $path;
        }

        $attemptSeed = $seed;
        $attempts = max(1, $this->maxRetries + 1);
        $lastError = 'sin respuesta';

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $bytes = $this->fetch($prompt, $attemptSeed);
            } catch (LockTimeoutException) {
                $lastError = 'candado de rate limit agotado';
                $this->backoff($attempt, $attempts);

                continue;
            } catch (ConnectionException) {
                $lastError = 'timeout o conexión';
                $this->backoff($attempt, $attempts);

                continue;
            } catch (RequestException $exception) {
                $status = $exception->response->status();
                $lastError = "HTTP {$status}";

                if (! $this->isRetryableStatus($status)) {
                    break;
                }

                $this->backoff($attempt, $attempts);

                continue;
            } catch (Throwable $exception) {
                $lastError = $exception->getMessage();
                $this->backoff($attempt, $attempts);

                continue;
            }

            if ($this->isValidImageData($bytes)) {
                $this->files->ensureDirectoryExists($this->cacheDirectory);
                $this->files->put($path, $bytes);

                return $path;
            }

            $lastError = 'el cuerpo no es una imagen válida';
            $attemptSeed = $this->nextSeed($seed, $attempt);
            $this->backoff($attempt, $attempts);
        }

        $this->logger->warning('No se pudo generar la imagen. Se usará un marcador.', [
            'seed' => $seed,
            'error' => $lastError,
            'prompt' => mb_substr($prompt, 0, 160),
        ]);

        return $this->placeholder($prompt, $seed);
    }

    public function isAvailable(): bool
    {
        $origin = $this->origin();

        if ($origin === '') {
            return false;
        }

        try {
            $response = $this->http
                ->timeout(3)
                ->get($origin);
        } catch (ConnectionException) {
            return false;
        }

        return $response->status() < 500;
    }

    private function fetch(string $prompt, int $seed): string
    {
        $lockSeconds = $this->timeout + (int) ceil($this->rateLimitSeconds) + 15;

        // El candado serializa a todos los workers. El wait solo cubre el resto de la ventana en caché compartida.
        return $this->cache->lock(self::LOCK_KEY, $lockSeconds)
            ->block($this->timeout + 60, function () use ($prompt, $seed): string {
                $this->waitForSlot();
                $this->reserveSlot();

                $response = $this->http
                    ->timeout($this->timeout)
                    ->get($this->endpoint($prompt), [
                        'width' => $this->width,
                        'height' => $this->height,
                        'seed' => $seed,
                        'model' => $this->model,
                        'nologo' => 'true',
                    ]);

                if ($this->isRetryableStatus($response->status()) || $response->failed()) {
                    $response->throw();
                }

                return $response->body();
            });
    }

    private function waitForSlot(): void
    {
        $availableAt = (float) $this->cache->get(self::AVAILABLE_AT_KEY, 0.0);
        $remainingMicros = (int) round(($availableAt - microtime(true)) * 1_000_000);

        if ($remainingMicros > 0) {
            Sleep::usleep($remainingMicros);
        }
    }

    private function reserveSlot(): void
    {
        $ttl = max(1, (int) ceil($this->rateLimitSeconds) + 30);

        $this->cache->put(
            self::AVAILABLE_AT_KEY,
            microtime(true) + $this->rateLimitSeconds,
            $ttl,
        );
    }

    private function backoff(int $attempt, int $maxAttempts): void
    {
        if ($attempt >= $maxAttempts) {
            return;
        }

        Sleep::usleep(1_000_000 * (2 ** ($attempt - 1)));
    }

    private function isRetryableStatus(int $status): bool
    {
        return $status === 429 || $status >= 500;
    }

    private function nextSeed(int $seed, int $attempt): int
    {
        $next = ($seed + ($attempt * 9973)) & 0x7FFFFFFF;

        return $next === $seed ? $next + 1 : $next;
    }

    private function endpoint(string $prompt): string
    {
        return $this->baseUrl.'/'.rawurlencode($prompt);
    }

    private function origin(): string
    {
        $parts = parse_url($this->baseUrl);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return '';
        }

        $origin = $parts['scheme'].'://'.$parts['host'];

        if (isset($parts['port'])) {
            $origin .= ':'.$parts['port'];
        }

        return $origin;
    }

    private function cachePath(string $prompt, int $seed): string
    {
        return $this->cacheDirectory.DIRECTORY_SEPARATOR.sha1($prompt.(string) $seed).'.jpg';
    }

    private function isValidImageData(string $bytes): bool
    {
        if ($bytes === '') {
            return false;
        }

        $this->files->ensureDirectoryExists($this->cacheDirectory);
        $tmp = $this->cacheDirectory.DIRECTORY_SEPARATOR.'.probe-'.bin2hex(random_bytes(4));
        $this->files->put($tmp, $bytes);

        try {
            return $this->isValidImageFile($tmp);
        } finally {
            $this->files->delete($tmp);
        }
    }

    private function isValidImageFile(string $path): bool
    {
        $info = @getimagesize($path);

        return $info !== false
            && ($info[0] ?? 0) > 0
            && ($info[1] ?? 0) > 0;
    }

    private function placeholder(string $prompt, int $seed): string
    {
        $path = $this->cacheDirectory.DIRECTORY_SEPARATOR.'placeholder-'.sha1($prompt.(string) $seed).'.jpg';
        $this->files->ensureDirectoryExists($this->cacheDirectory);

        try {
            $this->writePlaceholder($path, $seed);
        } catch (Throwable $exception) {
            $this->logger->warning('No se pudo pintar el marcador con GD. Se escribe un JPEG mínimo.', [
                'seed' => $seed,
                'error' => $exception->getMessage(),
            ]);
            $this->files->put($path, $this->minimalJpeg());
        }

        return $path;
    }

    private function writePlaceholder(string $path, int $seed): void
    {
        $canvas = imagecreatetruecolor($this->width, $this->height);

        if ($canvas === false) {
            throw new RuntimeException('GD no pudo crear el lienzo del marcador.');
        }

        $black = imagecolorallocate($canvas, 0, 0, 0);
        imagefill($canvas, 0, 0, $black);

        $label = (string) $seed;
        $font = 5;
        $textWidth = imagefontwidth($font) * strlen($label);
        $textHeight = imagefontheight($font);

        $tile = imagecreatetruecolor($textWidth, $textHeight);

        if ($tile === false) {
            imagedestroy($canvas);

            throw new RuntimeException('GD no pudo crear el texto del marcador.');
        }

        $tileBlack = imagecolorallocate($tile, 0, 0, 0);
        $tileWhite = imagecolorallocate($tile, 210, 210, 210);
        imagefill($tile, 0, 0, $tileBlack);
        imagestring($tile, $font, 0, 0, $label, $tileWhite);

        $targetHeight = max(1, (int) round($this->height * 0.28));
        $scale = $targetHeight / $textHeight;
        $dstW = max(1, (int) round($textWidth * $scale));
        $dstH = $targetHeight;
        $x = (int) round(($this->width - $dstW) / 2);
        $y = (int) round(($this->height - $dstH) / 2);

        imagecopyresampled($canvas, $tile, $x, $y, 0, 0, $dstW, $dstH, $textWidth, $textHeight);
        imagedestroy($tile);

        $written = imagejpeg($canvas, $path, 85);
        imagedestroy($canvas);

        if ($written === false) {
            throw new RuntimeException('GD no pudo guardar el marcador.');
        }
    }

    private function minimalJpeg(): string
    {
        return base64_decode(
            '/9j/4AAQSkZJRgABAQAAAQABAAD/2wAAAAD/wAARCAABAAEDAREAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAj/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIQAxAAAAGf/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPwB//9k=',
            true,
        ) ?: '';
    }
}
