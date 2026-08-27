<?php

declare(strict_types=1);

namespace App\Services\Ffmpeg;

use App\Exceptions\FfmpegException;
use Illuminate\Contracts\Config\Repository;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * La duración de un fichero de medios según ffprobe, en segundos con 3 decimales.
 */
final class MediaProbe
{
    private readonly string $ffprobe;

    private readonly float $timeout;

    public function __construct(Repository $config)
    {
        $this->ffprobe = (string) $config->get('stories.ffmpeg.ffprobe');
        $this->timeout = (float) $config->get('stories.ffmpeg.timeout');
    }

    /**
     * @throws FfmpegException si ffprobe falla
     * @throws RuntimeException si el fichero no declara una duración utilizable
     */
    public function duration(string $path): float
    {
        $process = $this->probe($path);

        if (! $process->isSuccessful()) {
            throw FfmpegException::fromProcess($process);
        }

        $duration = $this->parse($process->getOutput());

        if ($duration === null) {
            throw new RuntimeException('ffprobe no pudo leer la duración de '.$path.'.');
        }

        return $duration;
    }

    /**
     * La duración, o null si el fichero no se pudo sondear. Para decidir si un artefacto cacheado
     * sirve: ahí un fichero ilegible no es un error, es un "hay que rehacerlo".
     */
    public function tryDuration(string $path): ?float
    {
        $process = $this->probe($path);

        if (! $process->isSuccessful()) {
            return null;
        }

        return $this->parse($process->getOutput());
    }

    private function probe(string $path): Process
    {
        $process = new Process([
            $this->ffprobe, '-v', 'error',
            '-show_entries', 'format=duration',
            '-of', 'csv=p=0',
            $path,
        ]);
        $process->setTimeout($this->timeout);
        $process->run();

        return $process;
    }

    private function parse(string $output): ?float
    {
        $duration = (float) trim($output);

        return $duration > 0 ? round($duration, 3) : null;
    }
}
