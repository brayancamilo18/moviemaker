<?php

declare(strict_types=1);

namespace App\Services\Audio;

use App\Exceptions\FfmpegException;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use RuntimeException;
use Symfony\Component\Process\Process;

final class LibraryClipProcessor
{
    private readonly string $ffmpeg;

    private readonly string $ffprobe;

    private readonly int $nice;

    private readonly float $timeout;

    public function __construct(
        private Filesystem $files,
        Repository $config,
    ) {
        $this->ffmpeg = (string) $config->get('stories.ffmpeg.binary');
        $this->ffprobe = (string) $config->get('stories.ffmpeg.ffprobe');
        $this->nice = (int) $config->get('stories.ffmpeg.nice');
        $this->timeout = (float) $config->get('stories.ffmpeg.timeout');
    }

    public function assertAudio(string $path): void
    {
        $process = $this->run([
            $this->ffprobe, '-v', 'error',
            '-select_streams', 'a:0',
            '-show_entries', 'stream=codec_type,codec_name,sample_rate',
            '-of', 'json',
            $path,
        ]);

        $payload = json_decode($process->getOutput(), true);
        $stream = is_array($payload) ? ($payload['streams'][0] ?? null) : null;
        $codecType = is_array($stream) ? (string) ($stream['codec_type'] ?? '') : '';
        $codec = is_array($stream) ? (string) ($stream['codec_name'] ?? '') : '';
        $sampleRate = is_array($stream) ? (int) ($stream['sample_rate'] ?? 0) : 0;

        if ($codecType !== 'audio' || $codec === '' || $sampleRate < 1) {
            throw new RuntimeException('ffprobe no reconoció audio real en '.$path.'.');
        }
    }

    public function convertToLibraryWav(string $source, string $destination): void
    {
        $this->files->ensureDirectoryExists(dirname($destination));

        $this->run([
            $this->ffmpeg, '-nostdin', '-y', '-hide_banner',
            '-i', $source,
            '-ac', '2',
            '-ar', '48000',
            '-sample_fmt', 's16',
            $destination,
        ]);
    }

    public function duration(string $path): float
    {
        $process = $this->run([
            $this->ffprobe, '-v', 'error',
            '-show_entries', 'format=duration',
            '-of', 'csv=p=0',
            $path,
        ]);

        $duration = (float) trim($process->getOutput());

        if ($duration <= 0) {
            throw new RuntimeException('ffprobe no pudo leer la duración de '.$path.'.');
        }

        return round($duration, 3);
    }

    public function integratedLufs(string $path): float
    {
        $process = $this->run([
            $this->ffmpeg, '-nostdin', '-hide_banner',
            '-i', $path,
            '-filter:a', 'ebur128',
            '-f', 'null',
            '-',
        ]);

        $stderr = $process->getErrorOutput();

        if (preg_match_all('/I:\s*(-?(?:\d+\.?\d*|inf))\s*LUFS/i', $stderr, $matches) === 0) {
            throw new RuntimeException('FFmpeg no devolvió loudness ebur128 para '.$path.'.');
        }

        $value = (float) $matches[1][array_key_last($matches[1])];

        return round($value, 1);
    }

    public function isLoopable(string $path, float $duration): bool
    {
        if ($duration < 1.0) {
            return false;
        }

        $head = $this->rmsDb($path, 0.0, 0.5);
        $tail = $this->rmsDb($path, max(0.0, $duration - 0.5), 0.5);

        if ($head === null || $tail === null) {
            return false;
        }

        return abs($head - $tail) <= 6.0;
    }

    public function isSilent(string $path, float $thresholdDb = -50.0): bool
    {
        $rms = $this->rmsDb($path, 0.0, 2.0);

        return $rms === null || $rms < $thresholdDb;
    }

    private function rmsDb(string $path, float $start, float $length): ?float
    {
        $process = $this->run([
            $this->ffmpeg, '-nostdin', '-hide_banner',
            '-ss', sprintf('%.3f', $start),
            '-t', sprintf('%.3f', $length),
            '-i', $path,
            '-af', 'astats=metadata=1:reset=1',
            '-f', 'null',
            '-',
        ]);

        if (preg_match('/RMS level dB:\s*(-?(?:\d+\.?\d*|inf))/i', $process->getErrorOutput(), $matches) !== 1) {
            return null;
        }

        if (strtolower($matches[1]) === 'inf' || strtolower($matches[1]) === '-inf') {
            return null;
        }

        return (float) $matches[1];
    }

    /**
     * @param  list<string>  $arguments
     */
    private function run(array $arguments): Process
    {
        $process = new Process(['nice', '-n', (string) $this->nice, ...$arguments]);
        $process->setTimeout($this->timeout);
        $process->run();

        if (! $process->isSuccessful()) {
            throw FfmpegException::fromProcess($process);
        }

        return $process;
    }
}
