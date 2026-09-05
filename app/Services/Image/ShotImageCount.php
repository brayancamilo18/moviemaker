<?php

declare(strict_types=1);

namespace App\Services\Image;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Filesystem\Filesystem;
use InvalidArgumentException;
use JsonException;

/**
 * Cuántos planos de una historia tienen ya su imagen en disco.
 *
 * PipelineProgress solo sabe de un paso que esté corriendo por la cola: si el paso lo lanzó
 * un comando suelto, o si terminó, o si el worker murió a mitad, la caché no dice nada y la
 * pantalla se queda sin cifra. shots.json sí lo sabe siempre, porque es donde se escribe cada
 * imagePath conforme se genera. Es la misma cuenta que hace scripts/story-status.sh.
 *
 * La cuenta se memoriza contra el mtime y el tamaño del fichero: un sondeo cada dos segundos
 * no puede volver a decodificar 300 KB de JSON y hacer un stat por cada uno de ciento y pico
 * planos. El tamaño acompaña al mtime porque este solo tiene resolución de un segundo, y
 * cada imagen nueva añade una ruta al JSON, así que el par siempre cambia.
 */
final class ShotImageCount
{
    private const CACHE_TTL = 300;

    public function __construct(
        private Filesystem $files,
        private Cache $cache,
        private ShotPlanRepository $plans,
    ) {}

    /**
     * @return array{done: int, total: int}|null null si la historia aún no tiene plan de planos.
     */
    public function get(string $slug): ?array
    {
        try {
            $path = $this->plans->pathFor($slug);
        } catch (InvalidArgumentException) {
            return null;
        }

        if (! $this->files->isFile($path)) {
            return null;
        }

        $stamp = $this->files->lastModified($path).':'.$this->files->size($path);
        $key = 'shot-images:'.$slug.':'.$stamp;
        $cached = $this->cache->get($key);

        if (is_array($cached) && isset($cached['done'], $cached['total'])) {
            /** @var array{done: int, total: int} $cached */
            return $cached;
        }

        $counted = $this->count($path);

        if ($counted !== null) {
            $this->cache->put($key, $counted, self::CACHE_TTL);
        }

        return $counted;
    }

    /**
     * @return array{done: int, total: int}|null
     */
    private function count(string $path): ?array
    {
        try {
            $decoded = json_decode($this->files->get($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! is_array($decoded) || ! is_array($decoded['shots'] ?? null)) {
            return null;
        }

        $done = 0;
        $total = 0;

        foreach ($decoded['shots'] as $shot) {
            if (! is_array($shot)) {
                continue;
            }

            $total++;
            $image = $shot['imagePath'] ?? null;

            // Un imagePath escrito no basta: story:prune borra las imágenes cuando ya hay
            // vídeo y deja las rutas apuntando a nada. Lo que cuenta es el fichero.
            if (is_string($image) && $image !== '' && $this->files->isFile($image)) {
                $done++;
            }
        }

        return $total === 0 ? null : ['done' => $done, 'total' => $total];
    }
}
