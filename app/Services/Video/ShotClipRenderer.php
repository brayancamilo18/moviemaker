<?php

declare(strict_types=1);

namespace App\Services\Video;

use App\DataObjects\Shot;
use App\Exceptions\FfmpegException;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use InvalidArgumentException;
use Symfony\Component\Process\Process;

final class ShotClipRenderer
{
    private readonly string $ffmpeg;

    private readonly int $nice;

    private readonly float $timeout;

    private readonly int $width;

    private readonly int $height;

    private readonly int $fps;

    private readonly float $sourceUpscale;

    private readonly float $zoomMax;

    private readonly int $intermediateCrf;

    private readonly float $transitionDuration;

    public function __construct(
        private Filesystem $files,
        Repository $config,
    ) {
        $this->ffmpeg = (string) $config->get('stories.ffmpeg.binary');
        $this->nice = (int) $config->get('stories.ffmpeg.nice');
        $this->timeout = (float) $config->get('stories.ffmpeg.timeout');
        $this->width = (int) $config->get('stories.video.width');
        $this->height = (int) $config->get('stories.video.height');
        $this->fps = (int) $config->get('stories.video.fps');
        $this->sourceUpscale = (float) $config->get('stories.video.source_upscale');
        $this->zoomMax = (float) $config->get('stories.video.zoom_max');
        $this->intermediateCrf = (int) $config->get('stories.video.intermediate_crf');
        $this->transitionDuration = (float) $config->get('stories.video.transition_duration');
    }

    public function durationFor(Shot $shot, bool $followedByXfade): float
    {
        $real = round(max(0.0, $shot->end - $shot->start), 3);

        if (! $followedByXfade) {
            return $real;
        }

        return round($real + $this->transitionDuration, 3);
    }

    public function render(Shot $shot, string $outputPath, bool $followedByXfade = false): string
    {
        $image = trim((string) $shot->imagePath);

        if ($image === '' || ! $this->files->isFile($image)) {
            throw new InvalidArgumentException(
                "El plano {$shot->order} no tiene imagen en disco.",
            );
        }

        $duration = $this->durationFor($shot, $followedByXfade);
        $frames = max(1, (int) round($duration * $this->fps));
        $filter = $this->filterGraph($shot->motion, $frames);

        $this->files->ensureDirectoryExists(dirname($outputPath));

        $this->run([
            $this->ffmpeg, '-nostdin', '-y', '-hide_banner',
            '-loop', '1',
            '-i', $image,
            '-vf', $filter,
            '-frames:v', (string) $frames,
            '-an',
            '-c:v', 'libx264',
            '-crf', (string) $this->intermediateCrf,
            '-preset', 'ultrafast',
            '-pix_fmt', 'yuv420p',
            $outputPath,
        ]);

        if (! $this->files->isFile($outputPath) || $this->files->size($outputPath) < 1) {
            throw new InvalidArgumentException("No se pudo escribir el clip del plano {$shot->order}.");
        }

        return $outputPath;
    }

    private function filterGraph(string $motion, int $frames): string
    {
        $scaleWidth = (int) round($this->width * $this->sourceUpscale);
        $scaleHeight = (int) round($this->height * $this->sourceUpscale);
        $zoom = $this->formatNumber($this->zoomMax);
        $centeredX = 'iw/2-(iw/zoom/2)';
        $centeredY = 'ih/2-(ih/zoom/2)';

        [$z, $x, $y] = match (mb_strtolower(trim($motion))) {
            'zoom_in' => ["1+({$zoom}-1)*on/{$frames}", $centeredX, $centeredY],
            'zoom_out' => ["{$zoom}-({$zoom}-1)*on/{$frames}", $centeredX, $centeredY],
            'pan_left' => [$zoom, "(iw-iw/zoom)*(1-on/{$frames})", $centeredY],
            'pan_right' => [$zoom, "(iw-iw/zoom)*on/{$frames}", $centeredY],
            default => ['1.02', $centeredX, $centeredY],
        };

        return sprintf(
            'scale=%d:%d:flags=lanczos,zoompan=z=\'%s\':x=\'%s\':y=\'%s\':d=%d:s=%dx%d:fps=%d,setsar=1',
            $scaleWidth,
            $scaleHeight,
            $z,
            $x,
            $y,
            $frames,
            $this->width,
            $this->height,
            $this->fps,
        );
    }

    private function formatNumber(float $value): string
    {
        $formatted = rtrim(rtrim(sprintf('%.4f', $value), '0'), '.');

        return $formatted === '' || $formatted === '-' ? '0' : $formatted;
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
