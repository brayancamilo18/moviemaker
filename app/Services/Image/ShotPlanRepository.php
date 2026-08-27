<?php

declare(strict_types=1);

namespace App\Services\Image;

use App\DataObjects\ShotPlan;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

/**
 * Único lector y escritor de storage/app/stories/{slug}/shots.json.
 */
final class ShotPlanRepository
{
    private readonly string $storiesDirectory;

    public function __construct(
        private Filesystem $files,
        Repository $config,
    ) {
        $this->storiesDirectory = storage_path('app/'.$config->get('stories.output_path'));
    }

    public function pathFor(string $slug): string
    {
        $slug = trim($slug);

        if ($slug === '' || basename($slug) !== $slug) {
            throw new InvalidArgumentException('El slug de la historia no es válido.');
        }

        return $this->storiesDirectory.DIRECTORY_SEPARATOR.$slug.DIRECTORY_SEPARATOR.'shots.json';
    }

    /**
     * Devuelve null si no hay plan legible: no hay fichero, no es JSON o no trae planos.
     */
    public function read(string $slug): ?ShotPlan
    {
        $path = $this->pathFor($slug);

        if (! $this->files->isFile($path)) {
            return null;
        }

        try {
            $decoded = json_decode($this->files->get($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! is_array($decoded) || ! is_array($decoded['shots'] ?? null)) {
            return null;
        }

        /** @var array{version?: int, plannerVersion?: int, shots?: list<array<string, mixed>>} $decoded */
        $plan = ShotPlan::fromArray($decoded);

        return $plan->shots === [] ? null : $plan;
    }

    /**
     * Escribe a un temporal y renombra: una ejecución interrumpida nunca deja un shots.json a medias.
     */
    public function write(string $slug, ShotPlan $plan): void
    {
        $path = $this->pathFor($slug);
        $this->files->ensureDirectoryExists(dirname($path));

        $json = json_encode($plan->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        if ($json === false) {
            throw new RuntimeException('No se pudo serializar shots.json.');
        }

        $temporary = $path.'.'.bin2hex(random_bytes(6)).'.tmp';
        $this->files->put($temporary, $json."\n");

        if (! $this->files->move($temporary, $path)) {
            $this->files->delete($temporary);

            throw new RuntimeException("No se pudo escribir {$path}.");
        }
    }
}
