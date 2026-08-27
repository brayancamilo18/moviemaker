<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\ImageGenerator;
use App\Services\Image\ShotPlanner;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use RuntimeException;
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
        $this->assertSame(3, $decoded['plannerVersion']);
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
