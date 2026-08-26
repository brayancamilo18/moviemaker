<?php

declare(strict_types=1);

namespace App\Services\Audio;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Fuente única de verdad de la línea de tiempo del vídeo.
 * La autoridad es el WAV de narración, nunca los timestamps de whisper.
 */
final class NarrationClock
{
    private readonly string $ffprobe;

    public function __construct(
        private Filesystem $files,
        Repository $config,
    ) {
        $this->ffprobe = (string) $config->get('stories.ffmpeg.ffprobe', 'ffprobe');
    }

    public function narrationEnd(string $narrationWavPath): float
    {
        if ($narrationWavPath === '' || ! $this->files->isFile($narrationWavPath)) {
            throw new InvalidArgumentException('No existe el WAV de narración: '.$narrationWavPath);
        }

        $process = new Process([
            $this->ffprobe, '-v', 'error',
            '-show_entries', 'format=duration',
            '-of', 'default=noprint_wrappers=1:nokey=1',
            $narrationWavPath,
        ]);
        $process->setTimeout(null);
        $process->setIdleTimeout(60);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException('ffprobe falló sobre '.$narrationWavPath.': '.$process->getErrorOutput());
        }

        $duration = (float) trim($process->getOutput());

        if ($duration <= 0) {
            throw new RuntimeException('Duración inválida en '.$narrationWavPath);
        }

        return round($duration, 3);
    }

    public function masterDuration(string $narrationWavPath, float $tailSeconds): float
    {
        return round($this->narrationEnd($narrationWavPath) + max(0.0, $tailSeconds), 3);
    }
}
