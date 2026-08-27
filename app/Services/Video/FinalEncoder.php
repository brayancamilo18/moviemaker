<?php

declare(strict_types=1);

namespace App\Services\Video;

use App\Services\Ffmpeg\FfmpegRunner;
use App\Services\Ffmpeg\MediaProbe;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class FinalEncoder
{
    private readonly int $finalCrf;

    private readonly string $preset;

    private readonly float $saturation;

    private readonly float $contrast;

    private readonly float $grain;

    private readonly float $outroSeconds;

    private readonly float $syncTolerance;

    public function __construct(
        private Filesystem $files,
        private LoggerInterface $logger,
        private FfmpegRunner $ffmpeg,
        private MediaProbe $probe,
        Repository $config,
    ) {
        $this->finalCrf = (int) $config->get('stories.video.final_crf');
        $this->preset = (string) $config->get('stories.video.preset');
        $this->saturation = (float) $config->get('stories.video.grade.saturation');
        $this->contrast = (float) $config->get('stories.video.grade.contrast');
        $this->grain = (float) $config->get('stories.video.grade.grain');
        $this->outroSeconds = (float) $config->get('stories.video.outro_seconds');
        $this->syncTolerance = (float) $config->get('stories.video.sync_tolerance');
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

        $audioDuration = $this->probe->duration($audioPath);
        $expected = round($audioDuration + $this->outroSeconds, 3);

        $started = hrtime(true);
        $arguments = [
            '-nostdin', '-y', '-hide_banner',
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
        $arguments[] = 'apad=pad_dur='.$this->ffmpeg->formatNumber($this->outroSeconds);

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

        $this->ffmpeg->run($arguments);

        if (! $this->files->isFile($outputPath) || $this->files->size($outputPath) < 1) {
            throw new InvalidArgumentException('No se pudo escribir el vídeo final.');
        }

        $actual = $this->probe->duration($outputPath);

        if (abs($actual - $expected) > $this->syncTolerance) {
            throw new RuntimeException(sprintf(
                'El máster dura %.3f s y se esperaban %.3f s (audio %.3f s + outro %.3f s, tolerancia %.3f s). El vídeo mudo no cuadra con el audio: rehazlo con php artisan story:render {file} --from=assemble',
                $actual,
                $expected,
                $audioDuration,
                $this->outroSeconds,
                $this->syncTolerance,
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
            $this->ffmpeg->formatNumber($this->saturation),
            $this->ffmpeg->formatNumber($this->contrast),
            $this->ffmpeg->formatNumber($this->grain),
        );
    }

    private function formatBytes(int $bytes): string
    {
        return sprintf('%.1f MiB', $bytes / 1048576);
    }
}
