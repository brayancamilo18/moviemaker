<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\DataObjects\DirectedSfx;
use App\DataObjects\NarrationWord;
use App\DataObjects\Shot;
use App\Services\Audio\AudioLibrary;
use App\Services\Audio\AudioTrack;
use App\Services\Audio\LibraryClipProcessor;
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

    public function test_places_the_hit_lead_seconds_before_the_word_that_names_it(): void
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

    /**
     * La palabra manda sobre la estimación: el offsetRatio dice 0.0 y la palabra está al final del
     * plano, así que el golpe suena al final.
     */
    public function test_the_word_decides_the_instant_and_not_the_offset_ratio(): void
    {
        $this->indexClip('sfx/door-slam-1.wav', ['door', 'slam'], 0.8);

        $placed = $this->placeEffects(
            [$this->shot(1, 1, 2.0, 6.0)],
            [$this->effect(1, 0.0, 'door slam', ['door', 'slam'], DirectedSfx::IMPORTANCE_KEY)],
            [new NarrationWord('anchor1', 5.4, 5.9)],
        );

        $this->assertCount(1, $placed['tracks']);
        $this->assertEqualsWithDelta(5.25, $placed['tracks'][0]->startAt, 0.001);
    }

    public function test_an_effect_without_anchor_word_is_not_placed(): void
    {
        $this->indexClip('sfx/door-slam-1.wav', ['door', 'slam'], 0.8);

        $placed = $this->placeEffects(
            [$this->shot(1, 1, 2.0, 3.2)],
            [$this->effect(1, 0.0, 'door slam', ['door', 'slam'], DirectedSfx::IMPORTANCE_KEY, anchorWord: '')],
        );

        $this->assertSame([], $placed['tracks']);
        $this->assertSame('anchor_missing', $placed['skipped'][0]['reason']);
    }

    public function test_an_effect_whose_word_is_not_aligned_in_the_shot_is_not_placed(): void
    {
        $this->indexClip('sfx/door-slam-1.wav', ['door', 'slam'], 0.8);

        $placed = $this->placeEffects(
            [$this->shot(1, 1, 2.0, 3.2)],
            [$this->effect(1, 0.0, 'door slam', ['door', 'slam'], DirectedSfx::IMPORTANCE_KEY, anchorWord: 'slammed')],
            [new NarrationWord('whistled', 2.4, 2.9)],
        );

        $this->assertSame([], $placed['tracks']);
        $this->assertSame('anchor_not_found', $placed['skipped'][0]['reason']);
        $this->assertSame('slammed', $placed['skipped'][0]['anchorWord']);
    }

    /**
     * «The door slammed and slammed again»: hay dos anclas válidas y el offsetRatio elige. Es lo
     * único que decide todavía.
     */
    public function test_the_repeated_word_closest_to_the_offset_ratio_wins(): void
    {
        $this->indexClip('sfx/door-slam-1.wav', ['door', 'slam'], 0.8);

        $placed = $this->placeEffects(
            [$this->shot(1, 1, 2.0, 6.0)],
            [$this->effect(1, 0.9, 'door slam', ['door', 'slam'], DirectedSfx::IMPORTANCE_KEY, anchorWord: 'slammed')],
            [
                new NarrationWord('slammed', 2.2, 2.6),
                new NarrationWord('slammed', 5.4, 5.8),
            ],
        );

        $this->assertCount(1, $placed['tracks']);
        $this->assertEqualsWithDelta(5.25, $placed['tracks'][0]->startAt, 0.001);
    }

    /**
     * whisper escribe lo que oye, no lo que dice el guion. Un ancla que solo difiere en la
     * terminación tiene que seguir anclando.
     */
    public function test_a_word_transcribed_with_another_ending_still_anchors(): void
    {
        $this->indexClip('sfx/door-creak-1.wav', ['door', 'creak'], 0.8);

        $placed = $this->placeEffects(
            [$this->shot(1, 1, 2.0, 4.0)],
            [$this->effect(1, 0.0, 'door creak', ['door', 'creak'], DirectedSfx::IMPORTANCE_KEY, anchorWord: 'creaked')],
            [new NarrationWord('creaking', 2.5, 3.0)],
        );

        $this->assertCount(1, $placed['tracks']);
        $this->assertEqualsWithDelta(2.35, $placed['tracks'][0]->startAt, 0.001);
    }

    public function test_measures_the_dead_head_of_a_clip_and_ignores_one_that_starts_sounding(): void
    {
        $withHead = $this->libraryDir.DIRECTORY_SEPARATOR.'head.wav';
        $withoutHead = $this->libraryDir.DIRECTORY_SEPARATOR.'nohead.wav';
        (new Filesystem)->ensureDirectoryExists($this->libraryDir);
        $this->sine($withHead, 0.5, -12.0, 0.3);
        $this->sine($withoutHead, 0.5, -12.0);

        $processor = $this->app->make(LibraryClipProcessor::class);

        $this->assertEqualsWithDelta(0.3, $processor->onsetSeconds($withHead), 0.02);
        $this->assertSame(0.0, $processor->onsetSeconds($withoutHead));
    }

    /**
     * El golpe se quiere oír a 1.85 s (inicio del plano menos el lead). Si el clip trae 0.3 s de
     * silencio delante y entra en 1.85, el golpe suena a 2.15: tarde. Tiene que entrar en 1.55.
     */
    public function test_a_clip_with_a_dead_head_enters_early_so_the_hit_lands_on_time(): void
    {
        $this->indexClip('sfx/door-slam-1.wav', ['door', 'slam'], 0.8, silentHead: 0.3);

        $placed = $this->placeEffects(
            [$this->shot(1, 1, 2.0, 3.2)],
            [$this->effect(1, 0.0, 'door slam', ['door', 'slam'], DirectedSfx::IMPORTANCE_KEY)],
        );

        $this->assertCount(1, $placed['tracks']);
        $this->assertEqualsWithDelta(1.55, $placed['tracks'][0]->startAt, 0.02);
    }

    public function test_the_dead_head_never_moves_a_clip_more_than_the_configured_ceiling(): void
    {
        $this->app->make('config')->set('stories.audio.sfx.onset_max_seconds', 0.1);
        $this->indexClip('sfx/door-slam-1.wav', ['door', 'slam'], 0.8, silentHead: 0.3);

        $placed = $this->placeEffects(
            [$this->shot(1, 1, 2.0, 3.2)],
            [$this->effect(1, 0.0, 'door slam', ['door', 'slam'], DirectedSfx::IMPORTANCE_KEY)],
        );

        $this->assertCount(1, $placed['tracks']);
        $this->assertEqualsWithDelta(1.75, $placed['tracks'][0]->startAt, 0.001);
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

    /**
     * SoundVerifier acepta cualquier pico entre -35 y 0 dBFS, así que dos golpes «válidos» pueden
     * llevarse 28 dB. Tras normalizar tienen que sonar al mismo nivel.
     */
    public function test_two_hits_with_opposite_peaks_end_up_at_the_same_level(): void
    {
        $this->indexClip('sfx/door-slam-1.wav', ['door', 'slam'], 2.0, -2.0);
        $this->indexClip('sfx/glass-crack-1.wav', ['glass', 'crack'], 2.0, -30.0);

        $tracks = $this->placeEffects(
            [$this->shot(1, 1, 1.0, 2.0), $this->shot(2, 1, 20.0, 21.0)],
            [
                $this->effect(1, 0.0, 'door slam', ['door', 'slam'], DirectedSfx::IMPORTANCE_KEY),
                $this->effect(2, 0.0, 'glass crack', ['glass', 'crack'], DirectedSfx::IMPORTANCE_KEY),
            ],
        )['tracks'];

        $this->assertCount(2, $tracks);
        $this->assertStringContainsString('door-slam', $tracks[0]->path);
        $this->assertStringContainsString('glass-crack', $tracks[1]->path);

        $processor = $this->app->make(LibraryClipProcessor::class);
        $levels = [];

        foreach ($tracks as $track) {
            $truePeak = $processor->truePeakDbtp($track->path);

            $this->assertNotNull($truePeak);

            $levels[] = $truePeak + $track->gainDb;
        }

        $this->assertLessThan(-10.0, $tracks[0]->gainDb);
        $this->assertGreaterThan(0.0, $tracks[1]->gainDb);
        $this->assertEqualsWithDelta($levels[0], $levels[1], 1.0);
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
     * Sin palabras no hay ancla y no se coloca nada, así que por defecto se sintetiza una por plano
     * en su inicio: es lo que hace que las cuentas de estos tests sean «inicio del plano menos el
     * lead». Los casos que miden otra cosa pasan las suyas.
     *
     * @param  list<Shot>  $shots
     * @param  list<DirectedSfx>  $effects
     * @param  list<NarrationWord>  $words
     * @return array{tracks: list<AudioTrack>, skipped: list<array<string, mixed>>}
     */
    private function placeEffects(array $shots, array $effects, array $words = []): array
    {
        return $this->app->make(SfxPlacer::class)->place(
            $shots,
            $effects,
            [],
            $words === [] ? $this->wordsFor($shots) : $words,
        );
    }

    /**
     * @param  list<Shot>  $shots
     * @return list<NarrationWord>
     */
    private function wordsFor(array $shots): array
    {
        $words = [];

        foreach ($shots as $shot) {
            $words[] = new NarrationWord('anchor'.$shot->order, $shot->start, $shot->start + 0.2);
        }

        return $words;
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
    private function effect(
        int $shotIndex,
        float $offsetRatio,
        string $query,
        array $tags,
        string $importance,
        ?string $anchorWord = null,
    ): DirectedSfx {
        return new DirectedSfx(
            shotIndex: $shotIndex,
            offsetRatio: $offsetRatio,
            query: $query,
            tags: $tags,
            importance: $importance,
            anchorWord: $anchorWord ?? 'anchor'.$shotIndex,
        );
    }

    /**
     * @param  list<string>  $tags
     */
    private function indexClip(string $file, array $tags, float $duration, ?float $peakDbfs = null, float $silentHead = 0.0): void
    {
        $absolute = $this->libraryDir.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $file);
        (new Filesystem)->ensureDirectoryExists(dirname($absolute));
        $tone = max(0.0, $duration - $silentHead);
        $this->sine($absolute, $tone, -12.0, $silentHead);

        if ($peakDbfs !== null) {
            // La amplitud de la fuente sine de ffmpeg cambia entre versiones, así que un volume
            // fijo no fija el pico: hay que medir lo que ha salido y corregir.
            $sourcePeak = $this->samplePeakDbfs($absolute) + 12.0;
            $this->sine($absolute, $tone, $peakDbfs - $sourcePeak, $silentHead);
        }

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

    private function sine(string $path, float $duration, float $gainDb, float $silentHead = 0.0): void
    {
        $filters = [sprintf('volume=%.3fdB', $gainDb)];

        if ($silentHead > 0.0) {
            $filters[] = sprintf('adelay=delays=%d:all=1', (int) round($silentHead * 1000));
        }

        $process = new Process([
            'ffmpeg', '-nostdin', '-y', '-hide_banner',
            '-f', 'lavfi',
            '-i', sprintf('sine=frequency=440:sample_rate=48000:duration=%.3f', $duration),
            '-ac', '2',
            '-ar', '48000',
            '-sample_fmt', 's16',
            '-af', implode(',', $filters),
            $path,
        ]);
        $process->setTimeout(30);
        $process->mustRun();
    }

    private function samplePeakDbfs(string $path): float
    {
        $process = new Process([
            'ffmpeg', '-nostdin', '-hide_banner',
            '-i', $path,
            '-af', 'volumedetect',
            '-f', 'null',
            '-',
        ]);
        $process->setTimeout(30);
        $process->mustRun();

        $this->assertSame(
            1,
            preg_match('/max_volume:\s*(-?\d+(?:\.\d+)?)\s*dB/', $process->getErrorOutput(), $matches),
        );

        return (float) $matches[1];
    }
}
