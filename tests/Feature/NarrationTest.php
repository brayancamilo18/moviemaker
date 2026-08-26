<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\TextToSpeech;
use App\Exceptions\TtsUnavailableException;
use App\Services\Tts\KokoroTts;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

final class NarrationTest extends TestCase
{
    private string $storiesDirectory;

    private string $cacheDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
        Http::preventStrayRequests();

        $this->storiesDirectory = storage_path('app/testing/stories');
        $this->cacheDirectory = storage_path('app/testing/tts-cache');

        $files = $this->app->make(Filesystem::class);
        $files->deleteDirectory(storage_path('app/testing'));
        $files->ensureDirectoryExists($this->storiesDirectory);
        $files->ensureDirectoryExists($this->cacheDirectory);

        config([
            'stories.output_path' => 'testing/stories',
            'stories.tts.cache_path' => 'testing/tts-cache',
            'stories.tts.base_url' => 'http://127.0.0.1:8020',
            'stories.tts.voice' => 'af_heart',
            'stories.tts.speed' => 1.0,
            'stories.whisper.model' => '',
        ]);

        $this->app->forgetInstance(TextToSpeech::class);
        $this->app->singleton(TextToSpeech::class, function (): TextToSpeech {
            return new KokoroTts(
                http: $this->app->make(Factory::class),
                files: $this->app->make(Filesystem::class),
                baseUrl: 'http://127.0.0.1:8020',
                voice: 'af_heart',
                speed: 1.0,
                timeout: 5,
                cacheDirectory: $this->cacheDirectory,
            );
        });
    }

    protected function tearDown(): void
    {
        $this->app->make(Filesystem::class)->deleteDirectory(storage_path('app/testing'));

        parent::tearDown();
    }

    public function test_three_sentence_story_calls_tts_three_times_and_writes_the_master(): void
    {
        $this->fakeSidecar();
        $storyFile = $this->writeStory();

        $this->artisan('story:narrate', [
            'file' => $storyFile,
            '--skip-timings' => true,
        ])->assertSuccessful();

        $this->assertSame(3, $this->synthesizeCount());
        $this->assertMasterExists('three-sentences');
        $this->assertSame(3, $this->cachedWavCount());

        $payload = $this->readJson($storyFile);
        $this->assertSame(3, $payload['audio']['sentenceCount']);
        $this->assertIsFloat($payload['audio']['durationSeconds']);
        $this->assertGreaterThan(0, $payload['audio']['durationSeconds']);
        $this->assertSame('af_heart', $payload['audio']['voice']);
        $this->assertFileExists($payload['audio']['wav']);
        $this->assertFileExists($payload['audio']['mp3']);
        $this->assertNull($payload['audio']['timings']);
    }

    public function test_second_identical_run_uses_only_the_cache(): void
    {
        $this->fakeSidecar();
        $storyFile = $this->writeStory();

        $this->artisan('story:narrate', [
            'file' => $storyFile,
            '--skip-timings' => true,
        ])->assertSuccessful();

        $this->assertSame(3, $this->synthesizeCount());

        $this->artisan('story:narrate', [
            'file' => $storyFile,
            '--skip-timings' => true,
        ])
            ->expectsOutputToContain('Caché: 3/3')
            ->assertSuccessful();

        $this->assertSame(3, $this->synthesizeCount());
        $this->assertSame(3, $this->cachedWavCount());
    }

    public function test_no_cache_calls_tts_again(): void
    {
        $this->fakeSidecar();
        $storyFile = $this->writeStory();

        $this->artisan('story:narrate', [
            'file' => $storyFile,
            '--skip-timings' => true,
        ])->assertSuccessful();

        $this->artisan('story:narrate', [
            'file' => $storyFile,
            '--skip-timings' => true,
            '--no-cache' => true,
        ])
            ->expectsOutputToContain('Caché: 0/3')
            ->assertSuccessful();

        $this->assertSame(6, $this->synthesizeCount());
        $this->assertSame(3, $this->cachedWavCount());
    }

    public function test_down_sidecar_throws_and_leaves_no_partial_files(): void
    {
        $this->fakeSidecar(available: false);
        $storyFile = $this->writeStory();

        $this->artisan('story:narrate', [
            'file' => $storyFile,
            '--skip-timings' => true,
        ])
            ->expectsOutputToContain('El sidecar de Kokoro no está levantado.')
            ->expectsOutputToContain(KokoroTts::START_COMMAND)
            ->assertFailed();

        $this->assertMasterMissing('three-sentences');
        $this->assertSame(0, $this->cachedWavCount());
        $this->assertArrayNotHasKey('audio', $this->readJson($storyFile));

        try {
            $this->app->make(TextToSpeech::class)->synthesize('The door closed.');
            $this->fail('Se esperaba TtsUnavailableException.');
        } catch (TtsUnavailableException $exception) {
            $this->assertStringContainsString(KokoroTts::START_COMMAND, $exception->getMessage());
        }

        $this->assertMasterMissing('three-sentences');
        $this->assertSame(0, $this->cachedWavCount());
    }

    public function test_changing_voice_uses_a_new_cache_hash_and_resynthesizes(): void
    {
        $this->fakeSidecar();
        $storyFile = $this->writeStory();

        $this->artisan('story:narrate', [
            'file' => $storyFile,
            '--skip-timings' => true,
        ])->assertSuccessful();

        $defaultHashes = $this->cachedHashes();
        $this->assertCount(3, $defaultHashes);

        $this->artisan('story:narrate', [
            'file' => $storyFile,
            '--skip-timings' => true,
            '--voice' => 'am_adam',
        ])->assertSuccessful();

        $this->assertSame(6, $this->synthesizeCount());

        $allHashes = $this->cachedHashes();
        $this->assertCount(6, $allHashes);
        $this->assertCount(3, array_diff($allHashes, $defaultHashes));

        $payload = $this->readJson($storyFile);
        $this->assertSame('am_adam', $payload['audio']['voice']);
    }

    private function fakeSidecar(bool $available = true): void
    {
        $wav = $this->silenceWav();

        Http::fake(function (Request $request) use ($available, $wav) {
            if (str_contains($request->url(), '/health')) {
                if (! $available) {
                    return Http::response(['status' => 'down'], 503);
                }

                return Http::response(['status' => 'ok', 'model_loaded' => true]);
            }

            if (str_contains($request->url(), '/synthesize')) {
                if (! $available) {
                    return Http::response('', 503);
                }

                return Http::response($wav, 200, ['Content-Type' => 'audio/wav']);
            }

            return Http::response('unexpected', 404);
        });
    }

    private function writeStory(): string
    {
        $path = $this->storiesDirectory.DIRECTORY_SEPARATOR.'three-sentences.json';
        $json = json_encode($this->storyPayload(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $this->assertNotFalse($json);
        $this->app->make(Filesystem::class)->put($path, $json."\n");

        return $path;
    }

    /**
     * @return array<string, mixed>
     */
    private function storyPayload(): array
    {
        return [
            'title' => 'Three Sentences',
            'hook' => 'The door closed.',
            'description' => 'A short script used to test narration.',
            'tags' => ['test'],
            'thumbnailPrompt' => 'An empty hallway',
            'scenes' => [
                [
                    'order' => 1,
                    'narration' => 'The door closed. Then the whistle came. Who was there?',
                    'imagePrompt' => 'A closed wooden door',
                    'visualSummary' => 'A closed wooden door in a dim hallway',
                ],
            ],
            'pronunciations' => [],
        ];
    }

    private function silenceWav(): string
    {
        $path = base_path('tests/Fixtures/silence.wav');
        $wav = file_get_contents($path);
        $this->assertNotFalse($wav);

        return $wav;
    }

    private function synthesizeCount(): int
    {
        return Http::recorded(
            static fn (Request $request): bool => $request->method() === 'POST'
                && str_contains($request->url(), '/synthesize'),
        )->count();
    }

    private function cachedWavCount(): int
    {
        return count($this->cachedHashes());
    }

    /**
     * @return list<string>
     */
    private function cachedHashes(): array
    {
        $files = glob($this->cacheDirectory.DIRECTORY_SEPARATOR.'*.wav');

        if ($files === false) {
            return [];
        }

        $hashes = array_map(static fn (string $path): string => basename($path, '.wav'), $files);
        sort($hashes);

        return array_values($hashes);
    }

    private function assertMasterExists(string $slug): void
    {
        $directory = $this->storiesDirectory.DIRECTORY_SEPARATOR.$slug;
        $this->assertFileExists($directory.DIRECTORY_SEPARATOR.'narration.wav');
        $this->assertFileExists($directory.DIRECTORY_SEPARATOR.'narration.mp3');
        $this->assertFileDoesNotExist($directory.DIRECTORY_SEPARATOR.'timings.json');
    }

    private function assertMasterMissing(string $slug): void
    {
        $directory = $this->storiesDirectory.DIRECTORY_SEPARATOR.$slug;
        $this->assertFileDoesNotExist($directory.DIRECTORY_SEPARATOR.'narration.wav');
        $this->assertFileDoesNotExist($directory.DIRECTORY_SEPARATOR.'narration.mp3');
        $this->assertFileDoesNotExist($directory.DIRECTORY_SEPARATOR.'timings.json');
    }

    /**
     * @return array<string, mixed>
     */
    private function readJson(string $path): array
    {
        $json = file_get_contents($path);
        $this->assertNotFalse($json);

        /** @var array<string, mixed> $payload */
        $payload = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        return $payload;
    }
}
