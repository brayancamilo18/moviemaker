<?php

declare(strict_types=1);

namespace App\Services\Video;

use App\Services\Ffmpeg\FfmpegRunner;
use App\Services\Ffmpeg\MediaProbe;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use InvalidArgumentException;
use RuntimeException;

final class VideoAssembler
{
    private readonly int $fps;

    private readonly float $sceneFadeDuration;

    private readonly int $intermediateCrf;

    private readonly float $outroSeconds;

    private readonly float $syncTolerance;

    public function __construct(
        private Filesystem $files,
        private FfmpegRunner $ffmpeg,
        private MediaProbe $probe,
        Repository $config,
    ) {
        $this->fps = (int) $config->get('stories.video.fps');
        $this->sceneFadeDuration = (float) $config->get('stories.video.scene_fade_duration');
        $this->intermediateCrf = (int) $config->get('stories.video.intermediate_crf');
        $this->outroSeconds = (float) $config->get('stories.video.outro_seconds');
        $this->syncTolerance = (float) $config->get('stories.video.sync_tolerance');
    }

    /**
     * @param  list<string>  $sceneClips
     */
    public function assemble(
        array $sceneClips,
        float $targetDuration,
        string $outputPath,
        bool $keepIntermediates = false,
    ): string {
        $paths = $this->assertClips($sceneClips);

        if ($targetDuration <= 0) {
            throw new InvalidArgumentException('La duración del mix de audio tiene que ser mayor que 0.');
        }

        $workDir = $this->makeWorkDirectory($outputPath);

        try {
            $prepared = [];
            $durations = [];
            $last = count($paths) - 1;

            foreach ($paths as $index => $path) {
                $duration = $this->probe->duration($path);
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

            $this->assertSync($durations, $this->probe->duration($bodyPath), $targetDuration);

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
            if (! $keepIntermediates) {
                $this->files->deleteDirectory($workDir);
            }
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
            $filters[] = 'fade=t=in:d='.$this->ffmpeg->formatNumber($fade);
        }

        if ($fadeOut) {
            $start = max(0.0, round($duration - $fade, 3));
            $filters[] = 'fade=t=out:st='.$this->ffmpeg->formatNumber($start).':d='.$this->ffmpeg->formatNumber($fade);
        }

        $filters[] = 'setsar=1';

        $this->ffmpeg->run([
            '-nostdin', '-y', '-hide_banner',
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

        $this->ffmpeg->run([
            '-nostdin', '-y', '-hide_banner',
            '-sseof', '-0.1',
            '-i', $lastClipPath,
            '-frames:v', '1',
            $framePath,
        ]);

        if (! $this->files->isFile($framePath) || $this->files->size($framePath) < 1) {
            throw new InvalidArgumentException('No se pudo extraer el último fotograma para el outro.');
        }

        $frames = max(1, (int) round($this->outroSeconds * $this->fps));
        $fade = $this->ffmpeg->formatNumber($this->outroSeconds);

        $this->ffmpeg->run([
            '-nostdin', '-y', '-hide_banner',
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

        $this->ffmpeg->run([
            '-nostdin', '-y', '-hide_banner',
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

        if (abs($delta) <= $this->syncTolerance) {
            return;
        }

        $running = 0.0;
        $at = 1;

        foreach ($sceneDurations as $index => $duration) {
            $running = round($running + $duration, 3);
            $at = $index + 1;

            if ($delta > 0 && $running > $target + $this->syncTolerance) {
                break;
            }
        }

        $breakdown = [];

        foreach ($sceneDurations as $index => $duration) {
            $breakdown[] = sprintf('%d=%.3fs', $index + 1, $duration);
        }

        throw new RuntimeException(sprintf(
            'El vídeo mudo dura %.3f s y el mix de audio %.3f s (desfase %+.3f s, tolerancia %.3f s). El desfase se acumuló en la escena %d (acumulado %.3f s). Duraciones: %s. No se estira el vídeo: hay un bug aguas arriba. Rehaz el vídeo mudo con: php artisan story:render {file} --from=assemble',
            $actual,
            $target,
            $delta,
            $this->syncTolerance,
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

    private function concatFileLine(string $path): string
    {
        $absolute = realpath($path);

        if ($absolute === false) {
            throw new RuntimeException('No se encontró el clip '.$path.'.');
        }

        return "file '".str_replace("'", "'\\''", $absolute)."'";
    }

    /**
     * Cuelga del árbol del slug, junto al vídeo mudo: fuera de él, la limpieza del comando nunca lo
     * alcanza y cada huérfano son varios GB.
     */
    private function makeWorkDirectory(string $outputPath): string
    {
        $directory = dirname($outputPath).DIRECTORY_SEPARATOR.'assemble';
        $this->files->ensureDirectoryExists($directory);

        return $directory;
    }
}
