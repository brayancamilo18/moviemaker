<?php

declare(strict_types=1);

namespace App\Services\Ffmpeg;

use App\Exceptions\FfmpegException;
use Illuminate\Contracts\Config\Repository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;

/**
 * Única puerta de salida hacia el binario de ffmpeg: binario, prioridad y timeout salen de la
 * configuración, nunca del sitio que llama.
 */
final class FfmpegRunner
{
    private readonly string $ffmpeg;

    private readonly int $nice;

    private readonly float $timeout;

    public function __construct(
        private LoggerInterface $logger,
        Repository $config,
    ) {
        $this->ffmpeg = (string) $config->get('stories.ffmpeg.binary');
        $this->nice = (int) $config->get('stories.ffmpeg.nice');
        $this->timeout = (float) $config->get('stories.ffmpeg.timeout');
    }

    /**
     * @param  list<string>  $arguments  Argumentos de ffmpeg, sin el binario ni el prefijo nice.
     *
     * @throws FfmpegException
     */
    public function run(array $arguments): void
    {
        $process = new Process(['nice', '-n', (string) $this->nice, $this->ffmpeg, ...$arguments]);
        $process->setTimeout($this->timeout);

        $started = hrtime(true);
        $process->run();
        $elapsed = (hrtime(true) - $started) / 1e9;

        if (! $process->isSuccessful()) {
            $this->logger->error('ffmpeg falló.', [
                'command' => $process->getCommandLine(),
                'exitCode' => $process->getExitCode(),
                'stderr' => $process->getErrorOutput(),
            ]);

            throw FfmpegException::fromProcess($process);
        }

        $this->logger->debug('ffmpeg terminó.', [
            'command' => $process->getCommandLine(),
            'seconds' => round($elapsed, 3),
        ]);
    }

    /**
     * Los números que viajan dentro de un filtro se escriben sin ceros de cola y sin notación
     * científica, que el parser de filtros no acepta.
     */
    public function formatNumber(float $value): string
    {
        $formatted = rtrim(rtrim(sprintf('%.4f', $value), '0'), '.');

        return $formatted === '' || $formatted === '-' ? '0' : $formatted;
    }
}
