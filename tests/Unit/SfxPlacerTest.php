<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\DataObjects\Story;
use App\Services\Audio\AudioLibrary;
use App\Services\Audio\AudioTrack;
use App\Services\Audio\SfxPlacer;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use Throwable;

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

    public function test_places_a_hit_one_hundred_fifty_ms_before_the_anchor(): void
    {
        $this->indexClip('sfx/door-slam-1.wav', ['door', 'slam'], 0.8);

        $tracks = $this->app->make(SfxPlacer::class)->place(
            $this->story([[
                'query' => 'door slam',
                'tags' => ['door', 'slam'],
                'anchorText' => 'the door slammed',
                'kind' => 'key',
            ]]),
            $this->timings([
                ['order' => 1, 'sceneOrder' => 1, 'text' => 'The door slammed shut.', 'start' => 2.0, 'end' => 3.2],
            ], [
                ['order' => 1, 'start' => 0.0, 'end' => 8.0, 'duration' => 8.0],
            ]),
        );

        $this->assertCount(1, $tracks);
        $this->assertSame(AudioTrack::ROLE_SFX, $tracks[0]->role);
        $this->assertFalse($tracks[0]->duckable);
        $this->assertEqualsWithDelta(1.85, $tracks[0]->startAt, 0.001);
        $this->assertFileExists($tracks[0]->path);
        Http::assertNothingSent();
    }

    public function test_missing_anchor_falls_to_scene_start_and_warns_without_throwing(): void
    {
        $logger = $this->spyLogger();
        $this->indexClip('sfx/door-slam-1.wav', ['door', 'slam'], 0.8);

        try {
            $tracks = $this->app->make(SfxPlacer::class)->place(
                $this->story([[
                    'query' => 'door slam',
                    'tags' => ['door', 'slam'],
                    'anchorText' => 'a phrase that never appears',
                    'kind' => 'key',
                ]]),
                $this->timings([
                    ['order' => 1, 'sceneOrder' => 1, 'text' => 'The door slammed shut.', 'start' => 6.0, 'end' => 7.2],
                ], [
                    ['order' => 1, 'start' => 5.0, 'end' => 12.0, 'duration' => 7.0],
                ]),
            );
        } catch (Throwable $exception) {
            $this->fail('Un ancla ausente no debe lanzar: '.$exception->getMessage());
        }

        $this->assertCount(1, $tracks);
        $this->assertEqualsWithDelta(5.0, $tracks[0]->startAt, 0.001);
        $this->assertFalse($tracks[0]->duckable);
        $this->assertTrue($logger->hasWarning('Ancla de SFX no encontrada'));
    }

    public function test_six_hits_in_five_seconds_are_thinned_and_keys_survive(): void
    {
        $this->indexClip('sfx/door-slam-1.wav', ['door', 'slam'], 0.8);
        $this->indexClip('sfx/floor-creak-1.wav', ['floor', 'creak'], 0.8);
        $this->indexClip('sfx/cloth-rustle-1.wav', ['cloth', 'rustle'], 0.8);
        $this->indexClip('sfx/wood-tap-1.wav', ['wood', 'tap'], 0.8);
        $this->indexClip('sfx/metal-clink-1.wav', ['metal', 'clink'], 0.8);
        $this->indexClip('sfx/glass-crack-1.wav', ['glass', 'crack'], 0.8);

        $tracks = $this->app->make(SfxPlacer::class)->place(
            $this->story([
                [
                    'query' => 'door slam',
                    'tags' => ['door', 'slam'],
                    'anchorText' => 'the door slammed',
                    'kind' => 'key',
                ],
                [
                    'query' => 'floor creak',
                    'tags' => ['floor', 'creak'],
                    'anchorText' => 'the floor creaked',
                    'kind' => 'texture',
                ],
                [
                    'query' => 'cloth rustle',
                    'tags' => ['cloth', 'rustle'],
                    'anchorText' => 'the cloth rustled',
                    'kind' => 'texture',
                ],
                [
                    'query' => 'wood tap',
                    'tags' => ['wood', 'tap'],
                    'anchorText' => 'the wood tapped',
                    'kind' => 'texture',
                ],
                [
                    'query' => 'metal clink',
                    'tags' => ['metal', 'clink'],
                    'anchorText' => 'the metal clinked',
                    'kind' => 'texture',
                ],
                [
                    'query' => 'glass crack',
                    'tags' => ['glass', 'crack'],
                    'anchorText' => 'the glass cracked',
                    'kind' => 'key',
                ],
            ]),
            $this->timings([
                ['order' => 1, 'sceneOrder' => 1, 'text' => 'The door slammed.', 'start' => 0.15, 'end' => 0.8],
                ['order' => 2, 'sceneOrder' => 1, 'text' => 'The floor creaked.', 'start' => 0.7, 'end' => 1.2],
                ['order' => 3, 'sceneOrder' => 1, 'text' => 'The cloth rustled.', 'start' => 1.3, 'end' => 1.8],
                ['order' => 4, 'sceneOrder' => 1, 'text' => 'The wood tapped.', 'start' => 1.9, 'end' => 2.4],
                ['order' => 5, 'sceneOrder' => 1, 'text' => 'The metal clinked.', 'start' => 2.5, 'end' => 3.0],
                ['order' => 6, 'sceneOrder' => 1, 'text' => 'The glass cracked.', 'start' => 4.65, 'end' => 5.0],
            ], [
                ['order' => 1, 'start' => 0.0, 'end' => 5.0, 'duration' => 5.0],
            ]),
        );

        $this->assertCount(2, $tracks);
        $this->assertEqualsWithDelta(0.0, $tracks[0]->startAt, 0.001);
        $this->assertEqualsWithDelta(4.5, $tracks[1]->startAt, 0.001);
        $this->assertStringContainsString('door-slam', $tracks[0]->path);
        $this->assertStringContainsString('glass-crack', $tracks[1]->path);
        $this->assertLessThanOrEqual(5.0, $tracks[1]->startAt);
    }

    public function test_rotates_the_file_when_two_scenes_share_a_query(): void
    {
        $this->indexClip('sfx/door-creak-1.wav', ['door', 'creak'], 0.8);
        $this->indexClip('sfx/door-creak-2.wav', ['door', 'creak'], 0.8);

        $story = Story::fromArray([
            'title' => 'The door',
            'hook' => 'A door.',
            'description' => 'A door.',
            'tags' => ['night'],
            'thumbnailPrompt' => 'door',
            'scenes' => [
                [
                    'order' => 1,
                    'narration' => 'The door creaked open in the dark hallway.',
                    'imagePrompt' => 'door',
                    'soundEffect' => null,
                    'soundEffects' => [[
                        'query' => 'door creak slow',
                        'tags' => ['door', 'creak'],
                        'anchorText' => 'the door creaked',
                        'kind' => 'key',
                    ]],
                ],
                [
                    'order' => 2,
                    'narration' => 'The door creaked again behind my back.',
                    'imagePrompt' => 'door',
                    'soundEffect' => null,
                    'soundEffects' => [[
                        'query' => 'door creak slow',
                        'tags' => ['door', 'creak'],
                        'anchorText' => 'the door creaked again',
                        'kind' => 'key',
                    ]],
                ],
            ],
            'pronunciations' => [],
        ]);

        $tracks = $this->app->make(SfxPlacer::class)->place($story, $this->timings(
            [
                ['order' => 1, 'sceneOrder' => 1, 'text' => 'The door creaked open in the dark hallway.', 'start' => 1.0, 'end' => 3.0],
                ['order' => 2, 'sceneOrder' => 2, 'text' => 'The door creaked again behind my back.', 'start' => 10.0, 'end' => 12.0],
            ],
            [
                ['order' => 1, 'start' => 0.0, 'end' => 8.0, 'duration' => 8.0],
                ['order' => 2, 'start' => 8.0, 'end' => 16.0, 'duration' => 8.0],
            ],
        ));

        $this->assertCount(2, $tracks);
        $this->assertNotSame($tracks[0]->path, $tracks[1]->path);
        $this->assertFalse($tracks[0]->duckable);
        $this->assertFalse($tracks[1]->duckable);
        Http::assertNothingSent();
    }

    /**
     * @param  list<array{query: string, tags: list<string>, anchorText: string, kind: string}>  $effects
     */
    private function story(array $effects): Story
    {
        return Story::fromArray([
            'title' => 'The house',
            'hook' => 'The house waited.',
            'description' => 'House.',
            'tags' => ['night'],
            'thumbnailPrompt' => 'house',
            'scenes' => [[
                'order' => 1,
                'narration' => 'The door slammed. The floor creaked. The glass cracked.',
                'imagePrompt' => 'house',
                'soundEffect' => null,
                'soundEffects' => $effects,
            ]],
            'pronunciations' => [],
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $sentences
     * @param  list<array<string, mixed>>  $scenes
     * @return array{sentences: list<array<string, mixed>>, scenes: list<array<string, mixed>>}
     */
    private function timings(array $sentences, array $scenes): array
    {
        return [
            'sentences' => $sentences,
            'scenes' => $scenes,
        ];
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

    private function spyLogger(): object
    {
        $logger = new class extends AbstractLogger
        {
            /** @var list<array{level: string, message: string}> */
            public array $records = [];

            public function log($level, $message, array $context = []): void
            {
                $this->records[] = [
                    'level' => (string) $level,
                    'message' => (string) $message,
                ];
            }

            public function hasWarning(string $needle): bool
            {
                foreach ($this->records as $record) {
                    if ($record['level'] === 'warning' && str_contains($record['message'], $needle)) {
                        return true;
                    }
                }

                return false;
            }
        };

        $this->app->instance(LoggerInterface::class, $logger);

        return $logger;
    }
}
