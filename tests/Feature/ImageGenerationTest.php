<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\ImageGenerator;
use App\Services\Image\PollinationsGenerator;
use App\Services\Image\ShotPlanner;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Stringable;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class ImageGenerationTest extends TestCase
{
    private string $storiesDirectory;

    private string $cacheDirectory;

    /** Llamadas al doble del director: cada una devuelve una description distinta. */
    private int $directorCalls = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
        Http::preventStrayRequests();

        $this->directorCalls = 0;

        $this->storiesDirectory = storage_path('app/testing/stories');
        $this->cacheDirectory = storage_path('app/testing/image-cache');

        $files = $this->app->make(Filesystem::class);
        $files->deleteDirectory(storage_path('app/testing'));
        $files->ensureDirectoryExists($this->storiesDirectory);
        $files->ensureDirectoryExists($this->cacheDirectory);

        config([
            'stories.output_path' => 'testing/stories',
            'stories.images.cache_path' => 'testing/image-cache',
            'stories.images.rate_limit_seconds' => 0,
            'stories.images.max_retries' => 2,
            'stories.images.timeout' => 5,
            'stories.images.provider' => 'pollinations',
        ]);

        $this->app->forgetInstance(ImageGenerator::class);
    }

    protected function tearDown(): void
    {
        $this->app->make(Filesystem::class)->deleteDirectory(storage_path('app/testing'));

        parent::tearDown();
    }

    public function test_second_run_reuses_the_stored_direction_and_makes_zero_requests(): void
    {
        $this->fakePromptResponses($this->jpeg());
        $file = $this->writeStory(8);

        $this->artisan('story:images', ['file' => $file])->assertSuccessful();

        $decoded = $this->plan($file);
        $this->assertSame(ShotPlanner::VERSION, $decoded['plannerVersion'] ?? null);
        $this->assertSame(5, $decoded['plannerVersion']);
        $this->assertSame(
            'Directed hallway fog at dusk 1',
            $decoded['shots'][0]['description'] ?? null,
        );
        $this->assertSame('environment', $decoded['shots'][0]['subject'] ?? null);
        $this->assertSame([], $decoded['shots'][0]['characterSlugs'] ?? null);

        $this->assertGreaterThan(0, $this->promptRequestCount());
        $this->assertGreaterThan(0, $this->directorRequestCount());

        $before = $this->shots($file);

        $this->fakePromptResponses($this->jpeg());
        $this->artisan('story:images', ['file' => $file])->assertSuccessful();

        $this->assertSame(0, $this->directorRequestCount());
        $this->assertSame(0, $this->promptRequestCount());
        $this->assertSame($before, $this->shots($file));
    }

    public function test_redirecting_a_single_shot_leaves_the_other_rows_intact(): void
    {
        $this->fakePromptResponses($this->jpeg());
        $file = $this->writeStory(8);

        $this->artisan('story:images', ['file' => $file])->assertSuccessful();

        $before = $this->shots($file);
        $this->assertGreaterThan(3, count($before));

        $this->fakePromptResponses($this->jpeg());
        $this->artisan('story:images', [
            'file' => $file,
            '--only' => '3',
            '--redirect' => true,
        ])->assertSuccessful();

        $after = $this->shots($file);
        $this->assertCount(count($before), $after);

        foreach ($after as $index => $row) {
            if ((int) $row['order'] === 3) {
                continue;
            }

            $this->assertSame($before[$index], $row, "La fila {$row['order']} no debía cambiar.");
        }

        $this->assertSame('Directed hallway fog at dusk 2', $after[2]['description']);
        $this->assertNotSame($before[2]['prompt'], $after[2]['prompt']);
        $this->assertNotSame($before[2]['imagePath'], $after[2]['imagePath']);
        $this->assertSame(1, $this->promptRequestCount());
    }

    public function test_a_crash_halfway_keeps_the_rows_already_generated(): void
    {
        $this->fakePromptResponses($this->jpeg());
        $file = $this->writeStory(8);

        $this->app->instance(ImageGenerator::class, $this->generatorThatFailsAfter(2));

        $this->artisan('story:images', ['file' => $file])->assertFailed();

        $rows = $this->shots($file);

        $this->assertIsString($rows[0]['imagePath']);
        $this->assertIsString($rows[1]['imagePath']);
        $this->assertNull($rows[2]['imagePath']);
        $this->assertSame('Directed hallway fog at dusk 1', $rows[0]['description']);
    }

    public function test_a_stale_planner_version_redirects_the_shots(): void
    {
        $this->fakePromptResponses($this->jpeg());
        $file = $this->writeStory(8);

        $this->artisan('story:images', ['file' => $file])->assertSuccessful();

        $decoded = $this->plan($file);
        $decoded['plannerVersion'] = ShotPlanner::VERSION - 1;
        file_put_contents(
            $this->planPath($file),
            json_encode($decoded, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n",
        );

        $this->fakePromptResponses($this->jpeg());
        $this->artisan('story:images', ['file' => $file])->assertSuccessful();

        $this->assertGreaterThan(0, $this->directorRequestCount());
        $this->assertSame(ShotPlanner::VERSION, $this->plan($file)['plannerVersion']);
        $this->assertSame('Directed hallway fog at dusk 2', $this->shots($file)[0]['description']);
    }

    public function test_only_range_makes_exactly_three_image_requests(): void
    {
        $this->fakePromptResponses($this->jpeg());
        $file = $this->writeStory(8);

        $this->artisan('story:images', [
            'file' => $file,
            '--only' => '3-5',
        ])->assertSuccessful();

        $this->assertSame(3, $this->promptRequestCount());
    }

    public function test_retries_after_429_and_resolves_on_200(): void
    {
        $jpeg = $this->jpeg();

        $this->fakeHttp(function (Request $request) use ($jpeg) {
            static $promptHits = 0;

            if (str_contains($request->url(), 'generateContent')) {
                return Http::response($this->shotDirectorEnvelope($request), 200);
            }

            if (! str_contains($request->url(), '/prompt/')) {
                return Http::response('ok', 200);
            }

            $promptHits++;

            if ($promptHits === 1) {
                return Http::response('rate limited', 429);
            }

            return Http::response($jpeg, 200);
        });

        $file = $this->writeStory(1);

        $this->artisan('story:images', ['file' => $file])->assertSuccessful();

        $this->assertSame(2, $this->promptRequestCount());
        $this->assertFalse($this->shots($file)[0]['placeholder']);
        $this->assertFileExists((string) $this->shots($file)[0]['imagePath']);
        $this->assertNotFalse(getimagesize((string) $this->shots($file)[0]['imagePath']));
    }

    public function test_persistent_corrupt_response_writes_a_placeholder_and_succeeds(): void
    {
        $this->fakePromptResponses('not-an-image');
        $file = $this->writeStory(1);

        $this->artisan('story:images', ['file' => $file])->assertSuccessful();

        $shot = $this->shots($file)[0];

        $this->assertTrue($shot['placeholder']);
        $this->assertIsString($shot['imagePath']);
        $this->assertStringStartsWith('placeholder-', basename((string) $shot['imagePath']));
        $this->assertNotFalse(getimagesize((string) $shot['imagePath']));
    }

    public function test_asking_for_another_resolution_writes_another_cache_entry(): void
    {
        $this->fakePromptResponses($this->jpeg());

        $first = $this->generatorAt(1024, 576)->generate('a dim hallway in fog', 7);
        $second = $this->generatorAt(1920, 1080)->generate('a dim hallway in fog', 7);

        $this->assertNotSame($first, $second);
        $this->assertFileExists($first);
        $this->assertFileExists($second);
    }

    public function test_the_placeholder_also_depends_on_the_resolution(): void
    {
        $this->fakePromptResponses('not-an-image');

        $first = $this->generatorAt(1024, 576)->generate('a dim hallway in fog', 7);
        $second = $this->generatorAt(1920, 1080)->generate('a dim hallway in fog', 7);

        $this->assertStringStartsWith('placeholder-', basename($first));
        $this->assertStringStartsWith('placeholder-', basename($second));
        $this->assertNotSame($first, $second);
    }

    public function test_it_warns_once_when_the_provider_returns_another_size(): void
    {
        $this->fakePromptResponses($this->jpeg());
        $logger = $this->fakeLogger();

        $generator = $this->generatorAt(1024, 576);
        $generator->generate('a dim hallway in fog', 7);
        $generator->generate('another dim hallway in fog', 8);

        $this->assertCount(1, $logger->warnings);
        $this->assertSame('El proveedor devolvió la imagen a otro tamaño del pedido.', $logger->warnings[0]['message']);
        $this->assertSame('1024x576', $logger->warnings[0]['context']['requested'] ?? null);
        $this->assertSame('16x16', $logger->warnings[0]['context']['returned'] ?? null);
    }

    public function test_it_stays_quiet_when_the_provider_honours_the_size(): void
    {
        $this->fakePromptResponses($this->jpeg());
        $logger = $this->fakeLogger();

        $this->generatorAt(16, 16)->generate('a dim hallway in fog', 7);

        $this->assertSame([], $logger->warnings);
    }

    public function test_it_waits_for_a_fallen_provider_instead_of_writing_a_placeholder(): void
    {
        config([
            'stories.images.outage.probe_seconds' => 1,
            'stories.images.outage.max_probes' => 5,
        ]);

        $jpeg = $this->jpeg();
        $hits = 0;

        $this->fakeHttp(function (Request $request) use (&$hits, $jpeg) {
            if (! str_contains($request->url(), '/prompt/')) {
                return Http::response('ok', 200);
            }

            $hits++;

            // Dos rondas enteras de intentos sin que el proveedor conteste, y a la tercera vuelve.
            if ($hits <= 6) {
                throw new ConnectionException('el proveedor no responde');
            }

            return Http::response($jpeg, 200);
        });

        $logger = $this->fakeLogger();
        $path = $this->generatorAt(16, 16)->generate('a dim hallway in fog', 7);

        $this->assertStringNotContainsString('placeholder-', basename($path));
        $this->assertNotFalse(getimagesize($path));
        $this->assertSame(
            'El proveedor de imágenes no responde: se espera en vez de escribir un marcador.',
            $logger->warnings[0]['message'] ?? null,
        );
        $this->assertSame('1/5', $logger->warnings[0]['context']['intento'] ?? null);
    }

    public function test_it_settles_for_a_placeholder_when_the_outage_never_ends(): void
    {
        config([
            'stories.images.outage.probe_seconds' => 1,
            'stories.images.outage.max_probes' => 2,
        ]);

        $this->fakeHttp(function (Request $request) {
            if (! str_contains($request->url(), '/prompt/')) {
                return Http::response('ok', 200);
            }

            throw new ConnectionException('el proveedor no responde');
        });

        $path = $this->generatorAt(16, 16)->generate('a dim hallway in fog', 7);

        $this->assertStringStartsWith('placeholder-', basename($path));
    }

    public function test_a_corrupt_body_does_not_wait_for_the_provider(): void
    {
        config([
            'stories.images.outage.probe_seconds' => 1,
            'stories.images.outage.max_probes' => 5,
        ]);

        $this->fakePromptResponses('not-an-image');
        $logger = $this->fakeLogger();

        $path = $this->generatorAt(16, 16)->generate('a dim hallway in fog', 7);

        $this->assertStringStartsWith('placeholder-', basename($path));
        $this->assertSame(
            'No se pudo generar la imagen. Se usará un marcador.',
            $logger->warnings[0]['message'] ?? null,
        );
        $this->assertSame(0, $logger->warnings[0]['context']['probes'] ?? null);
    }

    public function test_it_trims_the_white_frame_the_model_paints(): void
    {
        $this->fakePromptResponses($this->letterboxedJpeg(256, 144, 20));

        $path = $this->generatorAt(256, 144)->generate('a dim hallway in fog', 7);

        $this->assertSame([256, 144], $this->sizeOf($path));
        $this->assertSame(0, $this->topBandOf($path));
    }

    public function test_it_leaves_a_clean_image_byte_for_byte(): void
    {
        $bytes = $this->letterboxedJpeg(256, 144, 0);
        $this->fakePromptResponses($bytes);

        $path = $this->generatorAt(256, 144)->generate('a dim hallway in fog', 7);

        $this->assertSame(sha1($bytes), sha1((string) file_get_contents($path)));
    }

    public function test_a_light_border_too_wide_to_be_a_frame_is_left_alone(): void
    {
        $bytes = $this->letterboxedJpeg(256, 144, 60);
        $this->fakePromptResponses($bytes);
        $logger = $this->fakeLogger();

        $path = $this->generatorAt(256, 144)->generate('a dim hallway in fog', 7);

        $this->assertSame(sha1($bytes), sha1((string) file_get_contents($path)));
        $this->assertSame(
            'El borde claro de la imagen ocupa demasiado para ser un marco; se deja intacta.',
            $logger->warnings[0]['message'] ?? null,
        );
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function sizeOf(string $path): array
    {
        $info = getimagesize($path);
        $this->assertNotFalse($info);

        return [(int) $info[0], (int) $info[1]];
    }

    private function topBandOf(string $path): int
    {
        $image = imagecreatefromjpeg($path);
        $this->assertNotFalse($image);

        $width = imagesx($image);
        $rows = 0;

        while ($rows < imagesy($image)) {
            $sum = 0.0;

            for ($x = 0; $x < $width; $x += 8) {
                $color = imagecolorat($image, $x, $rows);
                $sum += 0.299 * (($color >> 16) & 0xFF)
                    + 0.587 * (($color >> 8) & 0xFF)
                    + 0.114 * ($color & 0xFF);
            }

            if ($sum / max(1, (int) ceil($width / 8)) < 200) {
                break;
            }

            $rows++;
        }

        return $rows;
    }

    /**
     * Una escena de gradiente con bandas blancas planas arriba y abajo, como las que devuelve el
     * proveedor cuando decide pintar la imagen enmarcada.
     */
    private function letterboxedJpeg(int $width, int $height, int $band): string
    {
        $image = imagecreatetruecolor($width, $height);

        for ($y = 0; $y < $height; $y++) {
            $tone = 20 + (int) round(60 * $y / max(1, $height - 1));
            $inBand = $y < $band || $y >= $height - $band;
            $color = $inBand
                ? imagecolorallocate($image, 254, 254, 254)
                : imagecolorallocate($image, $tone, $tone, $tone + 10);

            imageline($image, 0, $y, $width - 1, $y, (int) $color);
        }

        ob_start();
        imagejpeg($image, null, 100);

        return (string) ob_get_clean();
    }

    private function generatorAt(int $width, int $height): PollinationsGenerator
    {
        config([
            'stories.images.width' => $width,
            'stories.images.height' => $height,
        ]);

        return $this->app->make(PollinationsGenerator::class);
    }

    private function fakeLogger(): object
    {
        $logger = new class extends AbstractLogger
        {
            /** @var list<array{message: string, context: array<string, mixed>}> */
            public array $warnings = [];

            public function log(mixed $level, string|Stringable $message, array $context = []): void
            {
                if ((string) $level === 'warning') {
                    $this->warnings[] = ['message' => (string) $message, 'context' => $context];
                }
            }
        };

        $this->app->instance(LoggerInterface::class, $logger);

        return $logger;
    }

    /**
     * @param  callable(Request): Response|string  $promptBody
     */
    private function fakePromptResponses(string $promptBody): void
    {
        $this->fakeHttp(function (Request $request) use ($promptBody) {
            if (str_contains($request->url(), 'generateContent')) {
                return Http::response($this->shotDirectorEnvelope($request), 200);
            }

            if (! str_contains($request->url(), '/prompt/')) {
                return Http::response('ok', 200);
            }

            return Http::response($promptBody, 200);
        });
    }

    /**
     * @param  callable(Request): Response  $callback
     */
    private function fakeHttp(callable $callback): void
    {
        Http::fake($callback);
    }

    private function promptRequestCount(): int
    {
        return Http::recorded(
            static fn (Request $request): bool => str_contains($request->url(), '/prompt/'),
        )->count();
    }

    private function directorRequestCount(): int
    {
        return Http::recorded(
            static fn (Request $request): bool => str_contains($request->url(), 'generateContent'),
        )->count();
    }

    private function generatorThatFailsAfter(int $successes): ImageGenerator
    {
        return new class($this->cacheDirectory, $this->jpeg(), $successes) implements ImageGenerator
        {
            private int $calls = 0;

            public function __construct(
                private string $directory,
                private string $bytes,
                private int $successes,
            ) {}

            public function generate(string $prompt, int $seed): string
            {
                $this->calls++;

                if ($this->calls > $this->successes) {
                    throw new RuntimeException('el proveedor de imágenes se cayó');
                }

                $path = $this->directory.DIRECTORY_SEPARATOR.'fake-'.$seed.'.jpg';
                file_put_contents($path, $this->bytes);

                return $path;
            }

            public function isAvailable(): bool
            {
                return true;
            }
        };
    }

    private function planPath(string $storyFile): string
    {
        $slug = pathinfo($storyFile, PATHINFO_FILENAME);

        return $this->storiesDirectory.DIRECTORY_SEPARATOR.$slug.DIRECTORY_SEPARATOR.'shots.json';
    }

    /**
     * @return array<string, mixed>
     */
    private function plan(string $storyFile): array
    {
        $decoded = json_decode(
            (string) file_get_contents($this->planPath($storyFile)),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $this->assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function shots(string $storyFile): array
    {
        $decoded = $this->plan($storyFile);

        $this->assertIsArray($decoded['shots'] ?? null);

        /** @var list<array<string, mixed>> $shots */
        $shots = $decoded['shots'];

        return $shots;
    }

    private function writeStory(int $sentenceCount): string
    {
        $slug = 'image-generation-'.$sentenceCount;
        $sentences = [];
        $cursor = 0.0;

        for ($order = 1; $order <= $sentenceCount; $order++) {
            $start = $cursor;
            $end = $start + 3.6;
            $sentences[] = [
                'order' => $order,
                'sceneOrder' => 1,
                'text' => 'He ran and slammed the door!',
                'start' => $start,
                'end' => $end,
                'pauseAfter' => 0.4,
                'alignment' => 'text',
            ];
            $cursor = $start + 4.0;
        }

        $audioEnd = $cursor;
        $storyDirectory = $this->storiesDirectory.DIRECTORY_SEPARATOR.$slug;
        $this->app->make(Filesystem::class)->ensureDirectoryExists($storyDirectory);

        $timings = [
            'version' => 1,
            'sentences' => $sentences,
            'scenes' => [[
                'order' => 1,
                'start' => 0.0,
                'end' => $audioEnd,
                'duration' => $audioEnd,
                'sentenceCount' => $sentenceCount,
            ]],
        ];

        file_put_contents(
            $storyDirectory.DIRECTORY_SEPARATOR.'timings.json',
            json_encode($timings, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n",
        );
        $this->writeNarrationWav($storyDirectory.DIRECTORY_SEPARATOR.'narration.wav', $audioEnd);

        $payload = [
            'title' => 'Image generation fixture',
            'hook' => 'The door slammed.',
            'description' => 'A fixture used to test image generation.',
            'tags' => ['test'],
            'thumbnailPrompt' => 'An empty hallway',
            'scenes' => [[
                'order' => 1,
                'narration' => implode(' ', array_column($sentences, 'text')),
                'imagePrompt' => 'A dim hallway in fog',
                'visualSummary' => 'A dim hallway vanishing into fog at dusk',
            ]],
            'pronunciations' => [],
            'visualBible' => [
                'setting' => 'Abandoned ranch on open grassland',
                'era' => '1990s rural',
                'timeOfDay' => 'overcast dusk',
                'weather' => 'heavy fog',
                'palette' => ['rust brown', 'bone white', 'charcoal'],
                'characters' => [],
                'recurringObjects' => [],
                'avoid' => ['neon signs'],
            ],
        ];

        $path = $this->storiesDirectory.DIRECTORY_SEPARATOR.$slug.'.json';
        file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");

        return $path;
    }

    /**
     * @return array<string, mixed>
     */
    private function shotDirectorEnvelope(Request $request): array
    {
        $payload = $request->data();
        $text = is_array($payload) ? (string) ($payload['contents'][0]['parts'][0]['text'] ?? '{}') : '{}';
        $user = json_decode($text, true);
        $shots = [];
        $this->directorCalls++;

        foreach (is_array($user['shots'] ?? null) ? $user['shots'] : [] as $shot) {
            if (! is_array($shot) || ! isset($shot['shotIndex'])) {
                continue;
            }

            $shots[] = [
                'shotIndex' => (int) $shot['shotIndex'],
                'description' => 'Directed hallway fog at dusk '.$this->directorCalls,
                'subject' => 'environment',
                'framing' => 'medium shot',
                'threatStage' => '',
                'characterSlugs' => [],
            ];
        }

        return [
            'candidates' => [
                [
                    'finishReason' => 'STOP',
                    'content' => [
                        'parts' => [
                            ['text' => json_encode(['shots' => $shots], JSON_UNESCAPED_UNICODE)],
                        ],
                    ],
                ],
            ],
        ];
    }

    private function writeNarrationWav(string $path, float $duration): void
    {
        $process = new Process([
            'ffmpeg', '-nostdin', '-y', '-hide_banner',
            '-f', 'lavfi',
            '-i', sprintf('anullsrc=r=48000:cl=stereo:d=%.3f', $duration),
            '-t', sprintf('%.3f', $duration),
            '-ac', '2',
            '-ar', '48000',
            '-sample_fmt', 's16',
            $path,
        ]);
        $process->setTimeout(30);
        $process->mustRun();
    }

    private function jpeg(): string
    {
        $image = imagecreatetruecolor(16, 16);
        imagefill($image, 0, 0, imagecolorallocate($image, 30, 30, 30));
        ob_start();
        imagejpeg($image, null, 80);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }
}
