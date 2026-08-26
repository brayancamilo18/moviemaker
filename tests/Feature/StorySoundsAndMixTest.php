<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Audio\AudioLibrary;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class StorySoundsAndMixTest extends TestCase
{
    private string $libraryDir;

    private string $storiesDir;

    private string $synthDir;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Sleep::fake();

        $this->libraryDir = storage_path('app/testing/mix-lib-'.bin2hex(random_bytes(4)));
        $this->storiesDir = 'testing/mix-stories-'.bin2hex(random_bytes(4));
        $this->synthDir = 'testing/audio-synth-'.bin2hex(random_bytes(4));

        $this->app->make('config')->set('stories.audio.library_path', $this->libraryDir);
        $this->app->make('config')->set('stories.audio.resolve.synth_path', $this->synthDir);
        $this->app->make('config')->set('stories.output_path', $this->storiesDir);
        $this->app->make('config')->set('stories.audio.music_enabled', false);
    }

    protected function tearDown(): void
    {
        $files = new Filesystem;
        $files->deleteDirectory($this->libraryDir);
        $files->deleteDirectory(storage_path('app/'.$this->storiesDir));
        $files->deleteDirectory(storage_path('app/'.$this->synthDir));
        $files->deleteDirectory(storage_path('app/tmp/ambience-beds'));
        $files->deleteDirectory(storage_path('app/tmp/music-beds'));

        parent::tearDown();
    }

    public function test_story_sounds_writes_manifest_and_keeps_manual_edits(): void
    {
        $this->indexClip('ambience/wind-1.wav', 'ambience', ['wind', 'night'], 3.0);
        $this->indexClip('sfx/door-1.wav', 'sfx', ['door', 'creak'], 0.8);
        $this->indexClip('music/drone-1.wav', 'music', ['dark', 'drone'], 3.0);
        $storyFile = $this->writeStory();
        $this->writeTimings();
        $this->writeNarration();

        $this->artisan('story:sounds', ['file' => $storyFile])
            ->assertSuccessful()
            ->expectsOutputToContain('ambience.1')
            ->expectsOutputToContain('sfx.1.1')
            ->expectsOutputToContain('music.hook')
            ->expectsOutputToContain('Escalera')
            ->expectsOutputToContain('Reparto por origen');

        $path = storage_path('app/'.$this->storiesDir.'/the-house/sounds.json');
        $this->assertFileExists($path);

        $manifest = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($manifest);
        $this->assertSame('ambience.1', $manifest['cues'][0]['id']);
        $original = $manifest['cues'][0]['file'];
        $this->assertNotSame('', $original);

        $manifest['cues'][0]['file'] = 'ambience/manual-edit.wav';
        file_put_contents($path, json_encode($manifest, JSON_PRETTY_PRINT)."\n");

        $this->artisan('story:sounds', ['file' => $storyFile])->assertSuccessful();

        $kept = json_decode((string) file_get_contents($path), true);
        $this->assertSame('ambience/manual-edit.wav', $kept['cues'][0]['file']);

        $this->artisan('story:sounds', [
            'file' => $storyFile,
            '--refresh-cue' => 'ambience.1',
        ])->assertSuccessful();

        $refreshed = json_decode((string) file_get_contents($path), true);
        $this->assertSame($original, $refreshed['cues'][0]['file']);
        Http::assertNothingSent();
    }

    public function test_story_mix_dry_run_prints_tracks_without_writing_mix(): void
    {
        $this->indexClip('ambience/wind-1.wav', 'ambience', ['wind', 'night'], 3.0);
        $this->indexClip('sfx/door-1.wav', 'sfx', ['door', 'creak'], 0.8);
        $this->indexClip('music/drone-1.wav', 'music', ['dark', 'drone'], 3.0);
        $storyFile = $this->writeStory();
        $this->writeTimings();
        $this->writeNarration();

        $this->artisan('story:sounds', ['file' => $storyFile])->assertSuccessful();

        $this->artisan('story:mix', [
            'file' => $storyFile,
            '--dry-run' => true,
            '--no-music' => true,
        ])
            ->assertSuccessful()
            ->expectsOutputToContain('narration')
            ->expectsOutputToContain('ambience')
            ->expectsOutputToContain('sfx')
            ->expectsOutputToContain('Simulación')
            ->expectsOutputToContain('Duración del máster');

        $this->assertFileDoesNotExist(storage_path('app/'.$this->storiesDir.'/the-house/narration_mix.wav'));
    }

    public function test_story_mix_respects_a_hand_edited_path_and_can_mix_narration_only(): void
    {
        $this->indexClip('ambience/wind-1.wav', 'ambience', ['wind', 'night'], 3.0);
        $this->indexClip('sfx/door-1.wav', 'sfx', ['door', 'creak'], 0.8);
        $this->indexClip('music/drone-1.wav', 'music', ['dark', 'drone'], 3.0);
        $alt = $this->indexClip('sfx/door-alt.wav', 'sfx', ['door', 'creak'], 0.8);
        $storyFile = $this->writeStory();
        $this->writeTimings();
        $this->writeNarration();

        $this->artisan('story:sounds', ['file' => $storyFile])->assertSuccessful();

        $path = storage_path('app/'.$this->storiesDir.'/the-house/sounds.json');
        $manifest = json_decode((string) file_get_contents($path), true);

        foreach ($manifest['cues'] as &$cue) {
            if (($cue['id'] ?? '') === 'sfx.1.1') {
                $cue['file'] = 'sfx/door-alt.wav';
            }
        }
        unset($cue);
        file_put_contents($path, json_encode($manifest, JSON_PRETTY_PRINT)."\n");

        $this->artisan('story:mix', [
            'file' => $storyFile,
            '--dry-run' => true,
            '--no-music' => true,
            '--no-ambience' => true,
        ])
            ->assertSuccessful()
            ->expectsOutputToContain('door-alt.wav');

        $this->artisan('story:mix', [
            'file' => $storyFile,
            '--no-music' => true,
            '--no-sfx' => true,
            '--no-ambience' => true,
        ])->assertSuccessful();

        $this->assertFileExists(storage_path('app/'.$this->storiesDir.'/the-house/narration_mix.wav'));
        $this->assertFileExists(storage_path('app/'.$this->storiesDir.'/the-house/narration_mix.mp3'));
        $this->assertFileExists($alt);
    }

    public function test_story_sounds_audit_does_not_resolve_and_mix_stops_on_blocking(): void
    {
        $this->indexClip('ambience/wind-1.wav', 'ambience', ['wind', 'night'], 3.0);
        $this->indexClip('sfx/door-1.wav', 'sfx', ['door', 'creak'], 0.8);
        $this->indexClip('music/drone-1.wav', 'music', ['dark', 'drone'], 3.0);
        $storyFile = $this->writeStory();
        $this->writeTimings();
        $this->writeNarration();

        $this->artisan('story:sounds', ['file' => $storyFile])->assertSuccessful();

        $path = storage_path('app/'.$this->storiesDir.'/the-house/sounds.json');
        $before = (string) file_get_contents($path);

        $this->artisan('story:sounds', [
            'file' => $storyFile,
            '--audit' => true,
        ])
            ->assertSuccessful()
            ->expectsOutputToContain('sin bloqueantes');

        $this->assertSame($before, (string) file_get_contents($path));
        Http::assertNothingSent();

        $manifest = json_decode($before, true);
        $deleted = $this->libraryDir.DIRECTORY_SEPARATOR.str_replace(
            '/',
            DIRECTORY_SEPARATOR,
            (string) $manifest['cues'][0]['file'],
        );
        unlink($deleted);

        $this->artisan('story:sounds', [
            'file' => $storyFile,
            '--audit' => true,
        ])
            ->assertFailed()
            ->expectsOutputToContain('bloqueantes');

        $this->artisan('story:mix', [
            'file' => $storyFile,
            '--dry-run' => true,
            '--no-music' => true,
        ])
            ->assertFailed()
            ->expectsOutputToContain('No se mezcla');
    }

    private function writeStory(): string
    {
        $directory = storage_path('app/'.$this->storiesDir);
        (new Filesystem)->ensureDirectoryExists($directory);
        $path = $directory.DIRECTORY_SEPARATOR.'the-house.json';

        $payload = [
            'title' => 'The house',
            'hook' => 'The house waited.',
            'description' => 'House.',
            'tags' => ['wind', 'night', 'dark', 'drone'],
            'thumbnailPrompt' => 'house',
            'scenes' => [
                [
                    'order' => 1,
                    'narration' => 'The door creaked open in the dark hallway.',
                    'imagePrompt' => 'hall',
                    'soundEffect' => null,
                    'ambience' => [
                        'query' => 'wind howling night',
                        'tags' => ['wind', 'night'],
                        'intensity' => 'subtle',
                    ],
                    'soundEffects' => [[
                        'query' => 'door creak slow',
                        'tags' => ['door', 'creak'],
                        'anchorText' => 'the door creaked',
                        'kind' => 'key',
                    ]],
                ],
                [
                    'order' => 2,
                    'narration' => 'The dark did not let go of the empty road.',
                    'imagePrompt' => 'road',
                    'soundEffect' => null,
                    'ambience' => [
                        'query' => 'wind howling night',
                        'tags' => ['wind', 'night'],
                        'intensity' => 'moderate',
                    ],
                ],
            ],
            'pronunciations' => [],
        ];

        file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n");

        return $path;
    }

    private function writeTimings(): void
    {
        $directory = storage_path('app/'.$this->storiesDir.'/the-house');
        (new Filesystem)->ensureDirectoryExists($directory);

        file_put_contents($directory.'/timings.json', json_encode([
            'version' => 1,
            'sentences' => [
                ['order' => 1, 'sceneOrder' => 1, 'text' => 'The door creaked open in the dark hallway.', 'start' => 1.0, 'end' => 3.0, 'pauseAfter' => 0.4],
                ['order' => 2, 'sceneOrder' => 2, 'text' => 'The dark did not let go of the empty road.', 'start' => 8.0, 'end' => 10.0, 'pauseAfter' => 0.4],
            ],
            'scenes' => [
                ['order' => 1, 'start' => 0.0, 'end' => 8.0, 'duration' => 8.0, 'sentenceCount' => 1],
                ['order' => 2, 'start' => 8.0, 'end' => 16.0, 'duration' => 8.0, 'sentenceCount' => 1],
            ],
        ], JSON_PRETTY_PRINT)."\n");
    }

    private function writeNarration(): void
    {
        $this->makeWav(storage_path('app/'.$this->storiesDir.'/the-house/narration.wav'), 16.0);
    }

    /**
     * @param  list<string>  $tags
     */
    private function indexClip(string $file, string $type, array $tags, float $duration): string
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

        return $absolute;
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
