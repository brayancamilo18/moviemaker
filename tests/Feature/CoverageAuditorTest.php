<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\DataObjects\ResolvedSound;
use App\DataObjects\Story;
use App\Services\Audio\AudioLibrary;
use App\Services\Audio\CoverageAuditor;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class CoverageAuditorTest extends TestCase
{
    private string $libraryDir;

    private string $storiesDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->libraryDir = storage_path('app/testing/coverage-lib-'.bin2hex(random_bytes(4)));
        $this->storiesDir = 'testing/coverage-stories-'.bin2hex(random_bytes(4));

        $this->app->make('config')->set('stories.audio.library_path', $this->libraryDir);
        $this->app->make('config')->set('stories.output_path', $this->storiesDir);
    }

    protected function tearDown(): void
    {
        $files = new Filesystem;
        $files->deleteDirectory($this->libraryDir);
        $files->deleteDirectory(storage_path('app/'.$this->storiesDir));

        parent::tearDown();
    }

    public function test_complete_coverage_passes_with_key_effect_omit_as_warning(): void
    {
        $ambience = $this->indexClip('ambience/wind-1.wav', 'ambience', ['wind'], 3.0);
        $this->writeNarrationAndTimings(16.0, [
            ['order' => 1, 'start' => 0.0, 'end' => 8.0, 'duration' => 8.0],
            ['order' => 2, 'start' => 8.0, 'end' => 16.0, 'duration' => 8.0],
        ]);

        $resolved = [
            $this->cue('ambience.1', 'ambience', $ambience, 1),
            $this->cue('ambience.2', 'ambience', $ambience, 2),
            $this->cue('sfx.1.1', 'sfx', '', 1, ResolvedSound::SOURCE_SYNTH, 'key', 'categoría animal_distant sin síntesis creíble'),
        ];

        $report = $this->app->make(CoverageAuditor::class)->audit(
            $this->story(),
            $resolved,
            $this->narrationPath(),
        );

        $this->assertTrue($report->passed);
        $this->assertSame([], $report->blocking);
        $this->assertNotEmpty($report->warnings);
        $this->assertSame(2, $report->sourceBreakdown[ResolvedSound::SOURCE_CACHE]);
        $this->assertSame(1, $report->sourceBreakdown[ResolvedSound::SOURCE_SYNTH]);
    }

    public function test_missing_scene_ambience_is_blocking(): void
    {
        $ambience = $this->indexClip('ambience/wind-1.wav', 'ambience', ['wind'], 3.0);
        $this->writeNarrationAndTimings(16.0, [
            ['order' => 1, 'start' => 0.0, 'end' => 8.0, 'duration' => 8.0],
            ['order' => 2, 'start' => 8.0, 'end' => 16.0, 'duration' => 8.0],
        ]);

        $report = $this->app->make(CoverageAuditor::class)->audit(
            $this->story(),
            [$this->cue('ambience.1', 'ambience', $ambience, 1)],
            $this->narrationPath(),
        );

        $this->assertFalse($report->passed);
        $this->assertTrue($this->contains($report->blocking, 'escena 2'));
    }

    public function test_gap_and_overlap_in_the_bed_are_blocking(): void
    {
        $ambience = $this->indexClip('ambience/wind-1.wav', 'ambience', ['wind'], 3.0);
        $this->writeNarrationAndTimings(16.0, [
            ['order' => 1, 'start' => 0.0, 'end' => 7.0, 'duration' => 7.0],
            ['order' => 2, 'start' => 8.0, 'end' => 16.0, 'duration' => 8.0],
        ]);

        $report = $this->app->make(CoverageAuditor::class)->audit(
            $this->story(),
            [
                $this->cue('ambience.1', 'ambience', $ambience, 1),
                $this->cue('ambience.2', 'ambience', $ambience, 2),
            ],
            $this->narrationPath(),
        );

        $this->assertFalse($report->passed);
        $this->assertTrue($this->contains($report->blocking, 'Hueco'));

        $this->writeNarrationAndTimings(16.0, [
            ['order' => 1, 'start' => 0.0, 'end' => 8.2, 'duration' => 8.2],
            ['order' => 2, 'start' => 8.0, 'end' => 16.0, 'duration' => 8.0],
        ]);

        $overlapped = $this->app->make(CoverageAuditor::class)->audit(
            $this->story(),
            [
                $this->cue('ambience.1', 'ambience', $ambience, 1),
                $this->cue('ambience.2', 'ambience', $ambience, 2),
            ],
            $this->narrationPath(),
        );

        $this->assertFalse($overlapped->passed);
        $this->assertTrue($this->contains($overlapped->blocking, 'Solape'));
    }

    public function test_missing_file_on_disk_is_blocking(): void
    {
        $ambience = $this->indexClip('ambience/wind-1.wav', 'ambience', ['wind'], 3.0);
        $this->writeNarrationAndTimings(16.0, [
            ['order' => 1, 'start' => 0.0, 'end' => 8.0, 'duration' => 8.0],
            ['order' => 2, 'start' => 8.0, 'end' => 16.0, 'duration' => 8.0],
        ]);

        $report = $this->app->make(CoverageAuditor::class)->audit(
            $this->story(),
            [
                $this->cue('ambience.1', 'ambience', $ambience, 1),
                $this->cue('ambience.2', 'ambience', 'ambience/deleted.wav', 2),
            ],
            $this->narrationPath(),
        );

        $this->assertFalse($report->passed);
        $this->assertTrue($this->contains($report->blocking, 'no está en disco')
            || $this->contains($report->blocking, 'no tiene ambiente'));
    }

    public function test_unjustified_key_effect_omit_is_blocking(): void
    {
        $ambience = $this->indexClip('ambience/wind-1.wav', 'ambience', ['wind'], 3.0);
        $this->writeNarrationAndTimings(16.0, [
            ['order' => 1, 'start' => 0.0, 'end' => 8.0, 'duration' => 8.0],
            ['order' => 2, 'start' => 8.0, 'end' => 16.0, 'duration' => 8.0],
        ]);

        $report = $this->app->make(CoverageAuditor::class)->audit(
            $this->story(),
            [
                $this->cue('ambience.1', 'ambience', $ambience, 1),
                $this->cue('ambience.2', 'ambience', $ambience, 2),
                $this->cue('sfx.1.1', 'sfx', '', 1, 'cache', 'key', null),
            ],
            $this->narrationPath(),
        );

        $this->assertFalse($report->passed);
        $this->assertTrue($this->contains($report->blocking, 'sfx.1.1'));
    }

    private function story(): Story
    {
        return Story::fromArray([
            'title' => 'The house',
            'hook' => 'The house waited.',
            'description' => 'House.',
            'tags' => ['wind', 'night'],
            'thumbnailPrompt' => 'house',
            'scenes' => [
                [
                    'order' => 1,
                    'narration' => 'The door creaked.',
                    'imagePrompt' => 'hall',
                    'soundEffect' => null,
                    'ambience' => [
                        'query' => 'wind howling night',
                        'tags' => ['wind', 'night'],
                        'intensity' => 'subtle',
                    ],
                    'soundEffects' => [[
                        'query' => 'dog barking distant',
                        'tags' => ['dog', 'bark', 'animal'],
                        'anchorText' => 'a dog',
                        'kind' => 'key',
                    ]],
                ],
                [
                    'order' => 2,
                    'narration' => 'The road waited.',
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
        ]);
    }

    /**
     * @param  list<array{order: int, start: float, end: float, duration: float}>  $scenes
     */
    private function writeNarrationAndTimings(float $narrationDuration, array $scenes): void
    {
        $directory = dirname($this->narrationPath());
        (new Filesystem)->ensureDirectoryExists($directory);
        $this->makeWav($this->narrationPath(), $narrationDuration);
        file_put_contents($directory.'/timings.json', json_encode([
            'version' => 1,
            'sentences' => [],
            'scenes' => $scenes,
        ], JSON_PRETTY_PRINT)."\n");
    }

    private function narrationPath(): string
    {
        return storage_path('app/'.$this->storiesDir.'/the-house/narration.wav');
    }

    /**
     * @param  list<string>  $haystack
     */
    private function contains(array $haystack, string $needle): bool
    {
        foreach ($haystack as $item) {
            if (str_contains($item, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function cue(
        string $id,
        string $type,
        string $file,
        ?int $sceneOrder,
        string $source = ResolvedSound::SOURCE_CACHE,
        ?string $kind = null,
        ?string $omitReason = null,
    ): array {
        return [
            'id' => $id,
            'type' => $type,
            'role' => $type === 'ambience' ? 'bed' : 'scene',
            'sceneOrder' => $sceneOrder,
            'query' => 'wind',
            'tags' => ['wind'],
            'kind' => $kind,
            'file' => $file === '' ? '' : str_replace($this->libraryDir.DIRECTORY_SEPARATOR, '', $file),
            'source' => $source,
            'score' => 1.0,
            'gainDb' => 0.0,
            'ladderLevel' => $source === ResolvedSound::SOURCE_DOWNLOAD ? 1 : null,
            'omitReason' => $omitReason,
        ];
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

        return $file;
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
