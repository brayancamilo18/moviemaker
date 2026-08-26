<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\DataObjects\Story;
use App\Services\Audio\AmbienceBuilder;
use App\Services\Audio\AudioLibrary;
use App\Services\Audio\AudioTrack;
use App\Services\Audio\LibraryClipProcessor;
use App\Services\Audio\Mixer;
use App\Services\Audio\StoryMixer;
use App\Services\Audio\StorySoundManifest;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class MixTest extends TestCase
{
    private string $libraryDir;

    private string $storiesDir;

    private string $synthDir;

    private string $workDir;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Sleep::fake();

        $this->libraryDir = storage_path('app/testing/mix-lib-'.bin2hex(random_bytes(4)));
        $this->storiesDir = 'testing/mix-stories-'.bin2hex(random_bytes(4));
        $this->synthDir = 'testing/audio-synth-'.bin2hex(random_bytes(4));
        $this->workDir = storage_path('app/testing/mix-work-'.bin2hex(random_bytes(4)));

        $this->app->make('config')->set('stories.audio.library_path', $this->libraryDir);
        $this->app->make('config')->set('stories.audio.resolve.synth_path', $this->synthDir);
        $this->app->make('config')->set('stories.output_path', $this->storiesDir);
        $this->app->make('config')->set('stories.audio.music_enabled', true);

        (new Filesystem)->ensureDirectoryExists($this->workDir);
    }

    protected function tearDown(): void
    {
        $files = new Filesystem;
        $files->deleteDirectory($this->libraryDir);
        $files->deleteDirectory(storage_path('app/'.$this->storiesDir));
        $files->deleteDirectory(storage_path('app/'.$this->synthDir));
        $files->deleteDirectory($this->workDir);
        $files->deleteDirectory(storage_path('app/tmp/ambience-beds'));
        $files->deleteDirectory(storage_path('app/tmp/music-beds'));

        parent::tearDown();
    }

    public function test_ambience_for_a_47_3_second_scene_lasts_exactly_that(): void
    {
        $this->app->make('config')->set('stories.audio.tail_seconds', 0.0);
        $this->indexClip('ambience/wind-night-1.wav', 'ambience', ['wind', 'night'], 3.0);

        $track = $this->app->make(AmbienceBuilder::class)->build($this->story(), [
            'scenes' => [
                ['order' => 1, 'start' => 0.0, 'end' => 47.3, 'duration' => 47.3],
            ],
        ], $this->makeWav($this->workDir.'/narration.wav', 47.3));

        $duration = $this->app->make(LibraryClipProcessor::class)->duration($track->path);

        $this->assertSame(AudioTrack::ROLE_AMBIENCE, $track->role);
        $this->assertEqualsWithDelta(47.3, $duration, 0.01);
        $this->assertEqualsWithDelta(47.3, (float) $track->endAt - $track->startAt, 0.01);
    }

    public function test_no_music_flag_drops_every_music_track(): void
    {
        $this->indexClip('ambience/wind-1.wav', 'ambience', ['wind', 'night'], 3.0);
        $this->indexClip('sfx/door-1.wav', 'sfx', ['door', 'creak'], 0.8);
        $this->indexClip('music/drone-1.wav', 'music', ['wind', 'night', 'dark', 'drone'], 3.0);
        $this->indexClip('music/drone-2.wav', 'music', ['wind', 'night', 'dark', 'drone'], 3.0);
        $this->writeStoryFiles();

        $story = Story::fromArray($this->storyPayload());
        $this->app->make(StorySoundManifest::class)->sync('the-house', $story, $this->timingsPayload());

        $withMusic = $this->app->make(StoryMixer::class)->mix('the-house', $story, [
            'dryRun' => true,
            'noMusic' => false,
        ]);
        $withoutMusic = $this->app->make(StoryMixer::class)->mix('the-house', $story, [
            'dryRun' => true,
            'noMusic' => true,
        ]);

        $this->assertContains(AudioTrack::ROLE_MUSIC, array_column($withMusic['tracks'], 'role'));
        $this->assertNotContains(AudioTrack::ROLE_MUSIC, array_column($withoutMusic['tracks'], 'role'));
    }

    public function test_generated_filter_contains_normalize_zero(): void
    {
        $script = $this->filterScript();

        $this->assertMatchesRegularExpression('/amix=inputs=\d+:normalize=0/', $script);
        $this->assertStringNotContainsString('loudnorm', $script);
    }

    public function test_every_adelay_repeats_the_value_per_channel(): void
    {
        $script = $this->filterScript();

        $this->assertStringContainsString('adelay=1500|1500', $script);

        preg_match_all('/adelay=[^,\[]+/', $script, $matches);

        $this->assertNotEmpty($matches[0]);

        foreach ($matches[0] as $adelay) {
            $this->assertMatchesRegularExpression('/^adelay=\d+\|\d+$/', $adelay);
            $this->assertTrue(preg_match('/^adelay=(\d+)\|(\d+)$/', $adelay, $pair) === 1);
            $this->assertSame($pair[1], $pair[2]);
        }
    }

    public function test_narration_never_enters_the_ducked_bus(): void
    {
        $script = $this->filterScript();

        $this->assertStringContainsString('asplit=2[narr_mix][narr_sc]', $script);
        $this->assertMatchesRegularExpression('/\[[^\]]+\]\[narr_sc\]sidechaincompress=/', $script);
        $this->assertStringContainsString('[ducked][narr_mix]', $script);
        $this->assertDoesNotMatchRegularExpression('/\[narr_mix\].*sidechaincompress/', $script);
        $this->assertDoesNotMatchRegularExpression('/sidechaincompress[^\n]*\[narr_mix\]/', $script);
    }

    private function filterScript(): string
    {
        $narration = $this->makeWav($this->workDir.'/narration.wav', 1.0);
        $ambience = $this->makeWav($this->workDir.'/ambience.wav', 2.0);
        $sfx = $this->makeWav($this->workDir.'/sfx.wav', 0.4);

        return $this->app->make(Mixer::class)->filterScript([
            new AudioTrack($narration, AudioTrack::ROLE_NARRATION, 0.0, null, 0.0, false, 0.0, 0.0),
            new AudioTrack($ambience, AudioTrack::ROLE_AMBIENCE, 0.0, null, -18.0, true, 0.2, 0.2),
            new AudioTrack($sfx, AudioTrack::ROLE_SFX, 1.5, null, 0.0, false, 0.0, 0.0),
        ]);
    }

    private function story(): Story
    {
        return Story::fromArray($this->storyPayload());
    }

    /**
     * @return array<string, mixed>
     */
    private function storyPayload(): array
    {
        return [
            'title' => 'The house',
            'hook' => 'The house waited.',
            'description' => 'House.',
            'tags' => ['wind', 'night', 'dark', 'drone'],
            'thumbnailPrompt' => 'house',
            'scenes' => [[
                'order' => 1,
                'narration' => 'The door creaked open in the dark hallway.',
                'imagePrompt' => 'hall',
                'visualSummary' => 'A dim hallway vanishing into fog at dusk',
                'ambience' => [
                    'query' => 'wind howling night',
                    'tags' => ['wind', 'night'],
                    'intensity' => 'subtle',
                ],
            ]],
            'pronunciations' => [],
        ];
    }

    /**
     * @return array{sentences: list<array<string, mixed>>, scenes: list<array<string, mixed>>}
     */
    private function timingsPayload(): array
    {
        return [
            'sentences' => [
                ['order' => 1, 'sceneOrder' => 1, 'text' => 'The door creaked open in the dark hallway.', 'start' => 1.0, 'end' => 3.0, 'pauseAfter' => 0.4],
            ],
            'scenes' => [
                ['order' => 1, 'start' => 0.0, 'end' => 16.0, 'duration' => 16.0, 'sentenceCount' => 1],
            ],
        ];
    }

    private function writeStoryFiles(): void
    {
        $directory = storage_path('app/'.$this->storiesDir);
        $slugDir = $directory.'/the-house';
        (new Filesystem)->ensureDirectoryExists($slugDir);

        file_put_contents(
            $directory.'/the-house.json',
            json_encode($this->storyPayload(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n",
        );
        file_put_contents(
            $slugDir.'/timings.json',
            json_encode($this->timingsPayload(), JSON_PRETTY_PRINT)."\n",
        );
        $this->makeWav($slugDir.'/narration.wav', 16.0);
    }

    /**
     * @param  list<string>  $tags
     */
    private function indexClip(string $file, string $type, array $tags, float $duration): void
    {
        $absolute = $this->libraryDir.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $file);
        $this->makeWav($absolute, $duration);

        $this->app->make(AudioLibrary::class)->add([
            'file' => $file,
            'type' => $type,
            'tags' => $tags,
            'duration' => $duration,
            'loopable' => $type !== 'sfx',
            'source_id' => (string) crc32($file),
            'source_url' => 'https://freesound.org/s/1/',
            'author' => 'tester',
            'license' => 'Creative Commons 0',
            'attribution_required' => false,
            'lufs' => -20.0,
            'sha1' => sha1($file),
        ]);
    }

    private function makeWav(string $path, float $duration): string
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
        $process->setTimeout(60);
        $process->mustRun();

        return $path;
    }
}
