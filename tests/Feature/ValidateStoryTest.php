<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Image\ShotPlanner;
use App\Services\Story\StoryValidator;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class ValidateStoryTest extends TestCase
{
    private string $storiesDir;

    private string $slug = 'validate-fixture';

    protected function setUp(): void
    {
        parent::setUp();

        $this->storiesDir = 'testing/validate-stories-'.bin2hex(random_bytes(4));

        config([
            'stories.output_path' => $this->storiesDir,
            'stories.audio.tail_seconds' => 0.0,
        ]);
        $this->app->forgetInstance(StoryValidator::class);

        (new Filesystem)->ensureDirectoryExists($this->storyDirectory());
        $this->writeFixture();
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory(storage_path('app/'.$this->storiesDir));

        parent::tearDown();
    }

    public function test_validate_passes_a_coherent_story(): void
    {
        $this->artisan('story:validate', ['file' => $this->storyFile()])
            ->expectsOutputToContain('sin bloqueantes')
            ->assertSuccessful();
    }

    public function test_validate_fails_when_a_shot_has_no_description(): void
    {
        $dir = $this->storyDirectory();
        $this->writeShots([
            $this->shot(1, 0.0, 4.0, $dir.DIRECTORY_SEPARATOR.'shot-1.jpg', ''),
            $this->shot(2, 4.0, 8.0, $dir.DIRECTORY_SEPARATOR.'shot-2.jpg', 'Fog over the road'),
        ]);

        $this->artisan('story:validate', ['file' => $this->storyFile()])
            ->expectsOutputToContain('hay bloqueantes')
            ->expectsOutputToContain('Sin description')
            ->assertFailed();
    }

    private function writeFixture(): void
    {
        $dir = $this->storyDirectory();
        $this->writeJpeg($dir.DIRECTORY_SEPARATOR.'shot-1.jpg');
        $this->writeJpeg($dir.DIRECTORY_SEPARATOR.'shot-2.jpg');
        $this->writeWav($dir.DIRECTORY_SEPARATOR.'narration.wav', 8.0);
        $this->writeWav($dir.DIRECTORY_SEPARATOR.'narration_mix.wav', 8.0);
        $this->writeShots([
            $this->shot(1, 0.0, 4.0, $dir.DIRECTORY_SEPARATOR.'shot-1.jpg', 'A dim hallway'),
            $this->shot(2, 4.0, 8.0, $dir.DIRECTORY_SEPARATOR.'shot-2.jpg', 'Fog over the road'),
        ]);

        file_put_contents($this->storyFile(), json_encode([
            'title' => 'Validate fixture',
            'hook' => 'The door closed.',
            'description' => 'A two-shot fixture for story:validate.',
            'tags' => ['test'],
            'thumbnailPrompt' => 'An empty hallway',
            'scenes' => [[
                'order' => 1,
                'narration' => 'The door closed behind me.',
                'imagePrompt' => 'A dim hallway',
                'visualSummary' => 'A dim hallway vanishing into fog at dusk',
            ]],
            'pronunciations' => [],
        ], JSON_THROW_ON_ERROR)."\n");
    }

    /**
     * @param  list<array<string, mixed>>  $shots
     */
    private function writeShots(array $shots): void
    {
        file_put_contents($this->storyDirectory().DIRECTORY_SEPARATOR.'shots.json', json_encode([
            'version' => 1,
            'plannerVersion' => ShotPlanner::VERSION,
            'shots' => $shots,
        ], JSON_THROW_ON_ERROR)."\n");
    }

    /**
     * @return array<string, mixed>
     */
    private function shot(int $order, float $start, float $end, string $image, string $description): array
    {
        return [
            'order' => $order,
            'sceneOrder' => 1,
            'start' => $start,
            'end' => $end,
            'sourceText' => 'Fixture shot '.$order,
            'framing' => 'medium shot',
            'motion' => 'static',
            'subject' => 'environment',
            'threatStage' => null,
            'description' => $description,
            'characterSlugs' => [],
            'imagePath' => $image,
            'placeholder' => false,
        ];
    }

    private function writeJpeg(string $path): void
    {
        $process = new Process([
            'ffmpeg', '-nostdin', '-y', '-hide_banner',
            '-f', 'lavfi',
            '-i', 'color=c=gray:s=640x360',
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

    private function storyFile(): string
    {
        return storage_path('app/'.$this->storiesDir.DIRECTORY_SEPARATOR.$this->slug.'.json');
    }

    private function storyDirectory(): string
    {
        return storage_path('app/'.$this->storiesDir.DIRECTORY_SEPARATOR.$this->slug);
    }
}
