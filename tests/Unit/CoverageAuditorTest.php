<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\DataObjects\ResolvedSound;
use App\DataObjects\Story;
use App\Services\Audio\AudioLibrary;
use App\Services\Audio\CoverageAuditor;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class CoverageAuditorTest extends TestCase
{
    private string $libraryDir;

    private string $storiesDir;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Http::preventStrayRequests();

        $this->libraryDir = storage_path('app/testing/coverage-unit-'.bin2hex(random_bytes(4)));
        $this->storiesDir = 'testing/coverage-unit-stories-'.bin2hex(random_bytes(4));

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

    public function test_ambience_bed_with_a_300ms_gap_is_blocking(): void
    {
        $ambience = $this->indexClip('ambience/wind-1.wav', 'ambience', ['wind'], 3.0);
        $this->writeNarrationAndTimings(16.0, [
            ['order' => 1, 'start' => 0.0, 'end' => 7.7, 'duration' => 7.7],
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
        $this->assertTrue($this->contains($report->blocking, '0.300'));
    }

    public function test_resolved_file_deleted_before_audit_is_blocking(): void
    {
        $first = $this->indexClip('ambience/wind-1.wav', 'ambience', ['wind'], 3.0);
        $second = $this->indexClip('ambience/wind-2.wav', 'ambience', ['wind'], 3.0);
        $this->writeNarrationAndTimings(16.0, [
            ['order' => 1, 'start' => 0.0, 'end' => 8.0, 'duration' => 8.0],
            ['order' => 2, 'start' => 8.0, 'end' => 16.0, 'duration' => 8.0],
        ]);

        unlink($this->libraryDir.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $second));

        $report = $this->app->make(CoverageAuditor::class)->audit(
            $this->story(),
            [
                $this->cue('ambience.1', 'ambience', $first, 1),
                $this->cue('ambience.2', 'ambience', $second, 2),
            ],
            $this->narrationPath(),
        );

        $this->assertFalse($report->passed);
        $this->assertTrue(
            $this->contains($report->blocking, 'no está en disco')
            || $this->contains($report->blocking, 'no tiene ambiente'),
        );
    }

    public function test_omitted_texture_effect_is_a_warning_not_blocking(): void
    {
        $ambience = $this->indexClip('ambience/wind-1.wav', 'ambience', ['wind'], 3.0);
        $this->writeNarrationAndTimings(16.0, [
            ['order' => 1, 'start' => 0.0, 'end' => 8.0, 'duration' => 8.0],
            ['order' => 2, 'start' => 8.0, 'end' => 16.0, 'duration' => 8.0],
        ]);

        $report = $this->app->make(CoverageAuditor::class)->audit(
            $this->storyWithTexture(),
            [
                $this->cue('ambience.1', 'ambience', $ambience, 1),
                $this->cue('ambience.2', 'ambience', $ambience, 2),
                $this->cue('sfx.1.1', 'sfx', '', 1, ResolvedSound::SOURCE_SYNTH, 'texture', 'categoría cloth_movement sin síntesis creíble'),
            ],
            $this->narrationPath(),
        );

        $this->assertTrue($report->passed);
        $this->assertSame([], $report->blocking);
        $this->assertTrue($this->contains($report->warnings, 'textura'));
        $this->assertTrue($this->contains($report->warnings, 'sfx.1.1'));
    }

    private function story(): Story
    {
        return $this->storyFromEffects([]);
    }

    private function storyWithTexture(): Story
    {
        return $this->storyFromEffects([[
            'query' => 'cloth rustle fabric',
            'tags' => ['cloth', 'fabric'],
            'anchorText' => 'the coat',
            'kind' => 'texture',
        ]]);
    }

    /**
     * @param  list<array<string, mixed>>  $effects
     */
    private function storyFromEffects(array $effects): Story
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
                    'soundEffects' => $effects,
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
            'ladderLevel' => null,
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
