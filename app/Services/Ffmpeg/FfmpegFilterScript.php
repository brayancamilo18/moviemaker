<?php

declare(strict_types=1);

namespace App\Services\Ffmpeg;

use Illuminate\Contracts\Config\Repository;
use Symfony\Component\Process\Process;

/**
 * Decide con qué opción se le pasa a ffmpeg un filter_complex guardado en un fichero.
 *
 * ffmpeg 8 eliminó -filter_complex_script. Su sustituto, -/filter_complex, usa la sintaxis
 * genérica "el valor de esta opción se lee del fichero indicado" y solo existe desde ffmpeg 7.0,
 * así que la opción no se puede fijar en el código: se elige según el binario de la máquina.
 */
final class FfmpegFilterScript
{
    public const OPTION_MODERN = '-/filter_complex';

    public const OPTION_LEGACY = '-filter_complex_script';

    /**
     * Primera versión de ffmpeg que acepta el prefijo "-/" para leer el valor de una opción de un fichero.
     */
    private const FIRST_VERSION_WITH_FILE_OPTIONS = 7;

    /**
     * Tope para las sondas de detección. No es el tiempo de un render: si un `ffmpeg -version`
     * no ha contestado en este plazo, el binario está roto.
     */
    private const PROBE_TIMEOUT = 30.0;

    private readonly string $ffmpeg;

    private ?string $option = null;

    public function __construct(Repository $config)
    {
        $this->ffmpeg = (string) $config->get('stories.ffmpeg.binary');
    }

    /**
     * El par de argumentos que carga el filtro desde $scriptPath.
     *
     * @return array{0: string, 1: string}
     */
    public function arguments(string $scriptPath): array
    {
        return [$this->option(), $scriptPath];
    }

    public function option(): string
    {
        return $this->option ??= $this->detect();
    }

    /**
     * Elige la opción sin ejecutar nada. La ayuda solo se consulta cuando no hay número de versión.
     */
    public static function optionFor(?int $majorVersion, string $helpOutput): string
    {
        if ($majorVersion !== null) {
            return $majorVersion >= self::FIRST_VERSION_WITH_FILE_OPTIONS
                ? self::OPTION_MODERN
                : self::OPTION_LEGACY;
        }

        return str_contains($helpOutput, 'filter_complex_script')
            ? self::OPTION_LEGACY
            : self::OPTION_MODERN;
    }

    /**
     * El major de la primera línea de `ffmpeg -version`, o null en las builds de git (N-120000-g…).
     */
    public static function majorVersion(string $versionOutput): ?int
    {
        if (preg_match('/^ffmpeg version (\d+)\./m', $versionOutput, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }

    private function detect(): string
    {
        $major = self::majorVersion($this->capture([$this->ffmpeg, '-hide_banner', '-version']));

        return self::optionFor(
            $major,
            $major === null ? $this->capture([$this->ffmpeg, '-hide_banner', '-h', 'full']) : '',
        );
    }

    /**
     * Un binario ausente o roto devuelve cadena vacía: la detección nunca corta el pipeline,
     * el fallo lo da después la llamada real a ffmpeg con su propio mensaje.
     *
     * @param  list<string>  $arguments
     */
    private function capture(array $arguments): string
    {
        $process = new Process($arguments);
        $process->setTimeout(self::PROBE_TIMEOUT);
        $process->run();

        return $process->getOutput().$process->getErrorOutput();
    }
}
