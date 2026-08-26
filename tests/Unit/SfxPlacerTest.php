<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\DataObjects\DirectedSfx;
use App\DataObjects\Shot;
use App\Services\Audio\AudioLibrary;
use App\Services\Audio\AudioTrack;
use App\Services\Audio\SfxPlacer;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class SfxPlacerTest extends TestCase
{
    private string $libraryDir;

    private string $synthDir;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Sleep::fake();

        $this->libraryDir = storage_path('app/testing/sfx-lib-'.bin2hex(random_bytes(4)));
        $this->synthDir = 'testing/audio-synth-'.bin2hex(random_bytes(4));

        $this->app->make('config')->set('stories.audio.library_path', $this->libraryDir);
        $this->app->make('config')->set('stories.audio.resolve.synth_path', $this->synthDir);
    }

    protected function tearDown(): void
    {
        $files = new Filesystem;
        $files->deleteDirectory($this->libraryDir);
        $files->deleteDirectory(storage_path('app/'.$this->synthDir));

        parent::tearDown();
    }

    public function test_places_a_hit_lead_seconds_before_offset_ratio_zero(): void
    {
        $this->indexClip('sfx/door-slam-1.wav', ['door', 'slam'], 0.8);

        $placed = $this->placeEffects(
            [$this->shot(1, 1, 2.0, 3.2)],
            [$this->effect(1, 0.0, 'door slam', ['door', 'slam'], DirectedSfx::IMPORTANCE_KEY)],
        );
        $tracks = $placed['tracks'];

        $this->assertCount(1, $tracks);
        $this->assertSame(AudioTrack::ROLE_SFX, $tracks[0]->role);
        $this->assertFalse($tracks[0]->duckable);
        $this->assertEqualsWithDelta(1.85, $tracks[0]->startAt, 0.001);
        $this->assertFileExists($tracks[0]->path);
        Http::assertNothingSent();
    }

    public function test_offset_ratio_one_lands_at_the_end_of_the_shot_minus_lead(): void
    {
        $this->indexClip('sfx/door-slam-1.wav', ['door', 'slam'], 0.8);

        $placed = $this->placeEffects(
            [$this->shot(1, 1, 2.0, 6.0)],
            [$this->effect(1, 1.0, 'door slam', ['door', 'slam'], DirectedSfx::IMPORTANCE_KEY)],
        );

        $this->assertCount(1, $placed['tracks']);
        $this->assertEqualsWithDelta(5.85, $placed['tracks'][0]->startAt, 0.001);
    }

    public function test_unknown_shot_is_skipped_without_throwing(): void
    {
        $this->indexClip('sfx/door-slam-1.wav', ['door', 'slam'], 0.8);

        $placed = $this->placeEffects(
            [$this->shot(1, 1, 2.0, 4.0)],
            [$this->effect(99, 0.0, 'door slam', ['door', 'slam'], DirectedSfx::IMPORTANCE_KEY)],
        );

        $this->assertSame([], $placed['tracks']);
        $this->assertSame([
            [
                'shot' => 99,
                'query' => 'door slam',
                'reason' => 'shot_not_found',
            ],
        ], $placed['skipped']);
    }

    public function test_six_hits_in_five_seconds_are_thinned_and_keys_survive(): void
    {
        $this->indexClip('sfx/door-slam-1.wav', ['door', 'slam'], 0.8);
        $this->indexClip('sfx/floor-creak-1.wav', ['floor', 'creak'], 0.8);
        $this->indexClip('sfx/cloth-rustle-1.wav', ['cloth', 'rustle'], 0.8);
        $this->indexClip('sfx/wood-tap-1.wav', ['wood', 'tap'], 0.8);
        $this->indexClip('sfx/metal-clink-1.wav', ['metal', 'clink'], 0.8);
        $this->indexClip('sfx/glass-crack-1.wav', ['glass', 'crack'], 0.8);

        $tracks = $this->placeEffects(
            [
                $this->shot(1, 1, 0.15, 0.8),
                $this->shot(2, 1, 0.7, 1.2),
                $this->shot(3, 1, 1.3, 1.8),
                $this->shot(4, 1, 1.9, 2.4),
                $this->shot(5, 1, 2.5, 3.0),
                $this->shot(6, 1, 4.65, 5.0),
            ],
            [
                $this->effect(1, 0.0, 'door slam', ['door', 'slam'], DirectedSfx::IMPORTANCE_KEY),
                $this->effect(2, 0.0, 'floor creak', ['floor', 'creak'], DirectedSfx::IMPORTANCE_TEXTURE),
                $this->effect(3, 0.0, 'cloth rustle', ['cloth', 'rustle'], DirectedSfx::IMPORTANCE_TEXTURE),
                $this->effect(4, 0.0, 'wood tap', ['wood', 'tap'], DirectedSfx::IMPORTANCE_TEXTURE),
                $this->effect(5, 0.0, 'metal clink', ['metal', 'clink'], DirectedSfx::IMPORTANCE_TEXTURE),
                $this->effect(6, 0.0, 'glass crack', ['glass', 'crack'], DirectedSfx::IMPORTANCE_KEY),
            ],
        )['tracks'];

        $this->assertCount(2, $tracks);
        $this->assertEqualsWithDelta(0.0, $tracks[0]->startAt, 0.001);
        $this->assertEqualsWithDelta(4.5, $tracks[1]->startAt, 0.001);
        $this->assertStringContainsString('door-slam', $tracks[0]->path);
        $this->assertStringContainsString('glass-crack', $tracks[1]->path);
        $this->assertLessThanOrEqual(5.0, $tracks[1]->startAt);
    }

    public function test_rotates_the_file_when_two_shots_share_a_query(): void
    {
        $this->indexClip('sfx/door-creak-1.wav', ['door', 'creak'], 0.8);
        $this->indexClip('sfx/door-creak-2.wav', ['door', 'creak'], 0.8);

        $tracks = $this->placeEffects(
            [
                $this->shot(1, 1, 1.0, 3.0, 'The door creaked open in the dark hallway.'),
                $this->shot(2, 2, 10.0, 12.0, 'The door creaked again behind my back.'),
            ],
            [
                $this->effect(1, 0.0, 'door creak slow', ['door', 'creak'], DirectedSfx::IMPORTANCE_KEY),
                $this->effect(2, 0.0, 'door creak slow', ['door', 'creak'], DirectedSfx::IMPORTANCE_KEY),
            ],
        )['tracks'];

        $this->assertCount(2, $tracks);
        $this->assertNotSame($tracks[0]->path, $tracks[1]->path);
        $this->assertFalse($tracks[0]->duckable);
        $this->assertFalse($tracks[1]->duckable);
        Http::assertNothingSent();
    }

    /**
     * @param  list<Shot>  $shots
     * @param  list<DirectedSfx>  $effects
     * @return array{tracks: list<AudioTrack>, skipped: list<array<string, mixed>>}
     */
    private function placeEffects(array $shots, array $effects): array
    {
        return $this->app->make(SfxPlacer::class)->place($shots, $effects);
    }

    private function shot(int $order, int $sceneOrder, float $start, float $end, string $sourceText = ''): Shot
    {
        return new Shot(
            order: $order,
            sceneOrder: $sceneOrder,
            start: $start,
            end: $end,
            sourceText: $sourceText,
            framing: 'medium shot',
            motion: 'static',
            subject: 'environment',
            threatStage: null,
        );
    }

    /**
     * @param  list<string>  $tags
     */
    private function effect(int $shotIndex, float $offsetRatio, string $query, array $tags, string $importance): DirectedSfx
    {
        return new DirectedSfx(
            shotIndex: $shotIndex,
            offsetRatio: $offsetRatio,
            query: $query,
            tags: $tags,
            importance: $importance,
        );
    }

    /**
     * @param  list<string>  $tags
     */
    private function indexClip(string $file, array $tags, float $duration): void
    {
        $absolute = $this->libraryDir.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $file);
        (new Filesystem)->ensureDirectoryExists(dirname($absolute));

        $process = new Process([
            'ffmpeg', '-nostdin', '-y', '-hide_banner',
            '-f', 'lavfi',
            '-i', sprintf('sine=frequency=440:sample_rate=48000:duration=%.3f', $duration),
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
            'type' => 'sfx',
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
        ]);
    }
}
