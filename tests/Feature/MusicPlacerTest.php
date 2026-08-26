<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\DataObjects\Story;
use App\Services\Audio\AudioLibrary;
use App\Services\Audio\AudioTrack;
use App\Services\Audio\LibraryClipProcessor;
use App\Services\Audio\MusicPlacer;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class MusicPlacerTest extends TestCase
{
    private string $libraryDir;

    private string $synthDir;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Sleep::fake();

        $this->libraryDir = storage_path('app/testing/music-lib-'.bin2hex(random_bytes(4)));
        $this->synthDir = 'testing/audio-synth-'.bin2hex(random_bytes(4));

        $this->app->make('config')->set('stories.audio.library_path', $this->libraryDir);
        $this->app->make('config')->set('stories.audio.resolve.synth_path', $this->synthDir);
        $this->app->make('config')->set('stories.audio.music_enabled', true);
    }

    protected function tearDown(): void
    {
        $files = new Filesystem;
        $files->deleteDirectory($this->libraryDir);
        $files->deleteDirectory(storage_path('app/'.$this->synthDir));
        $files->deleteDirectory(storage_path('app/tmp/music-beds'));

        parent::tearDown();
    }

    public function test_disabled_music_returns_no_tracks(): void
    {
        $this->app->make('config')->set('stories.audio.music_enabled', false);
        $this->indexClip('music/drone-1.wav', ['dark', 'drone'], 3.0, -20.0);

        $tracks = $this->placer()->place($this->story(), $this->timings(8.0, 40.0));

        $this->assertSame([], $tracks);
        Http::assertNothingSent();
    }

    public function test_places_hook_and_climax_as_duckable_music(): void
    {
        $this->indexClip('music/drone-1.wav', ['dark', 'drone'], 3.0, -20.0);
        $this->indexClip('music/drone-2.wav', ['dark', 'drone'], 3.0, -18.0);

        $tracks = $this->placer()->place($this->story(), $this->timings(8.0, 40.0));

        $this->assertCount(2, $tracks);

        $hook = $tracks[0];
        $this->assertSame(AudioTrack::ROLE_MUSIC, $hook->role);
        $this->assertTrue($hook->duckable);
        $this->assertSame(0.0, $hook->startAt);
        $this->assertEqualsWithDelta(8.0, $hook->endAt, 0.001);
        $this->assertSame(0.0, $hook->fadeIn);
        $this->assertEqualsWithDelta(4.0, $hook->fadeOut, 0.001);
        $this->assertEqualsWithDelta(-10.0, $hook->gainDb, 0.001);
        $this->assertEqualsWithDelta(8.0, $this->app->make(LibraryClipProcessor::class)->duration($hook->path), 0.2);

        $climax = $tracks[1];
        $this->assertSame(AudioTrack::ROLE_MUSIC, $climax->role);
        $this->assertTrue($climax->duckable);
        $this->assertEqualsWithDelta(30.0, $climax->startAt, 0.001);
        $this->assertEqualsWithDelta(32.0, $climax->endAt, 0.001);
        $this->assertEqualsWithDelta(6.0, $climax->fadeIn, 0.001);
        $this->assertEqualsWithDelta(5.0, $climax->fadeOut, 0.001);
        $this->assertEqualsWithDelta(-12.0, $climax->gainDb, 0.001);
        $this->assertNotSame($hook->path, $climax->path);
        Http::assertNothingSent();
    }

    public function test_skips_climax_when_the_video_is_too_short(): void
    {
        $this->indexClip('music/drone-1.wav', ['dark', 'drone'], 3.0, -20.0);

        $tracks = $this->placer()->place($this->story(), $this->timings(8.0, 20.0));

        $this->assertCount(1, $tracks);
        $this->assertSame(0.0, $tracks[0]->startAt);
        $this->assertEqualsWithDelta(8.0, $tracks[0]->endAt, 0.001);
    }

    private function placer(): MusicPlacer
    {
        return $this->app->make(MusicPlacer::class);
    }

    private function story(): Story
    {
        return Story::fromArray([
            'title' => 'The dark',
            'hook' => 'The dark arrived first.',
            'description' => 'Dark.',
            'tags' => ['dark', 'drone'],
            'thumbnailPrompt' => 'dark',
            'scenes' => [
                [
                    'order' => 1,
                    'narration' => 'The dark held the empty road.',
                    'imagePrompt' => 'road',
                    'soundEffect' => null,
                ],
                [
                    'order' => 2,
                    'narration' => 'The dark did not let go.',
                    'imagePrompt' => 'road',
                    'soundEffect' => null,
                ],
            ],
            'pronunciations' => [],
        ]);
    }

    /**
     * @return array{scenes: list<array{order: int, start: float, end: float, duration: float}>}
     */
    private function timings(float $firstEnd, float $total): array
    {
        return [
            'scenes' => [
                ['order' => 1, 'start' => 0.0, 'end' => $firstEnd, 'duration' => $firstEnd],
                ['order' => 2, 'start' => $firstEnd, 'end' => $total, 'duration' => $total - $firstEnd],
            ],
        ];
    }

    /**
     * @param  list<string>  $tags
     */
    private function indexClip(string $file, array $tags, float $duration, float $lufs): void
    {
        $absolute = $this->libraryDir.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $file);
        (new Filesystem)->ensureDirectoryExists(dirname($absolute));

        $process = new Process([
            'ffmpeg', '-nostdin', '-y', '-hide_banner',
            '-f', 'lavfi',
            '-i', sprintf('sine=frequency=110:sample_rate=48000:duration=%.3f', $duration),
            '-ac', '2',
            '-ar', '48000',
            '-sample_fmt', 's16',
            '-af', 'volume=-12dB',
            $absolute,
        ]);
        $process->setTimeout(30);
        $process->mustRun();

        $this->app->make(AudioLibrary::class)->add([
            'file' => $file,
            'type' => 'music',
            'tags' => $tags,
            'duration' => $duration,
            'loopable' => true,
            'source_id' => (string) crc32($file),
            'source_url' => 'https://freesound.org/s/1/',
            'author' => 'tester',
            'license' => 'Creative Commons 0',
            'attribution_required' => false,
            'lufs' => $lufs,
            'sha1' => sha1($file),
        ]);
    }
}
