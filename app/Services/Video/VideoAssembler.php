<?php

declare(strict_types=1);

namespace App\Services\Video;

use App\Exceptions\FfmpegException;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Process\Process;

final class VideoAssembler
{
    private const SYNC_TOLERANCE = 0.1;

    private readonly string $ffmpeg;

    private readonly string $ffprobe;

    private readonly int $nice;

    private readonly float $timeout;

    private readonly int $fps;

    private readonly float $sceneFadeDuration;

    private readonly int $intermediateCrf;

    private readonly float $outroSeconds;

    private readonly string $workRoot;

    public function __construct(
        private Filesystem $files,
        Repository $config,
    ) {
        $this->ffmpeg = (string) $config->get('stories.ffmpeg.binary');
        $this->ffprobe = (string) $config->get('stories.ffmpeg.ffprobe');
        $this->nice = (int) $config->get('stories.ffmpeg.nice');
        $this->timeout = (float) $config->get('stories.ffmpeg.timeout');
        $this->fps = (int) $config->get('stories.video.fps');
        $this->sceneFadeDuration = (float) $config->get('stories.video.scene_fade_duration');
        $this->intermediateCrf = (int) $config->get('stories.video.intermediate_crf');
        $this->outroSeconds = (float) $config->get('stories.video.outro_seconds');
        $this->workRoot = storage_path('app/'.$config->get('stories.video.work_path'));
    }

    /**
     * @param  list<string>  $sceneClips
     */
    public function assemble(array $sceneClips, float $targetDuration, string $outputPath): string
    {
        $paths = $this->assertClips($sceneClips);

        if ($targetDuration <= 0) {
            throw new InvalidArgumentException('La duración del mix de audio tiene que ser mayor que 0.');
        }

        $workDir = $this->makeWorkDirectory();

        try {
            $prepared = [];
            $durations = [];
            $last = count($paths) - 1;

            foreach ($paths as $index => $path) {
                $duration = $this->probeDuration($path);
                $durations[] = $duration;
                $prepared[] = $this->applySceneFades(
                    $path,
                    $workDir.DIRECTORY_SEPARATOR.'scene-'.($index + 1).'.mp4',
                    $duration,
                    fadeIn: $index > 0,
                    fadeOut: $index < $last,
                );
            }

            $bodyPath = $workDir.DIRECTORY_SEPARATOR.'body.mp4';
            $this->concat($prepared, $bodyPath);

            $this->assertSync($durations, $this->probeDuration($bodyPath), $targetDuration);

            $outroPath = $this->renderOutro(
                $prepared[$last],
                $workDir.DIRECTORY_SEPARATOR.'outro.mp4',
            );

            $this->files->ensureDirectoryExists(dirname($outputPath));
            $this->concat([$bodyPath, $outroPath], $outputPath);

            if (! $this->files->isFile($outputPath) || $this->files->size($outputPath) < 1) {
                throw new InvalidArgumentException('No se pudo escribir el vídeo mudo.');
            }

            return $outputPath;
        } finally {
            $this->files->deleteDirectory($workDir);
        }
    }

    /**
     * @param  list<string>  $sceneClips
     * @return list<string>
     */
    private function assertClips(array $sceneClips): array
    {
        if ($sceneClips === []) {
            throw new InvalidArgumentException('No hay clips de escena para ensamblar el vídeo.');
        }

        $paths = [];

        foreach ($sceneClips as $index => $clip) {
            $path = is_string($clip) ? trim($clip) : trim((string) (is_array($clip) ? ($clip['path'] ?? '') : ''));

            if ($path === '' || ! $this->files->isFile($path)) {
                throw new InvalidArgumentException("Falta el clip de la escena {$index}.");
            }

            $paths[] = $path;
        }

        return $paths;
    }

    private function applySceneFades(
        string $inputPath,
        string $outputPath,
        float $duration,
        bool $fadeIn,
        bool $fadeOut,
    ): string {
        $fade = $this->clampedFade($duration);
        $filters = [];

        if ($fadeIn) {
            $filters[] = 'fade=t=in:d='.$this->formatNumber($fade);
        }

        if ($fadeOut) {
            $start = max(0.0, round($duration - $fade, 3));
            $filters[] = 'fade=t=out:st='.$this->formatNumber($start).':d='.$this->formatNumber($fade);
        }

        $filters[] = 'setsar=1';

        $this->run([
            $this->ffmpeg, '-nostdin', '-y', '-hide_banner',
            '-i', $inputPath,
            '-vf', implode(',', $filters),
            ...$this->videoEncodeArguments(),
            $outputPath,
        ]);

        if (! $this->files->isFile($outputPath) || $this->files->size($outputPath) < 1) {
            throw new InvalidArgumentException('No se pudo escribir el clip de escena con fundido.');
        }

        return $outputPath;
    }

