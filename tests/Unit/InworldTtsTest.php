<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\TtsUnavailableException;
use App\Services\Tts\InworldTts;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Psr\Log\NullLogger;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class InworldTtsTest extends TestCase
{
    private const WAV = "RIFF\x24\x00\x00\x00WAVEfmt fake-pcm-bytes";

    private const INSTRUCTION = 'Narrate with quiet, restrained dread. Do not drag.';

    private string $cacheDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cacheDir = storage_path('app/testing/inworld-'.bin2hex(random_bytes(4)));

        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->cacheDir);

        parent::tearDown();
    }

    public function test_it_decodes_the_base64_audio_and_caches_the_wav(): void
    {
        $this->fakeApi();

        $path = $this->driver()->synthesize('The door closed.');

        $this->assertStringStartsWith($this->cacheDir, $path);
        $this->assertSame(self::WAV, (new Filesystem)->get($path));
        Http::assertSentCount(1);
    }

    public function test_it_serves_the_cached_wav_without_calling_the_api(): void
    {
        $this->fakeApi();
        $driver = $this->driver();

        $this->assertFalse($driver->isCached('The door closed.'));
        $first = $driver->synthesize('The door closed.');

        $this->assertTrue($driver->isCached('The door closed.'));
        $this->assertSame($first, $driver->synthesize('The door closed.'));
        Http::assertSentCount(1);
    }

    public function test_skip_cache_asks_the_api_again(): void
    {
        $this->fakeApi();
        $driver = $this->driver();

        $driver->synthesize('The door closed.');
        $driver->synthesize('The door closed.', ['skip_cache' => true]);

        Http::assertSentCount(2);
    }

    /**
     * Si la clave no cubriera la dirección de interpretación, cambiarla serviría el audio viejo
     * y no habría forma de notarlo salvo escuchándolo.
     */
    public function test_the_cache_key_covers_the_instruction(): void
    {
        $this->fakeApi();

        $first = $this->driver()->synthesize('The door closed.');
        $second = $this->driver(['instruction' => 'Narrate cheerfully.'])->synthesize('The door closed.');

        $this->assertNotSame($first, $second);
        Http::assertSentCount(2);
    }

    public function test_the_cache_key_covers_the_voice_and_the_speed(): void
    {
        $this->fakeApi();
        $driver = $this->driver();

        $base = $driver->synthesize('The door closed.');
        $otherVoice = $driver->synthesize('The door closed.', ['voice' => 'Levi']);
        $otherSpeed = $driver->synthesize('The door closed.', ['speed' => 1.2]);

        $this->assertNotSame($base, $otherVoice);
        $this->assertNotSame($base, $otherSpeed);
        $this->assertNotSame($otherVoice, $otherSpeed);
    }

    public function test_it_sends_the_payload_the_api_expects(): void
    {
        $this->fakeApi();

        $this->driver()->synthesize('The door closed.');

        Http::assertSent(function (Request $request): bool {
            $body = $request->data();

            return $request->url() === 'https://api.inworld.ai/tts/v1/voice'
                && $request->hasHeader('Authorization', 'Basic test-inworld-key')
                && $body['text'] === 'The door closed.'
                && $body['voiceId'] === 'Blake'
                && $body['modelId'] === 'inworld-tts-2'
                && $body['language'] === 'en-US'
                && $body['deliveryMode'] === 'BALANCED'
                && $body['instruction'] === self::INSTRUCTION
                && $body['enhanceGeneration'] === true
                && $body['audioConfig']['audioEncoding'] === 'LINEAR16'
                && $body['audioConfig']['sampleRateHertz'] === 48000
                && $body['audioConfig']['speakingRate'] === 1.1;
        });
    }

    public function test_it_omits_the_instruction_when_it_is_empty(): void
    {
        $this->fakeApi();

        $this->driver(['instruction' => ''])->synthesize('The door closed.');

        Http::assertSent(static fn (Request $request): bool => ! array_key_exists('instruction', $request->data()));
    }

    public function test_it_is_unavailable_without_a_credential(): void
    {
        $this->assertTrue($this->driver()->isAvailable());
        $this->assertFalse($this->driver(['apiKey' => ''])->isAvailable());
        $this->assertFalse($this->driver(['apiKey' => '   '])->isAvailable());
    }

    public function test_it_throws_when_the_api_rejects_the_request(): void
    {
        Http::fake([
            'api.inworld.ai/*' => Http::response(
                ['code' => 3, 'message' => 'audioConfig.speakingRate should be within the range of 0.5 to 1.5.'],
                400,
            ),
        ]);

        $this->expectException(TtsUnavailableException::class);
        $this->expectExceptionMessageMatches('/Inworld/');

        $this->driver()->synthesize('The door closed.');
    }

    public function test_it_throws_when_the_response_brings_no_audio(): void
    {
        Http::fake(['api.inworld.ai/*' => Http::response(['usage' => ['processedCharactersCount' => 16]])]);

        $this->expectException(TtsUnavailableException::class);
        $this->expectExceptionMessage('respondió sin audioContent');

        $this->driver()->synthesize('The door closed.');
    }

    public function test_it_refuses_a_text_longer_than_the_api_limit(): void
    {
        $this->fakeApi();

        $this->expectException(TtsUnavailableException::class);
        $this->expectExceptionMessage('2000 caracteres por petición');

        $this->driver()->synthesize(str_repeat('a', 2001));
    }

    /**
     * Fallar al construir evita descubrir el 400 en la primera frase de cien, después de que el
     * preflight haya dado verde.
     */
    public function test_it_refuses_a_speed_outside_the_api_range(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('debe estar entre 0.5 y 1.5; llegó 1.8');

        $this->driver(['speed' => 1.8]);
    }

    /**
     * Una historia son cientos de peticiones seguidas: un 503 en la frase 146 no puede tirar la
     * narración entera, que es justo lo que pasó la primera vez que se narró de verdad.
     */
    public function test_a_transient_failure_is_retried_until_it_answers(): void
    {
        Http::fake(['api.inworld.ai/*' => Http::sequence()
            ->push(['message' => 'unavailable'], 503)
            ->push(['message' => 'slow down'], 429)
            ->push(['audioContent' => base64_encode(self::WAV)]),
        ]);

        $path = $this->driver(['retryTimes' => 3])->synthesize('The door closed.');

        $this->assertSame(self::WAV, (new Filesystem)->get($path));
        Http::assertSentCount(3);
    }

    public function test_a_rejected_request_is_not_retried(): void
    {
        Http::fake(['api.inworld.ai/*' => Http::response(
            ['code' => 3, 'message' => 'audioConfig.speakingRate should be within the range.'],
            400,
        )]);

        try {
            $this->driver(['retryTimes' => 3])->synthesize('The door closed.');
            $this->fail('Se esperaba una TtsUnavailableException.');
        } catch (TtsUnavailableException) {
            // Un 400 es la petición, no el proveedor: repetirla da el mismo 400.
            Http::assertSentCount(1);
        }
    }

    public function test_it_gives_up_after_exhausting_the_retries_and_says_the_status(): void
    {
        Http::fake(['api.inworld.ai/*' => Http::response(['message' => 'unavailable'], 503)]);

        $this->expectException(TtsUnavailableException::class);
        $this->expectExceptionMessage('respondió HTTP 503 tras 3 intento(s)');

        $this->driver(['retryTimes' => 3])->synthesize('The door closed.');
    }

    public function test_it_returns_the_raw_clip_when_trimming_is_disabled(): void
    {
        $this->fakeApi();

        $path = $this->driver()->synthesize('The door closed.');

        $this->assertStringNotContainsString('trimmed', $path);
        $this->assertSame(self::WAV, (new Filesystem)->get($path));
    }

    /**
     * Inworld mete 1,685 s de silencio de media en cada frase, y con 252 frases eso alargaba la
     * narración 2:09. Lo que se recorta son los bordes, nunca las pausas interiores.
     */
    public function test_it_trims_the_silence_at_both_ends(): void
    {
        $this->fakeApiWithRealAudio(lead: 0.5, tone: 1.0, tail: 0.6);

        $path = $this->driver(['trim' => true])->synthesize('The door closed.');

        $this->assertStringContainsString('trimmed', $path);
        // El tono más los 0.04 s de guarda que se dejan en cada borde.
        $this->assertEqualsWithDelta(1.08, $this->duration($path), 0.12);
    }

    public function test_it_leaves_the_interior_pauses_alone(): void
    {
        $this->fakeApiWithRealAudio(lead: 0.5, tone: 0.4, tail: 0.5, interior: 1.0);

        $path = $this->driver(['trim' => true])->synthesize('The door closed.');

        // Los dos tonos, el hueco de 1 s que hay entre ellos y las dos guardas. Si se hubiera
        // colado stop_periods, el hueco interior desaparecería y esto bajaría a unos 0.88 s.
        $this->assertEqualsWithDelta(1.88, $this->duration($path), 0.15);
    }

    /**
     * Es lo que hace que mover el umbral no cueste dinero: la respuesta cruda se queda con su
     * clave de siempre y solo se vuelve a derivar en local.
     */
    public function test_changing_the_threshold_re_derives_without_calling_the_api(): void
    {
        $this->fakeApiWithRealAudio(lead: 0.5, tone: 1.0, tail: 0.5);

        $first = $this->driver(['trim' => true])->synthesize('The door closed.');
        $second = $this->driver(['trim' => true, 'thresholdDb' => -30.0])->synthesize('The door closed.');

        $this->assertNotSame($first, $second);
        $this->assertFileExists($first);
        $this->assertFileExists($second);
        Http::assertSentCount(1);
    }

    public function test_it_reuses_the_trimmed_clip_on_a_second_call(): void
    {
        $this->fakeApiWithRealAudio(lead: 0.5, tone: 1.0, tail: 0.5);
        $driver = $this->driver(['trim' => true]);

        $first = $driver->synthesize('The door closed.');
        $stamp = filemtime($first);
        $second = $driver->synthesize('The door closed.');

        $this->assertSame($first, $second);
        $this->assertSame($stamp, filemtime($second));
        Http::assertSentCount(1);
    }

    /**
     * story:narrate cuenta aciertos de caché para decir cuánto se va a facturar, así que lo que
     * importa es si está la respuesta cruda: derivar el recorte es ffmpeg en local.
     */
    public function test_is_cached_looks_at_the_billable_layer(): void
    {
        $this->fakeApiWithRealAudio(lead: 0.5, tone: 1.0, tail: 0.5);
        $driver = $this->driver(['trim' => true]);

        $this->assertFalse($driver->isCached('The door closed.'));
        $driver->synthesize('The door closed.');

        $this->assertTrue($driver->isCached('The door closed.'));
    }

    private function fakeApi(): void
    {
        Http::fake(['api.inworld.ai/*' => Http::response([
            'audioContent' => base64_encode(self::WAV),
            'usage' => ['processedCharactersCount' => 16, 'modelId' => 'inworld-tts-2'],
        ])]);
    }

    /**
     * El recorte llega apagado salvo que el caso lo pida. La mayoría de estas pruebas miran la
     * petición y la clave de caché, y encender ffmpeg sobre un WAV de mentira solo añadiría una
     * dependencia que no están comprobando. Los casos del recorte lo encienden y traen audio real.
     *
     * @param  array{apiKey?: string, speed?: float, instruction?: string, trim?: bool, thresholdDb?: float, guardSeconds?: float, retryTimes?: int}  $overrides
     */
    private function driver(array $overrides = []): InworldTts
    {
        return new InworldTts(
            http: $this->app->make(Factory::class),
            files: new Filesystem,
            logger: new NullLogger,
            apiKey: $overrides['apiKey'] ?? 'test-inworld-key',
            baseUrl: 'https://api.inworld.ai',
            model: 'inworld-tts-2',
            voice: 'Blake',
            speed: $overrides['speed'] ?? 1.1,
            language: 'en-US',
            deliveryMode: 'BALANCED',
            instruction: $overrides['instruction'] ?? self::INSTRUCTION,
            sampleRate: 48000,
            enhanceGeneration: true,
            timeout: 120,
            cacheDirectory: $this->cacheDir,
            maxCharacters: 2000,
            minSpeed: 0.5,
            maxSpeed: 1.5,
            retryTimes: $overrides['retryTimes'] ?? 1,
            retrySleepMs: 0,
            retryStatuses: [429, 500, 502, 503, 504],
            ffmpegBinary: 'ffmpeg',
            ffmpegTimeout: 60,
            trimSilence: $overrides['trim'] ?? false,
            trimThresholdDb: $overrides['thresholdDb'] ?? -50.0,
            trimGuardSeconds: $overrides['guardSeconds'] ?? 0.04,
            trimmedDirectory: $this->cacheDir.DIRECTORY_SEPARATOR.'trimmed',
        );
    }

    /**
     * Un WAV con silencio de sobra en los dos bordes, que es la forma que traen los clips de
     * Inworld. Con `interior` se parte el tono en dos para poder comprobar que la pausa de dentro
     * sobrevive al recorte. Sin audio de verdad no hay nada que medir.
     *
     * Http::fake acumula stubs en vez de sustituirlos y para un mismo patrón gana el primero, así
     * que esto tiene que quedar en una sola llamada por prueba.
     */
    private function fakeApiWithRealAudio(float $lead, float $tone, float $tail, float $interior = 0.0): void
    {
        /** @var list<array{0: int, 1: float}> $segments */
        $segments = $interior > 0.0
            ? [[0, $lead], [1, $tone], [0, $interior], [1, $tone], [0, $tail]]
            : [[0, $lead], [1, $tone], [0, $tail]];

        $trims = [];
        $labels = '';

        foreach ($segments as $index => [$input, $duration]) {
            $trims[] = sprintf('[%d]atrim=duration=%.3f[s%d]', $input, $duration, $index);
            $labels .= sprintf('[s%d]', $index);
        }

        $files = new Filesystem;
        $files->ensureDirectoryExists($this->cacheDir);
        $source = $this->cacheDir.DIRECTORY_SEPARATOR.'source.wav';

        (new Process([
            'ffmpeg', '-v', 'error', '-y',
            '-f', 'lavfi', '-i', 'anullsrc=r=48000:cl=mono',
            '-f', 'lavfi', '-i', 'sine=frequency=440:sample_rate=48000',
            '-filter_complex', sprintf(
                '%s;%sconcat=n=%d:v=0:a=1',
                implode(';', $trims),
                $labels,
                count($segments),
            ),
            '-c:a', 'pcm_s16le', $source,
        ]))->mustRun();

        Http::fake(['api.inworld.ai/*' => Http::response([
            'audioContent' => base64_encode($files->get($source)),
        ])]);
    }

    private function duration(string $path): float
    {
        $process = new Process([
            'ffprobe', '-v', 'error',
            '-show_entries', 'format=duration',
            '-of', 'csv=p=0', $path,
        ]);
        $process->mustRun();

        return (float) trim($process->getOutput());
    }
}
