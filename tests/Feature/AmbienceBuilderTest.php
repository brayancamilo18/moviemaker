<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\DataObjects\Story;
use App\Services\Audio\AmbienceBuilder;
use App\Services\Audio\AudioLibrary;
use App\Services\Audio\AudioTrack;
use App\Services\Audio\LibraryClipProcessor;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class AmbienceBuilderTest extends TestCase
{
    private string $libraryDir;

    private string $synthDir;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Sleep::fake();

        $this->libraryDir = storage_path('app/testing/ambience-lib-'.bin2hex(random_bytes(4)));
        $this->synthDir = 'testing/audio-synth-'.bin2hex(random_bytes(4));

        $this->app->make('config')->set('stories.audio.library_path', $this->libraryDir);
        $this->app->make('config')->set('stories.audio.resolve.synth_path', $this->synthDir);
        $this->app->make('config')->set('stories.audio.tail_seconds', 0.0);
    }

    protected function tearDown(): void
    {
        $files = new Filesystem;
        $files->deleteDirectory($this->libraryDir);
        $files->deleteDirectory(storage_path('app/'.$this->synthDir));
        $files->deleteDirectory(storage_path('app/tmp/ambience-beds'));

        parent::tearDown();
    }

    public function test_builds_a_single_duckable_bed_with_acrossfade(): void
    {
        $this->indexClip('ambience/wind-night-1.wav', ['wind', 'night'], 3.0, -20.0);

        $story = $this->story([
            [
                'query' => 'wind howling night',
                'tags' => ['wind', 'night'],
                'intensity' => 'subtle',
            ],
            [
                'query' => 'wind howling night',
                'tags' => ['wind', 'night'],
                'intensity' => 'heavy',
            ],
        ]);

        $track = $this->app->make(AmbienceBuilder::class)->build($story, [
            'scenes' => [
                ['order' => 1, 'start' => 0.0, 'end' => 4.0, 'duration' => 4.0],
                ['order' => 2, 'start' => 4.0, 'end' => 8.0, 'duration' => 4.0],
            ],
        ], $this->makeNarrationWav(8.0));

        $this->assertSame(AudioTrack::ROLE_AMBIENCE, $track->role);
        $this->assertTrue($track->duckable);
        $this->assertSame(0.0, $track->startAt);
        $this->assertSame(0.0, $track->gainDb);
        $this->assertFileExists($track->path);
        $this->assertEqualsWithDelta(8.0, $this->app->make(LibraryClipProcessor::class)->duration($track->path), 0.25);
        Http::assertNothingSent();
    }

    public function test_bed_duration_is_last_phrase_plus_tail_not_the_sum_of_segments(): void
    {
        $this->app->make('config')->set('stories.audio.tail_seconds', 10.0);
        $this->app->make('config')->set('stories.audio.ambience.acrossfade_seconds', 2.0);
        $this->app->forgetInstance(AmbienceBuilder::class);
        $this->indexClip('ambience/wind-night-1.wav', ['wind', 'night'], 3.0, -20.0);

        $story = $this->story([
            [
                'query' => 'wind howling night',
                'tags' => ['wind', 'night'],
                'intensity' => 'subtle',
            ],
            [
                'query' => 'wind howling night',
                'tags' => ['wind', 'night'],
                'intensity' => 'moderate',
            ],
            [
                'query' => 'wind howling night',
                'tags' => ['wind', 'night'],
                'intensity' => 'heavy',
            ],
        ]);

        $track = $this->app->make(AmbienceBuilder::class)->build($story, [
            'sentences' => [
                ['order' => 1, 'sceneOrder' => 1, 'text' => 'The wind arrived.', 'start' => 0.0, 'end' => 4.0],
                ['order' => 2, 'sceneOrder' => 2, 'text' => 'The trees leaned in.', 'start' => 4.0, 'end' => 8.0],
                ['order' => 3, 'sceneOrder' => 3, 'text' => 'Then the road went dark.', 'start' => 8.0, 'end' => 12.0],
            ],
            'scenes' => [
                ['order' => 1, 'start' => 0.0, 'end' => 4.0, 'duration' => 4.0],
                ['order' => 2, 'start' => 4.0, 'end' => 8.0, 'duration' => 4.0],
                ['order' => 3, 'start' => 8.0, 'end' => 12.0, 'duration' => 4.0],
            ],
        ], $this->makeNarrationWav(12.0));

        $duration = $this->app->make(LibraryClipProcessor::class)->duration($track->path);

        $this->assertEqualsWithDelta(22.0, $duration, 0.05);
        $this->assertEqualsWithDelta(22.0, (float) $track->endAt - $track->startAt, 0.05);
    }

    public function test_the_outro_scene_reuses_the_last_story_bed_and_does_not_resolve_its_own(): void
    {
        $this->app->make('config')->set('stories.audio.tail_seconds', 6.0);
        $this->app->forgetInstance(AmbienceBuilder::class);
        $this->indexClip('ambience/wind-night-1.wav', ['wind', 'night'], 3.0, -20.0);

        $story = $this->story([
            [
                'query' => 'wind howling night',
                'tags' => ['wind', 'night'],
                'intensity' => 'subtle',
            ],
            [
                'query' => 'wind howling night',
                'tags' => ['wind', 'night'],
                'intensity' => 'heavy',
            ],
        ]);

        $track = $this->app->make(AmbienceBuilder::class)->build($story, [
            'scenes' => [
                ['order' => 1, 'start' => 0.0, 'end' => 4.0, 'duration' => 4.0],
                ['order' => 2, 'start' => 4.0, 'end' => 8.0, 'duration' => 4.0],
                ['order' => 9000, 'start' => 8.0, 'end' => 20.0, 'duration' => 12.0],
            ],
        ], $this->makeNarrationWav(20.0));

        $this->assertCount(2, $track->credits);
        $this->assertSame('ambience.1', $track->credits[0]->cueId);
        $this->assertSame('ambience.2', $track->credits[1]->cueId);
        $this->assertEqualsWithDelta(26.0, $this->app->make(LibraryClipProcessor::class)->duration($track->path), 0.05);
        Http::assertNothingSent();
    }

    public function test_falls_back_to_story_tags_when_scene_has_no_ambience(): void
    {
        $this->indexClip('ambience/wind-night-1.wav', ['wind', 'night', 'fog'], 3.0, -20.0);

        $story = Story::fromArray([
            'title' => 'The fog',
            'hook' => 'The fog closed in.',
            'description' => 'Fog.',
            'tags' => ['wind', 'night'],
            'thumbnailPrompt' => 'fog',
            'scenes' => [[
                'order' => 1,
                'narration' => 'The wind moved the fog along the empty road.',
                'imagePrompt' => 'fog',
                'visualSummary' => 'Fog closing over an empty road at night',
            ]],
            'pronunciations' => [],
        ]);

        $track = $this->app->make(AmbienceBuilder::class)->build($story, [
            'scenes' => [
                ['order' => 1, 'start' => 0.0, 'end' => 4.0, 'duration' => 4.0],
            ],
        ], $this->makeNarrationWav(4.0));

        $this->assertTrue($track->duckable);
        $this->assertEqualsWithDelta(4.0, $this->app->make(LibraryClipProcessor::class)->duration($track->path), 0.2);
    }

    /**
     * @param  list<array{query: string, tags: list<string>, intensity: string}>  $beds
     */
    private function story(array $beds): Story
    {
        $scenes = [];

        foreach ($beds as $index => $bed) {
            $scenes[] = [
                'order' => $index + 1,
                'narration' => 'The wind kept moving through the empty trees.',
                'imagePrompt' => 'trees',
                'visualSummary' => 'Empty trees moving in the night wind',
                'ambience' => $bed,
            ];
        }

        return Story::fromArray([
            'title' => 'The wind',
            'hook' => 'The wind arrived first.',
            'description' => 'Wind.',
            'tags' => ['wind', 'night'],
            'thumbnailPrompt' => 'wind',
            'scenes' => $scenes,
            'pronunciations' => [],
        ]);
    }

    private function makeNarrationWav(float $duration): string
    {
        $path = storage_path('app/tmp/ambience-beds/narration-'.bin2hex(random_bytes(4)).'.wav');
        (new Filesystem)->ensureDirectoryExists(dirname($path));

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

        return $path;
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
            '-i', sprintf('sine=frequency=120:sample_rate=48000:duration=%.3f', $duration),
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
            'type' => 'ambience',
            'tags' => $tags,
            'duration' => $duration,
            'loopable' => true,
            'source_id' => '1',
            'source_url' => 'https://freesound.org/s/1/',
            'author' => 'tester',
            'license' => 'Creative Commons 0',
            'attribution_required' => false,
            'lufs' => $lufs,
            'sha1' => sha1($file),
        ]);
    }
}
