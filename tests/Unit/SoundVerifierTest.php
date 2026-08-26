<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Audio\SoundVerifier;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class SoundVerifierTest extends TestCase
{
    private string $workDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workDir = storage_path('app/testing/verifier-'.bin2hex(random_bytes(4)));
        (new Filesystem)->ensureDirectoryExists($this->workDir);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->workDir);

        parent::tearDown();
    }

    public function test_rejects_missing_or_tiny_files_first(): void
    {
        $tiny = $this->workDir.'/tiny.wav';
        file_put_contents($tiny, 'nope');

        $result = $this->verifier()->verify($tiny, 'sfx', 0.0);

        $this->assertFalse($result->passed);
        $this->assertCount(1, $result->failures);
        $this->assertStringContainsString('5 KB', $result->failures[0]);
    }

    public function test_rejects_html_saved_as_mp3_at_step_two(): void
    {
        $path = $this->workDir.'/error.mp3';
        file_put_contents($path, str_repeat('<html>not audio</html>', 400));

        $result = $this->verifier()->verify($path, 'sfx', 0.0);

        $this->assertFalse($result->passed);
        $this->assertCount(1, $result->failures);
        $this->assertStringContainsString('ffprobe', $result->failures[0]);
        $this->assertStringNotContainsString('mudo', $result->failures[0]);
        $this->assertStringNotContainsString('pico', $result->failures[0]);
    }

    public function test_rejects_shorter_than_min_duration(): void
    {
        $path = $this->sine('short.wav', 1.0, '-12dB');

        $result = $this->verifier()->verify($path, 'sfx', 3.0);

        $this->assertFalse($result->passed);
        $this->assertStringContainsString('minDuration', $result->failures[0]);
    }

    public function test_rejects_silence_at_step_four(): void
    {
        $path = $this->workDir.'/silence.wav';
        $this->ffmpeg([
            '-f', 'lavfi',
            '-i', 'anullsrc=r=48000:cl=stereo:d=2',
            '-ac', '2',
            '-ar', '48000',
            '-sample_fmt', 's16',
            $path,
        ]);

        $result = $this->verifier()->verify($path, 'sfx', 0.0);

        $this->assertFalse($result->passed);
        $this->assertCount(1, $result->failures);
        $this->assertStringContainsString('mudo', $result->failures[0]);
        $this->assertStringNotContainsString('pico', $result->failures[0]);
        $this->assertStringNotContainsString('cama', $result->failures[0]);
    }

    public function test_rejects_an_inaudible_sfx_hit(): void
    {
        $path = $this->sine('whisper.wav', 1.0, '-48dB');

        $result = $this->verifier()->verify($path, 'sfx', 0.0);

        $this->assertFalse($result->passed);
        $this->assertCount(1, $result->failures);
    }

    public function test_rejects_an_inaudible_ambience_bed(): void
    {
        $path = $this->sine('whisper-bed.wav', 3.0, '-48dB');

        $result = $this->verifier()->verify($path, 'ambience', 0.0);

        $this->assertFalse($result->passed);
        $this->assertCount(1, $result->failures);
        $this->assertStringContainsString('mudo', $result->failures[0]);
    }

    public function test_rejects_clipped_peak(): void
    {
        $path = $this->sine('clip.wav', 1.0, '24dB');

        $result = $this->verifier()->verify($path, 'sfx', 0.0);

        $this->assertFalse($result->passed);
        $this->assertStringContainsString('pico', $result->failures[0]);
    }

    public function test_rejects_ambience_with_a_slam_in_the_last_third_at_step_six(): void
    {
        $bed = $this->sine('bed.wav', 4.0, '-28dB');
        $slam = $this->sine('slam.wav', 2.0, '6dB');
        $path = $this->workDir.'/late-slam.wav';

        $this->ffmpeg([
            '-i', $bed,
            '-i', $slam,
            '-filter_complex', 'concat=n=2:v=0:a=1',
            $path,
        ]);

        $result = $this->verifier()->verify($path, 'ambience', 0.0);

        $this->assertFalse($result->passed);
        $this->assertCount(1, $result->failures);
        $this->assertStringContainsString('cama', $result->failures[0]);
        $this->assertStringContainsString('último', $result->failures[0]);
    }

    public function test_accepts_a_clean_sfx_and_uniform_ambience(): void
    {
        $sfx = $this->sine('ok-sfx.wav', 1.0, '-12dB');
        $ambience = $this->sine('ok-ambience.wav', 3.0, '-12dB');

        $sfxResult = $this->verifier()->verify($sfx, 'sfx', 0.5);
        $ambienceResult = $this->verifier()->verify($ambience, 'ambience', 1.0);

        $this->assertTrue($sfxResult->passed, implode('; ', $sfxResult->failures));
        $this->assertSame([], $sfxResult->failures);
        $this->assertTrue($ambienceResult->passed, implode('; ', $ambienceResult->failures));
        $this->assertSame([], $ambienceResult->failures);
    }

    private function verifier(): SoundVerifier
    {
        return $this->app->make(SoundVerifier::class);
    }

    private function sine(string $name, float $duration, string $volume): string
    {
        $path = $this->workDir.'/'.$name;
        $this->ffmpeg([
            '-f', 'lavfi',
            '-i', sprintf('sine=frequency=220:sample_rate=48000:duration=%.3f', $duration),
            '-ac', '2',
            '-ar', '48000',
            '-sample_fmt', 's16',
            '-af', 'volume='.$volume,
            $path,
        ]);

        return $path;
    }

    /**
     * @param  list<string>  $arguments
     */
    private function ffmpeg(array $arguments): void
    {
        $process = new Process([
            'ffmpeg', '-nostdin', '-y', '-hide_banner',
            ...$arguments,
        ]);
        $process->setTimeout(30);
        $process->mustRun();
    }
}
