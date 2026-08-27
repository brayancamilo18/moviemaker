<?php

declare(strict_types=1);

namespace App\Services\Storage;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use InvalidArgumentException;

/**
 * Barre los intermedios que ningún bloque finally llegó a borrar: un Ctrl-C, un OOM o un SIGKILL
 * dejan camas de ambiente y directorios de ensamblado de varios GB tirados para siempre.
 */
final class TempSweeper
{
    private readonly string $root;

    private readonly string $tmpRoot;

    private readonly int $maxAgeSeconds;

    /**
     * Patrones relativos a storage/app. Lista explícita: nunca un glob sobre todo tmp.
     *
     * @var list<string>
     */
    private readonly array $buckets;

    /**
     * Rutas que el barrido no puede tocar aunque un bucket apunte a ellas.
     *
     * @var list<string>
     */
    private readonly array $reserved;

    public function __construct(
        private Filesystem $files,
        Repository $config,
    ) {
        $this->root = $this->normalize(storage_path('app'));
        $this->tmpRoot = $this->root.DIRECTORY_SEPARATOR.'tmp';
        $this->maxAgeSeconds = (int) $config->get('stories.temp.max_age_seconds', 86400);

        $buckets = [];

        /** @var list<mixed> $configured */
        $configured = (array) $config->get('stories.temp.buckets', []);

        foreach ($configured as $bucket) {
            $bucket = trim((string) $bucket);

            if ($bucket !== '') {
                $buckets[] = $bucket;
            }
        }

        $this->buckets = $buckets;
        $this->reserved = [
            $this->absolute((string) $config->get('stories.output_path', 'stories'), $this->root),
            $this->absolute((string) $config->get('stories.audio.library_path', 'audio'), resource_path()),
            dirname($this->absolute(
                (string) $config->get('stories.audio.local_index_path', 'audio/library.json'),
                $this->root,
            )),
        ];
    }

    /**
     * @return array{entries: int, bytes: int}
     */
    public function sweep(): array
    {
        $entries = 0;
        $bytes = 0;
        $now = time();

        foreach ($this->buckets as $bucket) {
            foreach ($this->candidates($bucket) as $path) {
                if ($now - $this->modifiedAt($path) <= $this->maxAgeSeconds) {
                    continue;
                }

                $bytes += $this->weigh($path);
                $this->remove($path);
                $entries++;
            }
        }

        return ['entries' => $entries, 'bytes' => $bytes];
    }

    /**
     * Borra un intermedio concreto. Devuelve false en lugar de lanzar cuando la ruta no vive bajo
     * storage/app/tmp: esto corre en bloques finally y no puede tapar la excepción de verdad.
     */
    public function discard(string $path): bool
    {
        $path = trim($path);

        if ($path === '') {
            return false;
        }

        $normalized = $this->normalize($this->absolute($path, $this->root));

        if (! str_starts_with($normalized, $this->tmpRoot.DIRECTORY_SEPARATOR)) {
            return false;
        }

        if (! $this->files->isFile($normalized)) {
            return false;
        }

        return $this->files->delete($normalized);
    }

    /**
     * @return list<string>
     */
    private function candidates(string $bucket): array
    {
        $pattern = $this->assertSweepable($this->absolute($bucket, $this->root));
        $paths = [];

        foreach ($this->files->glob($pattern) ?: [] as $path) {
            $paths[] = $this->assertSweepable($path);
        }

        return $paths;
    }

    /**
     * Valida antes de borrar nada: un bucket mal escrito no puede acabar en un rm -rf sobre el
     * árbol de las historias ni sobre la librería de audio.
     */
    private function assertSweepable(string $path): string
    {
        $normalized = $this->normalize($path);

        if (! str_starts_with($normalized, $this->root.DIRECTORY_SEPARATOR)) {
            throw new InvalidArgumentException(
                'El barrido solo puede tocar rutas dentro de '.$this->root.'; llegó '.$path.'.',
            );
        }

        foreach ($this->reserved as $reserved) {
            if ($normalized === $reserved || str_starts_with($normalized, $reserved.DIRECTORY_SEPARATOR)) {
                throw new InvalidArgumentException(
                    'El barrido no puede tocar '.$reserved.'; llegó '.$path.'.',
                );
            }
        }

        return $normalized;
    }

    private function remove(string $path): void
    {
        if ($this->files->isDirectory($path)) {
            $this->files->deleteDirectory($path);

            return;
        }

        $this->files->delete($path);
    }

    private function weigh(string $path): int
    {
        if ($this->files->isFile($path)) {
            return $this->files->size($path);
        }

        if (! $this->files->isDirectory($path)) {
            return 0;
        }

        $bytes = 0;

        foreach ($this->files->allFiles($path) as $file) {
            $bytes += (int) $file->getSize();
        }

        return $bytes;
    }

    private function modifiedAt(string $path): int
    {
        if (! $this->files->exists($path)) {
            return 0;
        }

        return $this->files->lastModified($path);
    }

    private function absolute(string $value, string $base): string
    {
        $value = str_replace('/', DIRECTORY_SEPARATOR, trim($value));

        if (str_starts_with($value, DIRECTORY_SEPARATOR)) {
            return rtrim($value, DIRECTORY_SEPARATOR);
        }

        return rtrim($base, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$value;
    }

    /**
     * Resolución léxica: el path puede no existir todavía (un patrón con comodines nunca existe),
     * así que realpath() no sirve para decidir si cae dentro de storage/app.
     */
    private function normalize(string $absolute): string
    {
        $segments = [];

        foreach (explode(DIRECTORY_SEPARATOR, str_replace('/', DIRECTORY_SEPARATOR, $absolute)) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($segments);

                continue;
            }

            $segments[] = $segment;
        }

        return DIRECTORY_SEPARATOR.implode(DIRECTORY_SEPARATOR, $segments);
    }
}
