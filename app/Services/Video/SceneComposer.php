<?php

declare(strict_types=1);

namespace App\Services\Video;

use App\DataObjects\Shot;
use App\Exceptions\FfmpegException;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Process\Process;

final class SceneComposer
{
    private readonly string $ffmpeg;

    private readonly string $ffprobe;

    private readonly int $nice;

    private readonly float $timeout;

    private readonly int $fps;

    private readonly float $transitionDuration;

    private readonly int $intermediateCrf;

    public function __construct(
        private Filesystem $files,
        Repository $config,
    ) {
        $this->ffmpeg = (string) $config->get('stories.ffmpeg.binary');
        $this->ffprobe = (string) $config->get('stories.ffmpeg.ffprobe');
        $this->nice = (int) $config->get('stories.ffmpeg.nice');
        $this->timeout = (float) $config->get('stories.ffmpeg.timeout');
        $this->fps = (int) $config->get('stories.video.fps');
        $this->transitionDuration = (float) $config->get('stories.video.transition_duration');
        $this->intermediateCrf = (int) $config->get('stories.video.intermediate_crf');
    }

    /**
     * @param  list<array{path: string, shot: Shot}>  $clips
     */
    public function compose(array $clips, string $outputPath): string
    {
        if ($clips === []) {
            throw new InvalidArgumentException('No hay clips de plano para componer la escena.');
        }

        foreach ($clips as $index => $clip) {
            $path = is_array($clip) ? trim((string) ($clip['path'] ?? '')) : '';

            if ($path === '' || ! $this->files->isFile($path)) {
                throw new InvalidArgumentException("Falta el clip del plano {$index}.");
            }

            if (! (($clip['shot'] ?? null) instanceof Shot)) {
                throw new InvalidArgumentException("El clip {$index} no trae un Shot.");
            }
        }

        /** @var list<Shot> $shots */
        $shots = array_map(static fn (array $clip): Shot => $clip['shot'], $clips);
        $plan = $this->calculateOffsets($shots);

        $this->files->ensureDirectoryExists(dirname($outputPath));

        if (count($clips) === 1) {
            $this->run([
                $this->ffmpeg, '-nostdin', '-y', '-hide_banner',
                '-i', $clips[0]['path'],
                '-an',
                '-c:v', 'libx264',
                '-crf', (string) $this->intermediateCrf,
                '-preset', 'ultrafast',
                '-pix_fmt', 'yuv420p',
                $outputPath,
            ]);
        } else {
            $arguments = [$this->ffmpeg, '-nostdin', '-y', '-hide_banner'];

            foreach ($clips as $clip) {
                $arguments[] = '-i';
                $arguments[] = $clip['path'];
            }

            $arguments[] = '-filter_complex';
            $arguments[] = $this->filterGraph($plan['offsets']);
            $arguments[] = '-map';
            $arguments[] = '[out]';
            $arguments[] = '-an';
            $arguments[] = '-c:v';
            $arguments[] = 'libx264';
            $arguments[] = '-crf';
            $arguments[] = (string) $this->intermediateCrf;
            $arguments[] = '-preset';
            $arguments[] = 'ultrafast';
            $arguments[] = '-pix_fmt';
            $arguments[] = 'yuv420p';
            $arguments[] = $outputPath;

            $this->run($arguments);
        }

        if (! $this->files->isFile($outputPath) || $this->files->size($outputPath) < 1) {
            throw new InvalidArgumentException('No se pudo escribir el clip de escena.');
        }

        $this->fitToDuration($outputPath, $plan['duration']);

        $actual = $this->probeDuration($outputPath);
        $tolerance = 1 / max(1, $this->fps);

        if (abs($actual - $plan['duration']) > $tolerance) {
            throw new RuntimeException(sprintf(
                'El clip de escena dura %.3f s y se esperaban %.3f s (tolerancia %.3f s, 1 frame).',
                $actual,
                $plan['duration'],
                $tolerance,
            ));
        }

        return $outputPath;
    }

    /**
     * Offset de cada empalme sobre los ficheros en disco (reales + pad de xfade).
     *
     * Un clip seguido de xfade dura real + D; el último de la escena y el que
     * empalma con corte seco no se alargan. Con ese pad, offset = acumulado − D
     * y la escena dura la suma de duraciones reales.
     *
     * @param  list<Shot>  $shots
     * @return array{offsets: list<float|null>, duration: float}
     */
    public function calculateOffsets(array $shots): array
    {
        $fade = $this->transitionDuration;
        $offsets = [];
        $accumulated = 0.0;

        foreach ($shots as $index => $shot) {
            $file = $this->fileDuration($shots, $index);

            if ($index === 0) {
                $accumulated = $file;

                continue;
            }

            if ($this->isHardCut($shot)) {
                $offsets[] = null;
                $accumulated = round($accumulated + $file, 3);

                continue;
            }

            $offset = round(max(0.0, $accumulated - $fade), 3);
            $offsets[] = $offset;
            $accumulated = round($accumulated + $file - $fade, 3);
        }

        return [
            'offsets' => $offsets,
            'duration' => round($accumulated, 3),
        ];
    }

    /**
     * @param  list<float|null>  $offsets
     */
    private function filterGraph(array $offsets): string
    {
        $fade = $this->formatNumber($this->transitionDuration);
        $last = '0';
        $filters = [];
        $joins = count($offsets);

        foreach ($offsets as $index => $offset) {
            $incoming = (string) ($index + 1);
            $label = $index === $joins - 1 ? 'out' : 'v'.($index + 1);

            if ($offset === null) {
                $filters[] = sprintf('[%s][%s]concat=n=2:v=1:a=0[%s]', $last, $incoming, $label);
            } else {
                $filters[] = sprintf(
                    '[%s][%s]xfade=transition=fade:duration=%s:offset=%s[%s]',
                    $last,
                    $incoming,
                    $fade,
                    $this->formatNumber($offset),
                    $label,
                );
            }

            $last = $label;
        }

        return implode(';', $filters);
    }

    private function isHardCut(Shot $shot): bool
    {
        return mb_strtolower(trim($shot->motion)) === 'static';
    }

    private function realDuration(Shot $shot): float
    {
        return round(max(0.0, $shot->end - $shot->start), 3);
    }

    /**
     * @param  list<Shot>  $shots
     */
    private function fileDuration(array $shots, int $index): float
    {
        $real = $this->realDuration($shots[$index]);
        $next = $shots[$index + 1] ?? null;

        if ($next === null || $this->isHardCut($next)) {
            return $real;
        }

        return round($real + $this->transitionDuration, 3);
    }

    private function fitToDuration(string $path, float $duration): void
    {
        $duration = round(max(0.001, $duration), 3);
        $frames = max(1, (int) round($duration * $this->fps));
        $fitted = $path.'.fit.mp4';

        $this->run([
            $this->ffmpeg, '-nostdin', '-y', '-hide_banner',
            '-i', $path,
            '-vf', sprintf(
                'tpad=stop_mode=clone:stop_duration=1,trim=duration=%.3f,fps=%d,setpts=PTS-STARTPTS,format=yuv420p',
                $duration,
                $this->fps,
            ),
            '-frames:v', (string) $frames,
            '-an',
            '-c:v', 'libx264',
            '-crf', (string) $this->intermediateCrf,
            '-preset', 'ultrafast',
            '-pix_fmt', 'yuv420p',
            $fitted,
        ]);

        $this->files->delete($path);
        $this->files->move($fitted, $path);
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
