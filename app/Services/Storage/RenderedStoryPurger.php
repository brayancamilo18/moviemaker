<?php

declare(strict_types=1);

namespace App\Services\Storage;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use InvalidArgumentException;

/**
 * Borra los artefactos que dejan de hacer falta cuando el MP4 de una historia ya existe.
 *
 * Son casi 180 MB por historia, y casi todo son los dos WAV. No los borra TempSweeper porque ese
 * reserva a propósito el árbol de las historias y lanza si un bucket apunta ahí: esa guarda protege
 * el producto y no se toca. Este servicio es el único que entra en storage/app/stories/{slug}/, y
 * entra con una lista blanca de nombres y una lista negra que ninguna configuración puede saltarse.
 */
final class RenderedStoryPurger
{
    /**
     * Nunca se borran, diga lo que diga la configuración. El MP4 es el producto; el resto es lo que
     * queda para saber qué se hizo cuando el audio ya no está.
     *
     * @var list<string>
     */
    private const PROTECTED = [
        'video.mp4',
        'video-nograde.mp4',
        'subtitles.srt',
        'credits.txt',
        'timings.json',
        'shots.json',
        'sounds.json',
    ];

    private readonly string $outputDirectory;

    private readonly bool $enabled;

    /** @var list<string> */
    private readonly array $artifacts;

    /** @var list<string> */
    private readonly array $patterns;

    public function __construct(
        private Filesystem $files,
        Repository $config,
    ) {
        $this->outputDirectory = $this->normalize(storage_path('app/'.$config->get('stories.output_path')));
        $this->enabled = (bool) $config->get('stories.purge.enabled', false);
        $this->artifacts = $this->names((array) $config->get('stories.purge.artifacts', []));
        $this->patterns = $this->names((array) $config->get('stories.purge.patterns', []));
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    /**
     * @return array{files: int, bytes: int}
     */
    public function purge(string $slug): array
    {
        $directory = $this->storyDirectory($slug);
        $files = 0;
        $bytes = 0;

        foreach ($this->targets($directory) as $path) {
            if (! $this->files->isFile($path)) {
                continue;
            }

            $bytes += $this->files->size($path);
            $this->files->delete($path);
            $files++;
        }

        return ['files' => $files, 'bytes' => $bytes];
    }

    /**
     * @return list<string>
     */
    private function targets(string $directory): array
    {
        $targets = [];

        foreach ($this->artifacts as $name) {
            $targets[] = $this->assertDeletable($directory.DIRECTORY_SEPARATOR.$name, $directory);
        }

        foreach ($this->patterns as $pattern) {
            foreach ($this->files->glob($directory.DIRECTORY_SEPARATOR.$pattern) ?: [] as $path) {
                $targets[] = $this->assertDeletable($path, $directory);
            }
        }

        return array_values(array_unique($targets));
    }

    /**
     * Un fichero solo se borra si cuelga directamente del directorio de la historia y no está en la
     * lista negra. Con esto, un patrón mal escrito en la configuración falla en vez de llevarse el
     * vídeo por delante.
     */
    private function assertDeletable(string $path, string $directory): string
    {
        $normalized = $this->normalize($path);

        if (dirname($normalized) !== $directory) {
            throw new InvalidArgumentException(
                'La depuración solo puede tocar ficheros de '.$directory.'; llegó '.$path.'.',
            );
        }

        if (in_array(basename($normalized), self::PROTECTED, true)) {
            throw new InvalidArgumentException(
                'La depuración no puede borrar '.basename($normalized).'.',
            );
        }

        return $normalized;
    }

    private function storyDirectory(string $slug): string
    {
        $slug = trim($slug);

        if ($slug === '' || ! preg_match('/^[a-z0-9-]+$/', $slug)) {
            throw new InvalidArgumentException('El slug «'.$slug.'» no sirve para depurar artefactos.');
        }

        return $this->outputDirectory.DIRECTORY_SEPARATOR.$slug;
    }

    /**
     * Nombres sueltos: nada con separador de rutas ni con «..». Así ninguna entrada de configuración
     * puede apuntar fuera del directorio de la historia.
     *
     * @param  array<array-key, mixed>  $configured
     * @return list<string>
     */
    private function names(array $configured): array
    {
        $names = [];

        foreach ($configured as $value) {
            $name = trim((string) $value);

            if ($name === '') {
                continue;
            }

            if (str_contains($name, '/') || str_contains($name, DIRECTORY_SEPARATOR) || str_contains($name, '..')) {
                throw new InvalidArgumentException(
                    'La depuración solo acepta nombres de fichero; llegó «'.$name.'».',
                );
            }

            $names[] = $name;
        }

        return $names;
    }

    private function normalize(string $path): string
    {
        return rtrim(str_replace('/', DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);
    }
}
