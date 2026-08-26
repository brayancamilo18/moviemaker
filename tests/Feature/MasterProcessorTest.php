<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Audio\MasterProcessor;
use Illuminate\Filesystem\Filesystem;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class MasterProcessorTest extends TestCase
{
    private string $workDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workDir = storage_path('app/testing/master-'.bin2hex(random_bytes(4)));
        (new Filesystem)->ensureDirectoryExists($this->workDir);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->workDir);

        parent::tearDown();
    }

    public function test_process_writes_wav_and_320k_mp3_near_target_loudness(): void
    {
        $mix = $this->makeWav('mix.wav', 3.0);
        $output = $this->workDir.DIRECTORY_SEPARATOR.'out';

        $paths = $this->app->make(MasterProcessor::class)->process($mix, $output, 3.0);

        $this->assertSame($output.DIRECTORY_SEPARATOR.'narration_mix.wav', $paths['wav']);
        $this->assertSame($output.DIRECTORY_SEPARATOR.'narration_mix.mp3', $paths['mp3']);
        $this->assertFileExists($paths['wav']);
        $this->assertFileExists($paths['mp3']);

        $wav = $this->probe($paths['wav']);
        $this->assertSame(48000, $wav['sample_rate']);
        $this->assertSame(2, $wav['channels']);
        $this->assertSame('pcm_s16le', $wav['codec']);

        $mp3 = $this->probe($paths['mp3']);
        $this->assertSame('mp3', $mp3['codec']);
        $this->assertGreaterThan(280000, $mp3['bit_rate']);

        $measured = $this->app->make(MasterProcessor::class)->measure($paths['wav']);
        $this->assertEqualsWithDelta(-14.0, $measured['lufs'], 1.5);
        $this->assertLessThan(0.0, $measured['truePeak']);
        $this->assertGreaterThanOrEqual(0.0, $measured['lra']);
    }

    public function test_measure_returns_lufs_true_peak_and_lra(): void
    {
        $path = $this->makeWav('tone.wav', 2.5);

        $measured = $this->app->make(MasterProcessor::class)->measure($path);

        $this->assertArrayHasKey('lufs', $measured);
        $this->assertArrayHasKey('truePeak', $measured);
        $this->assertArrayHasKey('lra', $measured);
        $this->assertFinite($measured['lufs']);
        $this->assertFinite($measured['truePeak']);
        $this->assertFinite($measured['lra']);
    }

    public function test_process_rejects_a_missing_mix(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->app->make(MasterProcessor::class)->process(
            $this->workDir.DIRECTORY_SEPARATOR.'missing.wav',
            $this->workDir,
            1.0,
        );
    }

    public function test_process_rejects_trimming_more_than_half_a_second_of_audio(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('El recorte eliminaría');

        $this->app->make(MasterProcessor::class)->process(
            $this->makeWav('long.wav', 5.0),
            $this->workDir.DIRECTORY_SEPARATOR.'trim',
            3.0,
        );
    }

    public function test_process_pads_a_short_mix_to_the_target_duration(): void
    {
        $padded = $this->app->make(MasterProcessor::class)->process(
            $this->makeWav('short.wav', 2.0),
            $this->workDir.DIRECTORY_SEPARATOR.'pad',
            3.5,
        );

        $this->assertEqualsWithDelta(3.5, $this->probe($padded['wav'])['duration'], 0.05);
    }

    /**
     * @return array{sample_rate: int, channels: int, codec: string, bit_rate: int, duration: float}
     */
    private function probe(string $path): array
    {
        $process = new Process([
            'ffprobe', '-v', 'error',
            '-select_streams', 'a:0',
            '-show_entries', 'stream=sample_rate,channels,codec_name:format=duration,bit_rate',
            '-of', 'json',
            $path,
        ]);
        $process->setTimeout(30);
        $process->mustRun();

        /** @var array<string, mixed> $payload */
        $payload = json_decode($process->getOutput(), true);
        $stream = is_array($payload['streams'][0] ?? null) ? $payload['streams'][0] : [];
        $format = is_array($payload['format'] ?? null) ? $payload['format'] : [];

        return [
            'sample_rate' => (int) ($stream['sample_rate'] ?? 0),
            'channels' => (int) ($stream['channels'] ?? 0),
            'codec' => (string) ($stream['codec_name'] ?? ''),
            'bit_rate' => (int) ($format['bit_rate'] ?? 0),
            'duration' => (float) ($format['duration'] ?? 0),
        ];
    }

    private function makeWav(string $name, float $duration): string
    {
        $path = $this->workDir.DIRECTORY_SEPARATOR.$name;

        $process = new Process([
            'ffmpeg', '-nostdin', '-y', '-hide_banner',
            '-f', 'lavfi',
            '-i', sprintf('sine=frequency=440:sample_rate=48000:duration=%.3f', $duration),
            '-ac', '2',
            '-ar', '48000',
            '-sample_fmt', 's16',
            $path,
        ]);
        $process->setTimeout(30);
        $process->mustRun();

        return $path;
    }
}