    private function renderOutro(string $lastClipPath, string $outputPath): string
    {
        $framePath = dirname($outputPath).DIRECTORY_SEPARATOR.'last-frame.png';

        $this->run([
            $this->ffmpeg, '-nostdin', '-y', '-hide_banner',
            '-sseof', '-0.1',
            '-i', $lastClipPath,
            '-frames:v', '1',
            $framePath,
        ]);

        if (! $this->files->isFile($framePath) || $this->files->size($framePath) < 1) {
            throw new InvalidArgumentException('No se pudo extraer el último fotograma para el outro.');
        }

        $frames = max(1, (int) round($this->outroSeconds * $this->fps));
        $fade = $this->formatNumber($this->outroSeconds);

        $this->run([
            $this->ffmpeg, '-nostdin', '-y', '-hide_banner',
            '-loop', '1',
            '-framerate', (string) $this->fps,
            '-i', $framePath,
            '-vf', "fade=t=out:st=0:d={$fade},setsar=1,format=yuv420p",
            '-frames:v', (string) $frames,
            ...$this->videoEncodeArguments(),
            $outputPath,
        ]);

        if (! $this->files->isFile($outputPath) || $this->files->size($outputPath) < 1) {
            throw new InvalidArgumentException('No se pudo escribir el outro.');
        }

        return $outputPath;
    }

    /**
     * @param  list<string>  $paths
     */
    private function concat(array $paths, string $outputPath): void
    {
        $listPath = dirname($outputPath).DIRECTORY_SEPARATOR.'concat-'.bin2hex(random_bytes(4)).'.txt';
        $lines = array_map(fn (string $path): string => $this->concatFileLine($path), $paths);
        $this->files->put($listPath, implode("\n", $lines)."\n");

        $this->run([
            $this->ffmpeg, '-nostdin', '-y', '-hide_banner',
            '-fflags', '+genpts',
            '-f', 'concat', '-safe', '0',
            '-i', $listPath,
            '-c', 'copy',
            $outputPath,
        ]);
    }

    /**
     * @param  list<float>  $sceneDurations
     */
    private function assertSync(array $sceneDurations, float $actual, float $target): void
    {
        $delta = round($actual - $target, 3);

        if (abs($delta) <= self::SYNC_TOLERANCE) {
            return;
        }

        $running = 0.0;
        $at = 1;

        foreach ($sceneDurations as $index => $duration) {
            $running = round($running + $duration, 3);
            $at = $index + 1;

            if ($delta > 0 && $running > $target + self::SYNC_TOLERANCE) {
                break;
            }
        }

        $breakdown = [];

        foreach ($sceneDurations as $index => $duration) {
            $breakdown[] = sprintf('%d=%.3fs', $index + 1, $duration);
        }

        throw new RuntimeException(sprintf(
            'El vídeo mudo dura %.3f s y el mix de audio %.3f s (desfase %+.3f s). El desfase se acumuló en la escena %d (acumulado %.3f s). Duraciones: %s. No se estira el vídeo: hay un bug aguas arriba.',
            $actual,
            $target,
            $delta,
            $at,
            $running,
            implode(', ', $breakdown),
        ));
    }

    /**
     * @return list<string>
     */
    private function videoEncodeArguments(): array
    {
        return [
            '-an',
            '-c:v', 'libx264',
            '-crf', (string) $this->intermediateCrf,
            '-preset', 'ultrafast',
            '-pix_fmt', 'yuv420p',
        ];
    }

    private function clampedFade(float $duration): float
    {
        $frame = 1 / max(1, $this->fps);

        return min($this->sceneFadeDuration, max($frame, $duration - $frame));
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

    private function concatFileLine(string $path): string
    {
        $absolute = realpath($path);

        if ($absolute === false) {
            throw new RuntimeException('No se encontró el clip '.$path.'.');
        }

        return "file '".str_replace("'", "'\\''", $absolute)."'";
    }

    private function makeWorkDirectory(): string
    {
        $directory = $this->workRoot.DIRECTORY_SEPARATOR.'assemble-'.bin2hex(random_bytes(8));
        $this->files->ensureDirectoryExists($directory);

        return $directory;
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
