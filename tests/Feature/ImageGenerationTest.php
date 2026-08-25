<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\ImageGenerator;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

final class ImageGenerationTest extends TestCase
{
    private string $storiesDirectory;

    private string $cacheDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
        Http::preventStrayRequests();

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

    public function test_second_run_makes_zero_image_requests_because_everything_is_cached(): void
    {
        $this->fakePromptResponses($this->jpeg());
        $file = $this->writeStory(8);

        $this->artisan('story:images', ['file' => $file])->assertSuccessful();
        $this->assertGreaterThan(0, $this->promptRequestCount());

        $this->fakePromptResponses($this->jpeg());
        $this->artisan('story:images', ['file' => $file])->assertSuccessful();

        $this->assertSame(0, $this->promptRequestCount());
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

    /**
     * @return list<array<string, mixed>>
     */
    private function shots(string $storyFile): array
    {
        $slug = pathinfo($storyFile, PATHINFO_FILENAME);
        $path = $this->storiesDirectory.DIRECTORY_SEPARATOR.$slug.DIRECTORY_SEPARATOR.'shots.json';
        $decoded = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

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
                'soundEffect' => null,
                'visualBeats' => ['A dim hallway vanishing into fog'],
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
