<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\DataObjects\ResolvedSound;
use App\DataObjects\Story;
use App\Services\Audio\AudioLibrary;
use App\Services\Audio\FreesoundClient;
use App\Services\Audio\SoundLibraryImporter;
use App\Services\Audio\SoundResolver;
use App\Services\Audio\StorySoundManifest;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class SoundResolverTest extends TestCase
{
    private string $libraryDir;

    private ?string $storiesDir = null;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Sleep::fake();

        $this->libraryDir = storage_path('app/testing/audio-library-'.bin2hex(random_bytes(4)));
        $this->storiesDir = null;

        $this->app->make('config')->set('stories.audio.library_path', $this->libraryDir);
        $this->app->make('config')->set('stories.audio.cache_match_threshold', 0.6);
        $this->app->make('config')->set('stories.audio.resolve.synth_path', 'testing/audio-synth');
    }

    protected function tearDown(): void
    {
        $files = new Filesystem;
        $files->deleteDirectory($this->libraryDir);
        $files->deleteDirectory(storage_path('app/testing/audio-synth'));

        if ($this->storiesDir !== null) {
            $files->deleteDirectory(storage_path('app/'.$this->storiesDir));
        }

        parent::tearDown();
    }

    public function test_reuses_cache_when_tag_overlap_beats_threshold(): void
    {
        $this->indexClip('sfx/door-creak-1.wav', 'sfx', ['door', 'creak'], 1.0);

        $resolved = $this->resolve('sfx', 'door creak slow', ['door', 'creak']);

        $this->assertSame(ResolvedSound::SOURCE_CACHE, $resolved->source);
        $this->assertStringEndsWith('sfx/door-creak-1.wav', str_replace('\\', '/', $resolved->path));
        $this->assertGreaterThanOrEqual(0.6, $resolved->score);
        $this->assertNull($resolved->ladderLevel);
        Http::assertNothingSent();
    }

    public function test_skips_cache_below_threshold_and_synthesizes_when_freesound_is_empty(): void
    {
        Http::fake([
            'freesound.org/apiv2/search/text*' => Http::response(['results' => []], 200),
        ]);

        $this->indexClip('sfx/wind-only-1.wav', 'sfx', ['wind'], 1.0);

        $resolved = $this->resolve('sfx', 'uniquequeryxyz', ['uniquequeryxyz', 'metal', 'gate', 'distant']);

        $this->assertSame(ResolvedSound::SOURCE_SYNTH, $resolved->source);
        $this->assertSame('', $resolved->path);
        $this->assertNull($resolved->ladderLevel);
        $this->assertEmpty($this->app->make(AudioLibrary::class)->filter('sfx', 'uniquequeryxyz'));
    }

    public function test_scores_candidates_instead_of_api_order(): void
    {
        $preview = storage_path('app/testing/preview-good.wav');
        $this->makeWav($preview, 1.0);
        $bytes = (string) file_get_contents($preview);

        Http::fake([
            'freesound.org/apiv2/search/text*' => Http::response([
                'results' => [
                    $this->apiSound(1, 'Unrelated take', ['ambient'], 5.0, 12, 1.0),
                    $this->apiSound(2, 'Door creak', ['door', 'creak'], 2.0, 9000, 1.0),
                ],
            ], 200),
            'freesound.org/data/previews/1.mp3' => Http::response($bytes, 200),
            'freesound.org/data/previews/2.mp3' => Http::response($bytes, 200),
        ]);

        $resolved = $this->resolve('sfx', 'door creak', ['door', 'creak']);

        $this->assertSame(ResolvedSound::SOURCE_DOWNLOAD, $resolved->source);
        $this->assertStringContainsString('door-creak', strtolower($resolved->path));
        $this->assertSame('tester', $resolved->author);
        $this->assertTrue($resolved->attributionRequired);
        $this->assertSame(1, $resolved->ladderLevel);
    }

    public function test_identical_tags_on_two_resolves_yield_distinct_files(): void
    {
        $this->indexClip('sfx/door-creak-1.wav', 'sfx', ['door', 'creak'], 1.0);
        $this->indexClip('sfx/door-creak-2.wav', 'sfx', ['door', 'creak'], 1.0);

        $first = $this->resolve('sfx', 'door creak', ['door', 'creak']);
        $second = $this->app->make(SoundResolver::class)->resolve(
            ['door', 'creak'],
            'door creak',
            'sfx',
            0.0,
            [$first->path],
        );

        $this->assertSame(ResolvedSound::SOURCE_CACHE, $first->source);
        $this->assertSame(ResolvedSound::SOURCE_CACHE, $second->source);
        $this->assertNotSame($first->path, $second->path);
        $this->assertFileExists($second->path);
        Http::assertNothingSent();
    }

    public function test_freesound_503_falls_back_or_synthesizes_successfully(): void
    {
        Http::fake([
            'freesound.org/apiv2/search/text*' => Http::response(['detail' => 'unavailable'], 503),
        ]);

        $this->indexClip('sfx/wood-crack-single-9.wav', 'sfx', ['wood', 'crack', 'single'], 0.8);

        $resolved = $this->resolve('sfx', 'wood snap', ['wood', 'snap']);

        $this->assertSame(ResolvedSound::SOURCE_FALLBACK, $resolved->source);
        $this->assertFileExists($resolved->path);
        $this->assertNull($resolved->ladderLevel);
    }

    public function test_noncommercial_never_enters_scored_candidates_or_the_manifest(): void
    {
        $preview = storage_path('app/testing/preview-cc0.wav');
        $this->makeWav($preview, 1.0);
        $bytes = (string) file_get_contents($preview);

        Http::fake([
            'freesound.org/apiv2/search/text*' => Http::response([
                'results' => [
                    $this->apiSound(99, 'Illegal slam', ['door', 'creak'], 5.0, 99_000, 1.0, 'Attribution NonCommercial'),
                    $this->apiSound(2, 'Door creak', ['door', 'creak'], 1.0, 2, 1.0, 'Creative Commons 0'),
                ],
            ], 200),
            'freesound.org/data/previews/99.mp3' => Http::response('should-not-download', 200),
            'freesound.org/data/previews/2.mp3' => Http::response($bytes, 200),
        ]);

        $searched = $this->app->make(FreesoundClient::class)->search('door creak', 'sfx', 8);
        $this->assertSame([2], array_column($searched, 'id'));
        $this->assertSame(['Creative Commons 0'], array_column($searched, 'license'));

        $this->indexClip('ambience/wind-1.wav', 'ambience', ['wind', 'night'], 3.0);
        $this->indexClip('music/drone-1.wav', 'music', ['wind', 'night'], 3.0);
        $this->indexClip('music/drone-2.wav', 'music', ['wind', 'night'], 3.0);

        $storiesDir = 'testing/resolver-stories-'.bin2hex(random_bytes(4));
        $this->storiesDir = $storiesDir;
        $this->app->make('config')->set('stories.output_path', $storiesDir);

        $manifest = $this->app->make(StorySoundManifest::class)->sync('the-door', $this->storyWithSfx(), [
            'scenes' => [
                ['order' => 1, 'start' => 0.0, 'end' => 4.0, 'duration' => 4.0],
            ],
        ]);

        foreach ($manifest['cues'] as $cue) {
            $license = mb_strtolower((string) ($cue['license'] ?? ''));
            $this->assertStringNotContainsString('noncommercial', $license);
            $this->assertStringNotContainsString('non-commercial', $license);
        }

        foreach ($this->app->make(AudioLibrary::class)->clips() as $clip) {
            $license = mb_strtolower((string) ($clip['license'] ?? ''));
            $this->assertStringNotContainsString('noncommercial', $license);
            $this->assertStringNotContainsString('non-commercial', $license);
        }

        Http::assertNotSent(static fn ($request): bool => str_contains($request->url(), '/previews/99.mp3'));
    }

    public function test_tries_the_next_candidate_after_verification_fails(): void
    {
        $preview = storage_path('app/testing/preview-good.wav');
        $this->makeWav($preview, 1.0);

        Http::fake([
            'freesound.org/apiv2/search/text*' => Http::response([
                'results' => [
                    $this->apiSound(1, 'Broken take', ['door', 'creak'], 4.0, 100, 1.0),
                    $this->apiSound(2, 'Good take', ['door', 'creak'], 3.5, 80, 1.0),
                ],
            ], 200),
            'freesound.org/data/previews/1.mp3' => Http::response('', 200),
            'freesound.org/data/previews/2.mp3' => Http::response((string) file_get_contents($preview), 200),
        ]);

        $resolved = $this->resolve('sfx', 'door creak', ['door', 'creak']);

        $this->assertSame(ResolvedSound::SOURCE_DOWNLOAD, $resolved->source);
        $this->assertStringContainsString('good-take', strtolower($resolved->path));
        $this->assertSame(1, $resolved->ladderLevel);
    }

    public function test_walks_the_query_ladder_until_a_verified_hit(): void
    {
        $preview = storage_path('app/testing/preview-core.wav');
        $this->makeWav($preview, 1.0);
        $bytes = (string) file_get_contents($preview);

        Http::fake(function ($request) use ($bytes) {
            $url = $request->url();

            if (str_contains($url, '/data/previews/')) {
                return Http::response($bytes, 200);
            }

            $query = (string) $request['query'];

            if ($query === 'door') {
                return Http::response([
                    'results' => [
                        $this->apiSound(7, 'Plain door', ['door'], 4.0, 40, 1.0),
                    ],
                ], 200);
            }

            return Http::response(['results' => []], 200);
        });

        $resolved = $this->resolve('sfx', 'door creak slow', ['door', 'creak']);

        $this->assertSame(ResolvedSound::SOURCE_DOWNLOAD, $resolved->source);
        $this->assertSame(3, $resolved->ladderLevel);
        $this->assertStringContainsString('plain-door', strtolower($resolved->path));
    }

    public function test_skips_the_network_when_the_signal_budget_is_exhausted(): void
    {
        $this->app->make('config')->set('stories.audio.resolve_budget_seconds', 0);
        $this->app->make('config')->set('stories.audio.resolve_total_budget_seconds', 600);

        Http::fake([
            'freesound.org/apiv2/search/text*' => Http::response(['results' => []], 200),
        ]);

        $this->indexClip('sfx/wood-crack-single-9.wav', 'sfx', ['wood', 'crack'], 0.8);

        $resolved = $this->resolve('sfx', 'wood snap', ['wood', 'snap']);

        $this->assertSame(ResolvedSound::SOURCE_FALLBACK, $resolved->source);
        Http::assertNothingSent();
    }

    public function test_skips_the_network_for_remaining_signals_when_the_story_budget_is_exhausted(): void
    {
        $this->app->make('config')->set('stories.audio.resolve_budget_seconds', 20);
        $this->app->make('config')->set('stories.audio.resolve_total_budget_seconds', 0);

        Http::fake([
            'freesound.org/apiv2/search/text*' => Http::response(['results' => []], 200),
        ]);

        $resolver = $this->app->make(SoundResolver::class);
        $first = $resolver->resolve(['alphaone'], 'alphaone cue', 'sfx');
        $second = $resolver->resolve(['betatwo'], 'betatwo cue', 'sfx');

        $this->assertInstanceOf(ResolvedSound::class, $first);
        $this->assertInstanceOf(ResolvedSound::class, $second);
        Http::assertNothingSent();
    }

    public function test_opens_the_circuit_after_three_consecutive_freesound_5xx_errors(): void
    {
        Http::fake([
            'freesound.org/apiv2/search/text*' => Http::response(['detail' => 'unavailable'], 503),
        ]);

        $resolver = $this->app->make(SoundResolver::class);
        $resolver->resolve(['alphaone'], 'alphaone cue', 'sfx');
        $resolver->resolve(['betatwo'], 'betatwo cue', 'sfx');
        $resolver->resolve(['gammatre'], 'gammatre cue', 'sfx');
        $sent = Http::recorded()->count();

        $resolver->resolve(['deltfour'], 'deltfour cue', 'sfx');

        $this->assertSame($sent, Http::recorded()->count());
        $this->assertGreaterThanOrEqual(3, $sent);
    }

    public function test_caps_downloads_at_three_per_level_and_eight_per_signal(): void
    {
        $seq = 0;

        Http::fake(function ($request) use (&$seq) {
            if (str_contains($request->url(), '/data/previews/')) {
                return Http::response('', 200);
            }

            $results = [];

            for ($i = 0; $i < 6; $i++) {
                $seq++;
                $results[] = $this->apiSound($seq, 'Take '.$seq, ['door', 'creak'], 4.0, 10, 1.0);
            }

            return Http::response(['results' => $results], 200);
        });

        $resolved = $this->resolve('sfx', 'door creak slow', ['door', 'creak']);

        $this->assertInstanceOf(ResolvedSound::class, $resolved);

        $previews = Http::recorded(
            static fn ($request): bool => str_contains($request->url(), '/data/previews/'),
        );

        $this->assertLessThanOrEqual(8, $previews->count());
        $this->assertGreaterThan(3, $previews->count());
    }

    public function test_falls_back_to_a_clip_with_a_shared_tag(): void
    {
        Http::fake([
            'freesound.org/apiv2/search/text*' => Http::response(['results' => []], 200),
        ]);

        $this->indexClip('sfx/wood-crack-single-9.wav', 'sfx', ['wood', 'crack', 'single'], 0.8);

        $resolved = $this->resolve('sfx', 'wood snap', ['wood', 'snap']);

        $this->assertSame(ResolvedSound::SOURCE_FALLBACK, $resolved->source);
        $this->assertStringContainsString('wood-crack-single-9.wav', $resolved->path);
        $this->assertLessThan(0.6, $resolved->score);
        $this->assertNull($resolved->ladderLevel);
    }

    public function test_exclude_and_min_duration_skip_cache_hits(): void
    {
        Http::fake([
            'freesound.org/apiv2/search/text*' => Http::response(['results' => []], 200),
        ]);

        $this->indexClip('sfx/door-creak-1.wav', 'sfx', ['door', 'creak'], 1.0);
        $this->indexClip('sfx/door-creak-2.wav', 'sfx', ['door', 'creak'], 1.0);

        $resolved = $this->app->make(SoundResolver::class)->resolve(
            ['door', 'creak'],
            'door creak',
            'sfx',
            5.0,
            ['sfx/door-creak-1.wav'],
        );

        $this->assertSame(ResolvedSound::SOURCE_SYNTH, $resolved->source);
        $this->assertSame('', $resolved->path);
        $this->assertNull($resolved->ladderLevel);
    }

    public function test_signals_for_story_include_ambience_and_scene_effects(): void
    {
        $story = Story::fromArray([
            'title' => 'The door',
            'hook' => 'A door.',
            'description' => 'A door.',
            'tags' => ['night', 'wind'],
            'thumbnailPrompt' => 'door',
            'scenes' => [
                [
                    'order' => 1,
                    'narration' => 'The door closed.',
                    'imagePrompt' => 'door',
                    'soundEffect' => 'door creak slow',
                ],
            ],
        ]);

        $signals = $this->app->make(SoundResolver::class)->signalsFor($story);

        $this->assertSame('ambience', $signals[0]['type']);
        $this->assertContains('night', $signals[0]['tags']);
        $this->assertSame('sfx', $signals[1]['type']);
        $this->assertSame('door creak slow', $signals[1]['query']);
        $this->assertSame(1, $signals[1]['sceneOrder']);
    }

    public function test_resolve_command_never_fails_when_a_clip_is_missing(): void
    {
        Http::fake([
            'freesound.org/apiv2/search/text*' => Http::response(['results' => []], 200),
        ]);

        $this->artisan('audio:resolve', [
            '--type' => 'sfx',
            '--query' => 'totallyunknownfx',
        ])->assertSuccessful()
            ->expectsOutputToContain('sintetizado');
    }

    /**
     * @param  list<string>  $tags
     */
    private function resolve(string $type, string $query, array $tags = []): ResolvedSound
    {
        if ($tags === []) {
            $tags = SoundLibraryImporter::tagsFromQuery($query);
        }

        return $this->app->make(SoundResolver::class)->resolve($tags, $query, $type);
    }

    /**
     * @param  list<string>  $tags
     */
    private function indexClip(string $file, string $type, array $tags, float $duration, bool $makeFile = true): void
    {
        $absolute = $this->libraryDir.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $file);

        if ($makeFile) {
            $this->makeWav($absolute, $duration);
        }

        $this->app->make(AudioLibrary::class)->add($this->clip($file, $type, $tags, $duration));
    }

    /**
     * @param  list<string>  $tags
     * @return array<string, mixed>
     */
    private function clip(string $file, string $type, array $tags, float $duration): array
    {
        return [
            'file' => $file,
            'type' => $type,
            'tags' => $tags,
            'duration' => $duration,
            'loopable' => false,
            'source_id' => (string) crc32($file),
            'source_url' => 'https://freesound.org/s/1/',
            'author' => 'tester',
            'license' => 'Creative Commons 0',
            'attribution_required' => false,
            'lufs' => -20.0,
            'sha1' => sha1($file),
        ];
    }

    /**
     * @param  list<string>  $tags
     * @return array<string, mixed>
     */
    private function apiSound(
        int $id,
        string $name,
        array $tags,
        float $rating,
        int $downloads,
        float $duration,
        string $license = 'Attribution',
    ): array {
        return [
            'id' => $id,
            'name' => $name,
            'username' => 'tester',
            'license' => $license,
            'duration' => $duration,
            'avg_rating' => $rating,
            'num_downloads' => $downloads,
            'tags' => $tags,
            'url' => 'https://freesound.org/people/tester/sounds/'.$id.'/',
            'previews' => [
                'preview-hq-mp3' => 'https://freesound.org/data/previews/'.$id.'.mp3',
            ],
        ];
    }

    private function storyWithSfx(): Story
    {
        return Story::fromArray([
            'title' => 'The door',
            'hook' => 'A door.',
            'description' => 'A door.',
            'tags' => ['wind', 'night'],
            'thumbnailPrompt' => 'door',
            'scenes' => [[
                'order' => 1,
                'narration' => 'The door creaked open in the dark hallway.',
                'imagePrompt' => 'door',
                'soundEffect' => null,
                'ambience' => [
                    'query' => 'wind howling night',
                    'tags' => ['wind', 'night'],
                    'intensity' => 'subtle',
                ],
                'soundEffects' => [[
                    'query' => 'door creak',
                    'tags' => ['door', 'creak'],
                    'anchorText' => 'the door creaked',
                    'kind' => 'key',
                ]],
            ]],
            'pronunciations' => [],
        ]);
    }

    private function makeWav(string $path, float $duration): void
    {
        (new Filesystem)->ensureDirectoryExists(dirname($path));

        $process = new Process([
            'ffmpeg', '-nostdin', '-y', '-hide_banner',
            '-f', 'lavfi',
            '-i', sprintf('sine=frequency=220:sample_rate=48000:duration=%.3f', $duration),
            '-ac', '2',
            '-ar', '48000',
            '-sample_fmt', 's16',
            '-af', 'volume=-12dB',
            $path,
        ]);
        $process->setTimeout(30);
        $process->mustRun();
    }
}
