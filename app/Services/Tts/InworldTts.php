<?php

declare(strict_types=1);

namespace App\Services\Tts;

use App\Contracts\TextToSpeech;
use App\Exceptions\TtsUnavailableException;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\RequestException;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Motor de voz sobre la API de Inworld.
 *
 * A diferencia de Kokoro no hay proceso que arrancar ni modelo que cargar: basta con la
 * credencial. La caída de red aparece al sintetizar, no antes.
 */
final readonly class InworldTts implements TextToSpeech
{
    /**
     * LINEAR16 es el único formato sin comprimir que devuelve la cabecera WAV incluida en el
     * endpoint no-streaming. PCM entrega las mismas muestras pero desnudas, y NarrationAssembler
     * necesita que ffprobe pueda leer el ritmo y los canales del primer clip.
     */
    private const ENCODING = 'LINEAR16';

    private const ENDPOINT = '/tts/v1/voice';

    public function __construct(
        private Factory $http,
        private Filesystem $files,
        private LoggerInterface $logger,
        private string $apiKey,
        private string $baseUrl,
        private string $model,
        private string $voice,
        private float $speed,
        private string $language,
        private string $deliveryMode,
        private string $instruction,
        private int $sampleRate,
        private bool $enhanceGeneration,
        private int $timeout,
        private string $cacheDirectory,
        private int $maxCharacters,
        private float $minSpeed,
        private float $maxSpeed,
        private int $retryTimes,
        private int $retrySleepMs,
        /** @var list<int> */
        private array $retryStatuses,
        private string $ffmpegBinary,
        private int $ffmpegTimeout,
        private bool $trimSilence,
        private float $trimThresholdDb,
        private float $trimGuardSeconds,
        private string $trimmedDirectory,
    ) {
        if ($speed < $minSpeed || $speed > $maxSpeed) {
            throw new InvalidArgumentException(sprintf(
                'La velocidad de Inworld debe estar entre %s y %s; llegó %s. '
                .'Ajusta INWORLD_SPEED y ejecuta php artisan config:clear.',
                $this->number($minSpeed),
                $this->number($maxSpeed),
                $this->number($speed),
            ));
        }
    }

    /**
     * Devuelve la ruta absoluta del WAV que hay que ensamblar. La caché es el único freno al
     * gasto: cada acierto es una petición que no se factura.
     *
     * Son dos capas. La cruda guarda lo que respondió la API bajo una clave que solo depende de
     * lo que se le pidió; la recortada cuelga de ella y añade los parámetros del recorte. Así
     * cambiar el umbral vuelve a derivar en local y no gasta cuota.
     *
     * @param  array{voice?: string, speed?: float|int|string, skip_cache?: bool}  $options
     */
    public function synthesize(string $text, array $options = []): string
    {
        $voice = (string) ($options['voice'] ?? $this->voice);
        $speed = (float) ($options['speed'] ?? $this->speed);
        $skipCache = (bool) ($options['skip_cache'] ?? false);
        $raw = $this->cachePath($text, $voice, $speed);

        if ($skipCache || ! $this->files->exists($raw)) {
            $this->files->ensureDirectoryExists($this->cacheDirectory);
            $this->files->put($raw, $this->requestWav($text, $voice, $speed));
        }

        if (! $this->trimSilence) {
            return $raw;
        }

        $trimmed = $this->trimmedPath($text, $voice, $speed);

        if ($skipCache || ! $this->files->exists($trimmed)) {
            $this->trim($raw, $trimmed);
        }

        return $trimmed;
    }

    /**
     * Mira solo la capa cruda, que es la que cuesta dinero. Si está y falta la recortada, el
     * trabajo que queda es un ffmpeg en local, y para el recuento que imprime story:narrate esa
     * frase ya está cacheada: no se va a facturar.
     *
     * @param  array{voice?: string, speed?: float|int|string}  $options
     */
    public function isCached(string $text, array $options = []): bool
    {
        $voice = (string) ($options['voice'] ?? $this->voice);
        $speed = (float) ($options['speed'] ?? $this->speed);

        return $this->files->exists($this->cachePath($text, $voice, $speed));
    }

    /**
     * Solo comprueba la credencial, sin tocar la red: story:doctor y el preflight se ejecutan a
     * menudo, y el tramo gratuito se mide en caracteres. Una síntesis de cortesía por diagnóstico
     * gasta cuota para responder algo que la primera frase real ya responde.
     */
    public function isAvailable(): bool
    {
        return trim($this->apiKey) !== '';
    }

    private function requestWav(string $text, string $voice, float $speed): string
    {
        $length = mb_strlen($text);

        if ($length > $this->maxCharacters) {
            throw new TtsUnavailableException(sprintf(
                'Inworld admite %d caracteres por petición y esta frase trae %d. '
                .'Pártela en el guion antes de narrar.',
                $this->maxCharacters,
                $length,
            ));
        }

        try {
            $response = $this->http
                ->timeout($this->timeout)
                // throw: false para quedarnos con la respuesta fallida y poder decir qué status
                // trajo y qué cuerpo. Los reintentos siguen ocurriendo: lo que se desactiva es
                // que la última vuelta lance en vez de devolver.
                ->retry(
                    $this->retryTimes,
                    $this->retrySleepMs,
                    fn (Throwable $exception): bool => $this->shouldRetry($exception, $text),
                    throw: false,
                )
                ->withHeaders(['Authorization' => 'Basic '.$this->apiKey])
                ->asJson()
                ->post($this->baseUrl.self::ENDPOINT, $this->payload($text, $voice, $speed));
        } catch (ConnectionException $exception) {
            throw $this->failed('no responde: '.$exception->getMessage(), $text, $exception);
        } catch (RequestException $exception) {
            throw $this->failed('rechazó la petición: '.$exception->getMessage(), $text, $exception);
        }

        if (! $response->successful()) {
            throw $this->failed(sprintf(
                'respondió HTTP %d tras %d intento(s): %s',
                $response->status(),
                $this->retryTimes,
                trim($response->body()),
            ), $text);
        }

        $encoded = $response->json('audioContent');

        if (! is_string($encoded) || $encoded === '') {
            throw $this->failed('respondió sin audioContent.', $text);
        }

        $wav = base64_decode($encoded, true);

        if ($wav === false || $wav === '') {
            throw $this->failed('devolvió un audioContent que no es base64 válido.', $text);
        }

        $this->logUsage($response->json('usage'), $voice, $length);

        return $wav;
    }

    /**
     * @return array{
     *     text: string,
     *     voiceId: string,
     *     modelId: string,
     *     language: string,
     *     deliveryMode: string,
     *     enhanceGeneration: bool,
     *     audioConfig: array{audioEncoding: string, sampleRateHertz: int, speakingRate: float},
     *     instruction?: string
     * }
     */
    private function payload(string $text, string $voice, float $speed): array
    {
        $payload = [
            'text' => $text,
            'voiceId' => $voice,
            'modelId' => $this->model,
            'language' => $this->language,
            'deliveryMode' => $this->deliveryMode,
            'enhanceGeneration' => $this->enhanceGeneration,
            'audioConfig' => [
                'audioEncoding' => self::ENCODING,
                'sampleRateHertz' => $this->sampleRate,
                'speakingRate' => $speed,
            ],
        ];

        if ($this->instruction !== '') {
            $payload['instruction'] = $this->instruction;
        }

        return $payload;
    }

    /**
     * La clave tiene que cubrir todo lo que cambia la salida. Si se queda corta, editar una
     * frase sirve audio viejo para las demás y no hay forma de notarlo salvo escuchándolo.
     */
    private function cachePath(string $text, string $voice, float $speed): string
    {
        $key = sha1(implode("\0", [
            $text,
            $voice,
            $this->number($speed),
            $this->model,
            $this->language,
            $this->deliveryMode,
            $this->instruction,
            (string) $this->sampleRate,
            self::ENCODING,
            $this->enhanceGeneration ? '1' : '0',
        ]));

        return $this->cacheDirectory.DIRECTORY_SEPARATOR.$key.'.wav';
    }

    /**
     * Cuelga de la clave cruda y le suma lo que cambia el recorte. Derivarla en vez de rehacerla
     * es lo que permite mover el umbral sin volver a pedir el audio.
     */
    private function trimmedPath(string $text, string $voice, float $speed): string
    {
        $key = sha1(implode("\0", [
            basename($this->cachePath($text, $voice, $speed), '.wav'),
            $this->number($this->trimThresholdDb),
            $this->number($this->trimGuardSeconds),
        ]));

        return $this->trimmedDirectory.DIRECTORY_SEPARATOR.$key.'.wav';
    }

    private function trim(string $source, string $destination): void
    {
        $this->files->ensureDirectoryExists(dirname($destination));

        $process = new Process([
            $this->ffmpegBinary,
            '-v', 'error',
            '-y',
            '-i', $source,
            '-af', $this->trimFilter(),
            '-c:a', 'pcm_s16le',
            $destination,
        ], timeout: (float) $this->ffmpegTimeout);

        $process->run();

        if ($process->isSuccessful() && $this->files->exists($destination)) {
            return;
        }

        $error = trim($process->getErrorOutput());

        throw new RuntimeException(sprintf(
            'ffmpeg no pudo recortar el silencio de %s: %s',
            basename($source),
            $error !== '' ? $error : 'terminó con código '.$process->getExitCode().' y sin salida de error.',
        ));
    }

    /**
     * silenceremove solo sabe mirar el principio del flujo, así que la cola se recorta dando la
     * vuelta al audio, quitándole otra vez el principio y devolviéndolo a su orden.
     *
     * Deliberadamente sin stop_periods: con él se recortarían también los silencios interiores,
     * los de las comas, y ésos son la interpretación de la voz.
     */
    private function trimFilter(): string
    {
        $edge = sprintf(
            'silenceremove=start_periods=1:start_threshold=%sdB:start_silence=%s',
            $this->number($this->trimThresholdDb),
            $this->number($this->trimGuardSeconds),
        );

        return $edge.',areverse,'.$edge.',areverse';
    }

    /**
     * El tramo gratuito se mide en caracteres, así que conviene poder sumarlos desde el log.
     */
    private function logUsage(mixed $usage, string $voice, int $fallbackLength): void
    {
        $characters = is_array($usage) ? (int) ($usage['processedCharactersCount'] ?? 0) : 0;

        $this->logger->info('Inworld sintetizó una frase.', [
            'characters' => $characters > 0 ? $characters : $fallbackLength,
            'model' => $this->model,
            'voice' => $voice,
        ]);
    }

    /**
     * Solo se reintenta lo que puede salir bien a la segunda. Deja rastro en el log de cada
     * vuelta, que es lo que faltaba cuando una narración de 252 frases murió en la 146 sin
     * escribir una sola línea.
     */
    private function shouldRetry(Throwable $exception, string $text): bool
    {
        $status = $exception instanceof RequestException
            ? $exception->response->status()
            : 0;
        $retrying = $exception instanceof ConnectionException
            || in_array($status, $this->retryStatuses, true);

        $this->logger->warning('Inworld falló una petición.', [
            'status' => $status,
            'retrying' => $retrying,
            'reason' => $exception->getMessage(),
            'sentence' => $this->snippet($text),
        ]);

        return $retrying;
    }

    /**
     * El motivo va al log además de a la excepción: story:narrate imprime una barra de progreso
     * que se come el mensaje, así que sin esto un fallo se queda en un código de salida a secas.
     */
    private function failed(string $reason, string $text, ?Throwable $previous = null): TtsUnavailableException
    {
        $this->logger->error('Inworld no pudo sintetizar una frase.', [
            'reason' => $reason,
            'voice' => $this->voice,
            'sentence' => $this->snippet($text),
        ]);

        return new TtsUnavailableException(
            'La API de Inworld en '.$this->baseUrl.' '.$reason,
            previous: $previous,
        );
    }

    private function snippet(string $text): string
    {
        return mb_strlen($text) > 80 ? mb_substr($text, 0, 77).'...' : $text;
    }

    private function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
