<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\FfmpegException;
use App\Services\Ffmpeg\FfmpegRunner;
use App\Services\Ffmpeg\MediaProbe;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class FfmpegRunnerTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = storage_path('app/testing/ffmpeg-runner-'.bin2hex(random_bytes(8)));
        $this->app->make(Filesystem::class)->ensureDirectoryExists($this->directory);
    }

    protected function tearDown(): void
    {
        $this->app->make(Filesystem::class)->deleteDirectory($this->directory);

        parent::tearDown();
    }

    public function test_it_runs_ffmpeg_over_a_fixture(): void
    {
        $fixture = $this->writeWav(1.0);
        $output = $this->directory.DIRECTORY_SEPARATOR.'trimmed.wav';

        $this->runner()->run([
            '-nostdin', '-y', '-hide_banner',
            '-i', $fixture,
            '-t', '0.5',
            $output,
        ]);

        $this->assertFileExists($output);
        $this->assertEqualsWithDelta(0.5, $this->app->make(MediaProbe::class)->duration($output), 0.05);
    }

    public function test_a_failed_invocation_throws_with_the_command_and_the_stderr(): void
    {
        $output = $this->directory.DIRECTORY_SEPARATOR.'nunca.wav';

        try {
            $this->runner()->run([
                '-nostdin', '-y', '-hide_banner',
                '-i', $this->directory.DIRECTORY_SEPARATOR.'ausente.wav',
                $output,
            ]);
            $this->fail('Se esperaba una FfmpegException.');
        } catch (FfmpegException $exception) {
            $this->assertStringContainsString('ausente.wav', $exception->command);
            $this->assertNotSame('', $exception->errorOutput);
        }

        $this->assertFileDoesNotExist($output);
    }

    public function test_it_formats_filter_numbers_without_trailing_zeros(): void
    {
        $runner = $this->runner();

        $this->assertSame('0.5', $runner->formatNumber(0.5));
        $this->assertSame('20', $runner->formatNumber(20.0));
        $this->assertSame('0', $runner->formatNumber(0.0));
        $this->assertSame('1.2346', $runner->formatNumber(1.23456));
        $this->assertSame('-1.25', $runner->formatNumber(-1.25));
    }

    private function runner(): FfmpegRunner
    {
        return $this->app->make(FfmpegRunner::class);
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
