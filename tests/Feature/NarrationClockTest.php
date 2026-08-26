<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Audio\AmbienceBuilder;
use App\Services\Audio\NarrationClock;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class NarrationClockTest extends TestCase
{
    private string $workDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workDir = storage_path('app/testing/narration-clock-'.bin2hex(random_bytes(4)));
        (new Filesystem)->ensureDirectoryExists($this->workDir);
        $this->app->make('config')->set('stories.audio.tail_seconds', 10.0);
        $this->app->forgetInstance(AmbienceBuilder::class);
        $this->app->forgetInstance(NarrationClock::class);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->workDir);

        parent::tearDown();
    }

    public function test_expected_duration_uses_wav_length_not_whisper_timestamps(): void
    {
        $wav = $this->makeSilentWav(100.0);
        $timings = [
            'sentences' => [
                ['order' => 1, 'sceneOrder' => 1, 'text' => 'The door closed.', 'start' => 0.0, 'end' => 92.0],
            ],
            'scenes' => [
                ['order' => 1, 'start' => 0.0, 'end' => 92.0, 'duration' => 92.0],
            ],
        ];

        $tail = (float) $this->app->make('config')->get('stories.audio.tail_seconds');
        $builder = $this->app->make(AmbienceBuilder::class);

        $this->assertSame(92.0, $builder->lastTranscribedPhraseEnd($timings));
        $this->assertEqualsWithDelta(100.0 + $tail, $builder->expectedDuration($wav), 0.05);
        $this->assertNotEquals(round(92.0 + $tail, 3), $builder->expectedDuration($wav));
    }

    private function makeSilentWav(float $duration): string
    {
        $path = $this->workDir.DIRECTORY_SEPARATOR.'narration.wav';

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
        $process->setTimeout(60);
        $process->mustRun();

        return $path;
    }
}
