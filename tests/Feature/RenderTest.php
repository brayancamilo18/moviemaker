<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Image\ShotPlanner;
use App\Services\Story\StoryValidator;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class RenderTest extends TestCase
{
    private string $storiesDir;

    private string $workDir;

    private string $slug = 'render-fixture';

    protected function setUp(): void
    {
        parent::setUp();

        $this->storiesDir = 'testing/render-stories-'.bin2hex(random_bytes(4));
        $this->workDir = 'testing/render-work-'.bin2hex(random_bytes(4));

        config([
            'stories.output_path' => $this->storiesDir,
            'stories.video.work_path' => $this->workDir,
            'stories.video.preset' => 'ultrafast',
            'stories.video.outro_seconds' => 0.4,
            'stories.video.scene_fade_duration' => 0.1,
            'stories.audio.tail_seconds' => 0.0,
        ]);
        $this->app->forgetInstance(StoryValidator::class);

        $files = $this->app->make(Filesystem::class);
        $files->ensureDirectoryExists($this->storyDirectory());
        $this->writeFixture();
    }

    protected function tearDown(): void
    {
        $files = $this->app->make(Filesystem::class);
        $files->deleteDirectory(storage_path('app/'.$this->storiesDir));
        $files->deleteDirectory(storage_path('app/'.$this->workDir));

        parent::tearDown();
    }

    public function test_render_writes_mp4_with_video_audio_yuv420p_matching_mix(): void
    {
        $this->artisan('story:render', [
            'file' => $this->storyFile(),
            '--keep-intermediates' => true,
            '--no-grade' => true,
        ])->assertSuccessful();

        $video = $this->storyDirectory().DIRECTORY_SEPARATOR.'video-nograde.mp4';
        $this->assertFileExists($video);

        $probe = $this->probe($video);
        $this->assertContains('video', $probe['types']);
        $this->assertContains('audio', $probe['types']);
        $this->assertSame('yuv420p', $probe['pix_fmt']);
        $this->assertEqualsWithDelta(8.4, $probe['duration'], 0.1);
    }

    public function test_rerun_only_regenerates_the_missing_scene_clip(): void
    {
        $this->artisan('story:render', [
            'file' => $this->storyFile(),
            '--keep-intermediates' => true,
            '--no-grade' => true,
        ])->assertSuccessful();

        $clips = $this->workDirectory().DIRECTORY_SEPARATOR.'clips';
        $scenes = $this->workDirectory().DIRECTORY_SEPARATOR.'scenes';
        $scene1 = $scenes.DIRECTORY_SEPARATOR.'scene-01.mp4';
        $scene2 = $scenes.DIRECTORY_SEPARATOR.'scene-02.mp4';
        $clipFiles = glob($clips.DIRECTORY_SEPARATOR.'shot-*.mp4') ?: [];

        $this->assertFileExists($scene1);
        $this->assertFileExists($scene2);
        $this->assertNotEmpty($clipFiles);

        $clipMtimes = [];

        foreach ($clipFiles as $path) {
            $clipMtimes[$path] = filemtime($path);
        }

        $scene1Mtime = filemtime($scene1);
        unlink($scene2);
        $this->assertFileDoesNotExist($scene2);

        sleep(1);

        $this->artisan('story:render', [
            'file' => $this->storyFile(),
            '--keep-intermediates' => true,
            '--no-grade' => true,
        ])->assertSuccessful();

        $this->assertFileExists($scene2);
        $this->assertGreaterThan($scene1Mtime, filemtime($scene2));
        $this->assertSame($scene1Mtime, filemtime($scene1));

        foreach ($clipMtimes as $path => $mtime) {
            $this->assertSame($mtime, filemtime($path), 'Se regeneró el clip '.$path);
        }
    }

    public function test_dry_run_fails_when_shots_do_not_cover_the_mix(): void
    {
        $dir = $this->storyDirectory();
        $this->writeShotsJson([
            $this->shotRow(1, 1, 0.0, 4.0, 'zoom_in', $dir.DIRECTORY_SEPARATOR.'shot-1.jpg'),
            $this->shotRow(2, 1, 4.0, 6.0, 'static', $dir.DIRECTORY_SEPARATOR.'shot-2.jpg'),
            $this->shotRow(3, 2, 6.0, 7.5, 'pan_left', $dir.DIRECTORY_SEPARATOR.'shot-3.jpg'),
        ], ShotPlanner::VERSION);

        $this->artisan('story:render', [
            'file' => $this->storyFile(),
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('Los planos cubren 7.500 s')
            ->expectsOutputToContain('hay bloqueantes')
            ->doesntExpectOutputToContain('Modo simulación')
            ->assertFailed();
    }

    public function test_dry_run_fails_when_planner_version_is_stale(): void
    {
        $dir = $this->storyDirectory();
        $this->writeShotsJson([
            $this->shotRow(1, 1, 0.0, 4.0, 'zoom_in', $dir.DIRECTORY_SEPARATOR.'shot-1.jpg'),
            $this->shotRow(2, 1, 4.0, 6.0, 'static', $dir.DIRECTORY_SEPARATOR.'shot-2.jpg'),
            $this->shotRow(3, 2, 6.0, 8.0, 'pan_left', $dir.DIRECTORY_SEPARATOR.'shot-3.jpg'),
        ], null);

        $this->artisan('story:render', [
            'file' => $this->storyFile(),
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('Plan de plannerVersion ausente; el actual es '.ShotPlanner::VERSION)
            ->expectsOutputToContain('hay bloqueantes')
            ->doesntExpectOutputToContain('Modo simulación')
            ->assertFailed();
    }

    private function writeFixture(): void
    {
        $dir = $this->storyDirectory();
        $red = $dir.DIRECTORY_SEPARATOR.'shot-1.jpg';
        $blue = $dir.DIRECTORY_SEPARATOR.'shot-2.jpg';
        $green = $dir.DIRECTORY_SEPARATOR.'shot-3.jpg';

        $this->writeJpeg($red, 'red');
        $this->writeJpeg($blue, 'blue');
        $this->writeJpeg($green, 'green');
        $this->writeWav($dir.DIRECTORY_SEPARATOR.'narration.wav', 8.0);
        $this->writeWav($dir.DIRECTORY_SEPARATOR.'narration_mix.wav', 8.0);

        $shots = [
            $this->shotRow(1, 1, 0.0, 4.0, 'zoom_in', $red),
            $this->shotRow(2, 1, 4.0, 6.0, 'static', $blue),
            $this->shotRow(3, 2, 6.0, 8.0, 'pan_left', $green),
        ];

        $this->writeShotsJson($shots, ShotPlanner::VERSION);

        file_put_contents($dir.DIRECTORY_SEPARATOR.'timings.json', json_encode([
            'version' => 1,
            'sentences' => [
                ['order' => 1, 'sceneOrder' => 1, 'text' => 'The door closed behind me.', 'start' => 0.0, 'end' => 4.0, 'pauseAfter' => 0.0, 'alignment' => 'text'],
                ['order' => 2, 'sceneOrder' => 1, 'text' => 'The hallway stayed empty.', 'start' => 4.0, 'end' => 6.0, 'pauseAfter' => 0.0, 'alignment' => 'text'],
                ['order' => 3, 'sceneOrder' => 2, 'text' => 'Then the whistle came closer.', 'start' => 6.0, 'end' => 8.0, 'pauseAfter' => 0.0, 'alignment' => 'text'],
            ],
            'scenes' => [
                ['order' => 1, 'start' => 0.0, 'end' => 6.0, 'duration' => 6.0, 'sentenceCount' => 2],
                ['order' => 2, 'start' => 6.0, 'end' => 8.0, 'duration' => 2.0, 'sentenceCount' => 1],
            ],
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");

        file_put_contents($this->storyFile(), json_encode([
            'title' => 'Render fixture',
            'hook' => 'The door closed.',
            'description' => 'A three-shot fixture for story:render.',
            'tags' => ['test'],
            'thumbnailPrompt' => 'An empty hallway',
            'scenes' => [
                [
                    'order' => 1,
                    'narration' => 'The door closed behind me. The hallway stayed empty.',
                    'imagePrompt' => 'A dim hallway',
                    'visualSummary' => 'A dim hallway vanishing into fog at dusk',
                ],
                [
                    'order' => 2,
                    'narration' => 'Then the whistle came closer.',
                    'imagePrompt' => 'Fog over a dirt road',
                    'visualSummary' => 'Fog hanging over a dirt road at dusk',
                ],
            ],
            'pronunciations' => [],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
    }

    /**
     * @param  list<array<string, mixed>>  $shots
     */
    private function writeShotsJson(array $shots, ?int $plannerVersion): void
    {
        $payload = [
            'version' => 1,
            'shots' => $shots,
        ];

        if ($plannerVersion !== null) {
            $payload['plannerVersion'] = $plannerVersion;
        }

        file_put_contents(
            $this->storyDirectory().DIRECTORY_SEPARATOR.'shots.json',
            json_encode($payload, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n",
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function shotRow(int $order, int $scene, float $start, float $end, string $motion, string $image): array
    {
        return [
            'order' => $order,
            'sceneOrder' => $scene,
            'start' => $start,
            'end' => $end,
            'sourceText' => 'Fixture shot '.$order,
            'framing' => 'wide',
            'motion' => $motion,
            'subject' => 'environment',
            'threatStage' => null,
            'description' => 'Fixture hallway '.$order,
            'characterSlugs' => [],
            'imagePath' => $image,
            'placeholder' => false,
        ];
    }

    private function writeJpeg(string $path, string $color): void
    {
        $process = new Process([
            'ffmpeg', '-nostdin', '-y', '-hide_banner',
            '-f', 'lavfi',
            '-i', 'color=c='.$color.':s=1280x720',
            '-frames:v', '1',
            $path,
        ]);
        $process->setTimeout(30);
        $process->mustRun();
    }

    private function writeWav(string $path, float $duration): void
    {
        $process = new Process([
            'ffmpeg', '-nostdin', '-y', '-hide_banner',
            '-f', 'lavfi',
            '-i', sprintf('sine=frequency=220:sample_rate=48000:duration=%.3f', $duration),
            '-ac', '2',
            '-ar', '48000',
            '-sample_fmt', 's16',
            $path,
        ]);
        $process->setTimeout(30);
        $process->mustRun();
    }

    /**
     * @return array{duration: float, pix_fmt: ?string, types: list<string>}
     */
    private function probe(string $path): array
    {
        $process = new Process([
            'ffprobe', '-v', 'error',
            '-show_entries', 'format=duration:stream=codec_type,pix_fmt',
            '-of', 'json',
            $path,
        ]);
        $process->setTimeout(30);
        $process->mustRun();

        /** @var array<string, mixed> $payload */
        $payload = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $types = [];
        $pix = null;

        foreach ($payload['streams'] ?? [] as $stream) {
            if (! is_array($stream)) {
                continue;
            }

            $type = (string) ($stream['codec_type'] ?? '');

            if ($type !== '') {
                $types[] = $type;
            }

            if ($type === 'video' && isset($stream['pix_fmt'])) {
                $pix = (string) $stream['pix_fmt'];
            }
        }

        return [
            'duration' => (float) ($payload['format']['duration'] ?? 0),
            'pix_fmt' => $pix,
            'types' => $types,
        ];
    }

    private function storyFile(): string
    {
        return storage_path('app/'.$this->storiesDir.DIRECTORY_SEPARATOR.$this->slug.'.json');
    }

    private function storyDirectory(): string
    {
        return storage_path('app/'.$this->storiesDir.DIRECTORY_SEPARATOR.$this->slug);
    }

    private function workDirectory(): string
    {
        return storage_path('app/'.$this->workDir.DIRECTORY_SEPARATOR.$this->slug);
    }
}
