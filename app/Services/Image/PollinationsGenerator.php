<?php

declare(strict_types=1);

namespace App\Services\Image;

use App\Contracts\ImageGenerator;
use GdImage;
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

    /** Un marco ocupa cientos de píxeles: muestrear uno de cada ocho lo detecta igual y cuesta ocho veces menos. */
    private const BAND_SAMPLE_STEP = 8;

    private readonly string $baseUrl;

    private readonly string $model;

    private readonly int $width;

    private readonly int $height;

    private readonly float $rateLimitSeconds;

    private readonly int $maxRetries;

    private readonly int $timeout;

    private readonly string $cacheDirectory;

    private readonly int $jpegQuality;

    private readonly float $bandBrightness;

    private readonly float $bandUniformity;

    private readonly float $bandMaxRatio;

    private readonly int $outageProbeSeconds;

    private readonly int $outageMaxProbes;

    private bool $sizeReported = false;

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
        $this->jpegQuality = (int) $config->get('stories.images.jpeg_quality');
        $this->bandBrightness = (float) $config->get('stories.images.letterbox.brightness');
        $this->bandUniformity = (float) $config->get('stories.images.letterbox.uniformity');
        $this->bandMaxRatio = (float) $config->get('stories.images.letterbox.max_ratio');
        $this->outageProbeSeconds = (int) $config->get('stories.images.outage.probe_seconds');
        $this->outageMaxProbes = (int) $config->get('stories.images.outage.max_probes');
    }

    public function generate(string $prompt, int $seed, string $negativePrompt = ''): string
    {
        $path = $this->cachePath($prompt, $seed, $negativePrompt);

        if ($this->files->exists($path) && $this->isValidImageFile($path)) {
            // También a la vuelta de la caché: recortar es idempotente y así se arreglan las que se
            // descargaron antes de que existiera esta comprobación.
            $this->trimLetterbox($path);

            return $path;
        }

        $probes = 0;
        $outcome = ['path' => null, 'error' => 'sin respuesta', 'outage' => false];

        // Cuando el que falla es el proveedor, rendirse aquí no ahorra nada: el plano siguiente va
        // a fallar igual y la historia acaba llena de marcadores por una caída de media hora. Así
        // que se espera a que vuelva y se vuelve a pedir. Una respuesta mala de verdad no espera.
        while (true) {
            $outcome = $this->attempts($prompt, $seed, $path, $negativePrompt);

            if ($outcome['path'] !== null) {
                return $outcome['path'];
            }

            if (! $outcome['outage'] || $probes >= $this->outageMaxProbes) {
                break;
            }

            $probes++;
            $this->waitForProvider($probes, $outcome['error']);
        }

        $this->logger->warning('No se pudo generar la imagen. Se usará un marcador.', [
            'seed' => $seed,
            'error' => $outcome['error'],
            'probes' => $probes,
            'prompt' => mb_substr($prompt, 0, 160),
        ]);

        return $this->placeholder($prompt, $seed);
    }

    /**
     * Una ronda completa de intentos sobre el mismo plano. `outage` distingue las dos cosas que se
     * confundían antes: que el proveedor no esté (se espera) y que devuelva basura (se rinde).
     *
     * @return array{path: string|null, error: string, outage: bool}
     */
    private function attempts(string $prompt, int $seed, string $path, string $negativePrompt = ''): array
    {
        $attemptSeed = $seed;
        $attempts = max(1, $this->maxRetries + 1);
        $lastError = 'sin respuesta';
        $outage = false;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $bytes = $this->fetch($prompt, $attemptSeed, $negativePrompt);
            } catch (LockTimeoutException) {
                $lastError = 'candado de rate limit agotado';
                $outage = false;
                $this->backoff($attempt, $attempts);

                continue;
            } catch (ConnectionException) {
                $lastError = 'timeout o conexión';
                $outage = true;
                $this->backoff($attempt, $attempts);

                continue;
            } catch (RequestException $exception) {
                $status = $exception->response->status();
                $lastError = "HTTP {$status}";
                $outage = $this->isRetryableStatus($status);

                if (! $outage) {
                    break;
                }

                $this->backoff($attempt, $attempts);

                continue;
            } catch (Throwable $exception) {
                $lastError = $exception->getMessage();
                $outage = false;
                $this->backoff($attempt, $attempts);

                continue;
            }

            if ($this->isValidImageData($bytes)) {
                $this->files->ensureDirectoryExists($this->cacheDirectory);
                $this->files->put($path, $bytes);
                $this->reportSize($path, $prompt);
                $this->trimLetterbox($path);

                return ['path' => $path, 'error' => '', 'outage' => false];
            }

            $lastError = 'el cuerpo no es una imagen válida';
            $outage = false;
            $attemptSeed = $this->nextSeed($seed, $attempt);
            $this->backoff($attempt, $attempts);
        }

        return ['path' => null, 'error' => $lastError, 'outage' => $outage];
    }

    private function waitForProvider(int $probe, string $error): void
    {
        $this->logger->warning('El proveedor de imágenes no responde: se espera en vez de escribir un marcador.', [
            'error' => $error,
            'intento' => $probe.'/'.$this->outageMaxProbes,
            'espera' => $this->outageProbeSeconds.' s',
        ]);

        Sleep::sleep($this->outageProbeSeconds);
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

    private function fetch(string $prompt, int $seed, string $negativePrompt = ''): string
    {
        $lockSeconds = $this->timeout + (int) ceil($this->rateLimitSeconds) + 15;

        // El candado serializa a todos los workers. El wait solo cubre el resto de la ventana en caché compartida.
        return $this->cache->lock(self::LOCK_KEY, $lockSeconds)
            ->block($this->timeout + 60, function () use ($prompt, $seed, $negativePrompt): string {
                $this->waitForSlot();
                $this->reserveSlot();

                $query = [
                    'width' => $this->width,
                    'height' => $this->height,
                    'seed' => $seed,
                    'model' => $this->model,
                    'nologo' => 'true',
                ];

                // Rama negativa de verdad. Vacía no se manda: un negative_prompt en blanco
                // es un parámetro más que ensucia la URL y la clave de caché del proveedor.
                if ($negativePrompt !== '') {
                    $query['negative_prompt'] = $negativePrompt;
                }

                $response = $this->http
                    ->timeout($this->timeout)
                    ->get($this->endpoint($prompt), $query);

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

    private function cachePath(string $prompt, int $seed, string $negativePrompt = ''): string
    {
        return $this->cacheDirectory.DIRECTORY_SEPARATOR.sha1($this->fingerprint($prompt, $seed, $negativePrompt)).'.jpg';
    }

    /**
     * La resolución entra en la clave porque una imagen de 1024 px y otra de 1920 px con el mismo
     * prompt y la misma semilla son ficheros distintos: sin ella, cambiar la resolución serviría
     * las viejas y nadie sabría por qué el vídeo no mejora.
     */
    private function fingerprint(string $prompt, int $seed, string $negativePrompt = ''): string
    {
        return $prompt.(string) $seed.'|'.$this->width.'x'.$this->height.'|'.$negativePrompt;
    }

    /**
     * El proveedor recorta la petición en silencio y responde 200, así que un catálogo entero por
     * debajo de la resolución pedida pasa desapercibido hasta que el vídeo se ve blando. Un aviso
     * por ejecución basta: el techo es del proveedor y repetirlo cien veces solo tapa el resto.
     */
    private function reportSize(string $path, string $prompt): void
    {
        if ($this->sizeReported) {
            return;
        }

        $size = $this->dimensions($path);

        if ($size === null || ($size[0] === $this->width && $size[1] === $this->height)) {
            return;
        }

        $this->sizeReported = true;

        $this->logger->warning('El proveedor devolvió la imagen a otro tamaño del pedido.', [
            'requested' => $this->width.'x'.$this->height,
            'returned' => $size[0].'x'.$size[1],
            'model' => $this->model,
            'prompt' => mb_substr($prompt, 0, 160),
        ]);
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
        return $this->dimensions($path) !== null;
    }

    /**
     * @return array{0: int, 1: int}|null
     */
    private function dimensions(string $path): ?array
    {
        $info = @getimagesize($path);

        if ($info === false) {
            return null;
        }

        $width = (int) ($info[0] ?? 0);
        $height = (int) ($info[1] ?? 0);

        if ($width <= 0 || $height <= 0) {
            return null;
        }

        return [$width, $height];
    }

    /**
     * El modelo pinta a veces la escena como una copia enmarcada: bandas claras y planas arriba y
     * abajo que en el vídeo se ven como dos franjas blancas cruzando el plano. Se recorta lo que
     * queda dentro, se ajusta al aspecto pedido y se reescala. La imagen pierde un poco de encuadre
     * y gana el plano entero.
     */
    private function trimLetterbox(string $path): void
    {
        $image = @imagecreatefromjpeg($path);

        if ($image === false) {
            return;
        }

        $width = imagesx($image);
        $height = imagesy($image);
        $bands = $this->bands($image, $width, $height);

        if ($bands['top'] + $bands['bottom'] + $bands['left'] + $bands['right'] === 0) {
            return;
        }

        if ($this->bandsTooBig($bands, $width, $height)) {
            $this->logger->warning('El borde claro de la imagen ocupa demasiado para ser un marco; se deja intacta.', [
                'path' => basename($path),
                'bands' => $bands,
                'size' => $width.'x'.$height,
            ]);

            return;
        }

        $innerWidth = $width - $bands['left'] - $bands['right'];
        $innerHeight = $height - $bands['top'] - $bands['bottom'];
        [$cropWidth, $cropHeight] = $this->largestCrop($innerWidth, $innerHeight);

        $canvas = imagecreatetruecolor($this->width, $this->height);

        if ($canvas === false) {
            return;
        }

        imagecopyresampled(
            $canvas,
            $image,
            0,
            0,
            $bands['left'] + (int) round(($innerWidth - $cropWidth) / 2),
            $bands['top'] + (int) round(($innerHeight - $cropHeight) / 2),
            $this->width,
            $this->height,
            $cropWidth,
            $cropHeight,
        );

        if (imagejpeg($canvas, $path, $this->jpegQuality) === false) {
            $this->logger->warning('No se pudo reescribir la imagen recortada; se queda con su marco.', [
                'path' => basename($path),
            ]);

            return;
        }

        $this->logger->info('Imagen con marco recortada.', [
            'path' => basename($path),
            'bands' => $bands,
        ]);
    }

    /**
     * @return array{top: int, bottom: int, left: int, right: int}
     */
    private function bands(GdImage $image, int $width, int $height): array
    {
        $top = 0;
        while ($top < $height && $this->isBandRow($image, $top, $width)) {
            $top++;
        }

        // Una imagen entera plana no tiene marco: no tiene nada, y bandsTooBig la deja en paz.
        if ($top >= $height) {
            return ['top' => $top, 'bottom' => 0, 'left' => 0, 'right' => 0];
        }

        $bottom = 0;
        while ($bottom < $height - $top && $this->isBandRow($image, $height - 1 - $bottom, $width)) {
            $bottom++;
        }

        $left = 0;
        while ($left < $width && $this->isBandColumn($image, $left, $height)) {
            $left++;
        }

        if ($left >= $width) {
            return ['top' => $top, 'bottom' => $bottom, 'left' => $left, 'right' => 0];
        }

        $right = 0;
        while ($right < $width - $left && $this->isBandColumn($image, $width - 1 - $right, $height)) {
            $right++;
        }

        return ['top' => $top, 'bottom' => $bottom, 'left' => $left, 'right' => $right];
    }

    /**
     * @param  array{top: int, bottom: int, left: int, right: int}  $bands
     */
    private function bandsTooBig(array $bands, int $width, int $height): bool
    {
        $vertical = (int) floor($height * $this->bandMaxRatio);
        $horizontal = (int) floor($width * $this->bandMaxRatio);

        return $bands['top'] > $vertical
            || $bands['bottom'] > $vertical
            || $bands['left'] > $horizontal
            || $bands['right'] > $horizontal;
    }

    /**
     * El rectángulo más grande con el aspecto pedido que cabe dentro de lo que quedó tras el marco.
     * Reescalar sin esto deformaría la escena, que es peor que perder unos píxeles de encuadre.
     *
     * @return array{0: int, 1: int}
     */
    private function largestCrop(int $innerWidth, int $innerHeight): array
    {
        $target = $this->width / max(1, $this->height);

        if ($innerWidth / max(1, $innerHeight) > $target) {
            return [max(1, (int) round($innerHeight * $target)), $innerHeight];
        }

        return [$innerWidth, max(1, (int) round($innerWidth / $target))];
    }

    private function isBandRow(GdImage $image, int $y, int $width): bool
    {
        $samples = [];

        for ($x = 0; $x < $width; $x += self::BAND_SAMPLE_STEP) {
            $samples[] = $this->brightness($image, $x, $y);
        }

        return $this->isBand($samples);
    }

    private function isBandColumn(GdImage $image, int $x, int $height): bool
    {
        $samples = [];

        for ($y = 0; $y < $height; $y += self::BAND_SAMPLE_STEP) {
            $samples[] = $this->brightness($image, $x, $y);
        }

        return $this->isBand($samples);
    }

    /**
     * Un marco es claro y plano. Solo claro no basta: la niebla también lo es, pero tiene textura.
     *
     * @param  list<float>  $samples
     */
    private function isBand(array $samples): bool
    {
        $count = count($samples);

        if ($count === 0) {
            return false;
        }

        $mean = array_sum($samples) / $count;

        if ($mean < $this->bandBrightness) {
            return false;
        }

        $variance = 0.0;

        foreach ($samples as $sample) {
            $variance += ($sample - $mean) ** 2;
        }

        return sqrt($variance / $count) <= $this->bandUniformity;
    }

    private function brightness(GdImage $image, int $x, int $y): float
    {
        $color = imagecolorat($image, $x, $y);

        return 0.299 * (($color >> 16) & 0xFF)
            + 0.587 * (($color >> 8) & 0xFF)
            + 0.114 * ($color & 0xFF);
    }

    private function placeholder(string $prompt, int $seed): string
    {
        $path = $this->cacheDirectory.DIRECTORY_SEPARATOR.'placeholder-'.sha1($this->fingerprint($prompt, $seed)).'.jpg';
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
