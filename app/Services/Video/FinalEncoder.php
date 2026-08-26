<?php

declare(strict_types=1);

namespace App\Services\Video;

use App\Exceptions\FfmpegException;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Process\Process;

final class FinalEncoder
{
    private readonly string $ffmpeg;

    private readonly int $nice;

    private readonly float $timeout;

    private readonly int $finalCrf;

    private readonly string $preset;

    private readonly float $saturation;

    private readonly float $contrast;

    private readonly float $grain;

    private readonly float $outroSeconds;

    private readonly string $ffprobe;

    public function __construct(
        private Filesystem $files,
        private LoggerInterface $logger,
        Repository $config,
    ) {
        $this->ffmpeg = (string) $config->get('stories.ffmpeg.binary');
        $this->nice = (int) $config->get('stories.ffmpeg.nice');
        $this->timeout = (float) $config->get('stories.ffmpeg.timeout');
        $this->finalCrf = (int) $config->get('stories.video.final_crf');
        $this->preset = (string) $config->get('stories.video.preset');
        $this->saturation = (float) $config->get('stories.video.grade.saturation');
        $this->contrast = (float) $config->get('stories.video.grade.contrast');
        $this->grain = (float) $config->get('stories.video.grade.grain');
        $this->outroSeconds = (float) $config->get('stories.video.outro_seconds');
        $this->ffprobe = (string) $config->get('stories.ffmpeg.ffprobe');
    }

    public function encode(string $videoPath, string $audioPath, string $outputPath, bool $grade = true): string
    {
        $videoPath = trim($videoPath);
        $audioPath = trim($audioPath);

        if ($videoPath === '' || ! $this->files->isFile($videoPath)) {
            throw new InvalidArgumentException('Falta el vídeo mudo para la codificación final.');
        }

        if ($audioPath === '' || ! $this->files->isFile($audioPath)) {
            throw new InvalidArgumentException('Falta el mix de audio para la codificación final.');
        }

        $this->files->ensureDirectoryExists(dirname($outputPath));

        $audioDuration = $this->probeDuration($audioPath);
        $expected = round($audioDuration + $this->outroSeconds, 3);

        $started = hrtime(true);
        $arguments = [
            $this->ffmpeg, '-nostdin', '-y', '-hide_banner',
            '-i', $videoPath,
            '-i', $audioPath,
            '-map', '0:v:0',
            '-map', '1:a:0',
        ];

        $vf = $grade
            ? $this->gradeFilter().',scale=out_range=limited,format=yuv420p'
            : 'scale=out_range=limited,format=yuv420p';
        $arguments[] = '-vf';
        $arguments[] = $vf;
        $arguments[] = '-af';
        $arguments[] = 'apad=pad_dur='.$this->formatNumber($this->outroSeconds);

        $arguments = [
            ...$arguments,
            '-c:v', 'libx264',
            '-crf', (string) $this->finalCrf,
            '-preset', $this->preset,
            '-pix_fmt', 'yuv420p',
            '-c:a', 'aac',
            '-b:a', '192k',
            '-ar', '48000',
            '-movflags', '+faststart',
            '-shortest',
            $outputPath,
        ];

        $this->run($arguments);

        if (! $this->files->isFile($outputPath) || $this->files->size($outputPath) < 1) {
            throw new InvalidArgumentException('No se pudo escribir el vídeo final.');
        }

        $actual = $this->probeDuration($outputPath);

        if (abs($actual - $expected) > 0.1) {
            throw new RuntimeException(sprintf(
                'El máster dura %.3f s y se esperaban %.3f s (audio %.3f s + outro %.3f s).',
                $actual,
                $expected,
                $audioDuration,
                $this->outroSeconds,
            ));
        }

        $elapsed = (hrtime(true) - $started) / 1e9;
        $bytes = $this->files->size($outputPath);

        $this->logger->info(sprintf(
            'Codificación final: %.1f s, %s.',
            $elapsed,
            $this->formatBytes($bytes),
        ), [
            'output' => $outputPath,
            'seconds' => round($elapsed, 1),
            'bytes' => $bytes,
            'grade' => $grade,
        ]);

        return $outputPath;
    }

    private function gradeFilter(): string
    {
        return sprintf(
            'eq=saturation=%s:contrast=%s,curves=preset=darker,vignette=PI/5:mode=backward,noise=alls=%s:allf=t+u,unsharp=5:5:0.3',
            $this->formatNumber($this->saturation),
            $this->formatNumber($this->contrast),
            $this->formatNumber($this->grain),
        );
    }

    private function formatBytes(int $bytes): string
    {
        return sprintf('%.1f MiB', $bytes / 1048576);
    }

    private function formatNumber(float $value): string
    {
        $formatted = rtrim(rtrim(sprintf('%.4f', $value), '0'), '.');

        return $formatted === '' || $formatted === '-' ? '0' : $formatted;
    }

    private function probeDuration(string $path): float
    {
        $process = new Process([
            $this->ffprobe, '-v', 'error',
            '-show_entries', 'format=duration',
            '-of', 'csv=p=0',
            $path,
        ]);
        $process->setTimeout($this->timeout);
        $process->run();

        if (! $process->isSuccessful()) {
            throw FfmpegException::fromProcess($process);
        }

        $duration = (float) trim($process->getOutput());

        if ($duration <= 0) {
            throw new RuntimeException('ffprobe no pudo leer la duración de '.$path.'.');
        }

        return round($duration, 3);
    }

    /**
     * @param  list<string>  $arguments
     */
    private function run(array $arguments): void
    {
        $process = new Process(['nice', '-n', (string) $this->nice, ...$arguments]);
        $process->setTimeout($this->timeout);
        $process->run();

        if (! $process->isSuccessful()) {
            throw FfmpegException::fromProcess($process);
        }
    }
}
