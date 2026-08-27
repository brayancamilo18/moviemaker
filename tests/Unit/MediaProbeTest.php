<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\FfmpegException;
use App\Services\Ffmpeg\MediaProbe;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class MediaProbeTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = storage_path('app/testing/media-probe-'.bin2hex(random_bytes(8)));
        $this->app->make(Filesystem::class)->ensureDirectoryExists($this->directory);
    }

    protected function tearDown(): void
    {
        $this->app->make(Filesystem::class)->deleteDirectory($this->directory);

        parent::tearDown();
    }

    public function test_it_reads_the_duration_of_a_fixture(): void
    {
        $fixture = $this->writeWav(1.5);

        $this->assertEqualsWithDelta(1.5, $this->probe()->duration($fixture), 0.05);
        $this->assertEqualsWithDelta(1.5, (float) $this->probe()->tryDuration($fixture), 0.05);
    }

    public function test_a_file_that_is_not_media_makes_the_strict_probe_throw(): void
    {
        $path = $this->directory.DIRECTORY_SEPARATOR.'not-media.wav';
        $this->app->make(Filesystem::class)->put($path, 'esto no es un WAV');

        $this->expectException(FfmpegException::class);
        $this->probe()->duration($path);
    }

    public function test_an_unprobeable_file_has_no_duration_instead_of_throwing(): void
    {
        $missing = $this->directory.DIRECTORY_SEPARATOR.'ausente.wav';
        $broken = $this->directory.DIRECTORY_SEPARATOR.'not-media.wav';
        $this->app->make(Filesystem::class)->put($broken, 'esto no es un WAV');

        $this->assertNull($this->probe()->tryDuration($missing));
        $this->assertNull($this->probe()->tryDuration($broken));
    }

    private function probe(): MediaProbe
    {
        return $this->app->make(MediaProbe::class);
    }

    private function writeWav(float $duration): string
    {
        $path = $this->directory.DIRECTORY_SEPARATOR.'fixture.wav';
        $process = new Process([
            'ffmpeg', '-nostdin', '-y', '-hide_banner',
            '-f', 'lavfi',
            '-i', sprintf('sine=frequency=440:sample_rate=48000:duration=%.3f', $duration),
            '-ac', '1',
            $path,
        ]);
        $process->setTimeout(30);
        $process->mustRun();

        return $path;
    }
}
