<?php

declare(strict_types=1);

namespace App\Services\Image;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Filesystem\Filesystem;
use InvalidArgumentException;
use JsonException;

/**
 * Lee shots.json y lo convierte en la rejilla de planos que se revisa de un vistazo.
 *
 * Las imágenes viven en una caché global, nombradas por el hash de su prompt, y shots.json
 * guarda su ruta absoluta. Esa ruta viene de un fichero en disco, así que nunca se sirve tal
 * cual: se resuelve y se comprueba que cae dentro de los dos únicos directorios que pueden
 * contener imágenes de una historia. Sin esa comprobación, cualquiera que pudiera escribir un
 * shots.json podría leer cualquier fichero de la máquina a través de la ruta de imágenes.
 */
final class ContactSheet
{
    private readonly string $storiesDirectory;

    private readonly string $cacheDirectory;

    private readonly float $threatMin;

    private readonly float $threatMax;

    private readonly float $detailMax;

    /**
     * Plan ya decodificado, por slug. Una hoja de contactos pregunta por ciento y pico planos
     * y cada pregunta abriría el mismo JSON de 300 KB: sin esto, pintar la rejilla cuesta
     * ciento y pico decodificaciones del mismo fichero.
     *
     * @var array<string, list<array<string, mixed>>|null>
     */
    private array $decoded = [];

    public function __construct(
        private Filesystem $files,
        private ShotPlanRepository $plans,
        Config $config,
    ) {
        $this->storiesDirectory = storage_path('app/'.$config->get('stories.output_path'));
        $this->cacheDirectory = storage_path('app/'.$config->get('stories.images.cache_path', 'image-cache'));
        $this->threatMin = (float) $config->get('stories.images.direction.threat_ratio_min');
        $this->threatMax = (float) $config->get('stories.images.direction.threat_ratio_max');
        $this->detailMax = (float) $config->get('stories.images.direction.detail_ratio_max');
    }

    /**
     * @return list<array<string, mixed>>|null null cuando la historia todavía no tiene plan.
     */
    public function shots(string $slug): ?array
    {
        $plan = $this->readPlan($slug);

        if ($plan === null) {
            return null;
        }

        $total = count($plan);
        $rows = [];

        foreach ($plan as $index => $shot) {
            $start = (float) ($shot['start'] ?? 0);
            $end = (float) ($shot['end'] ?? 0);

            $rows[] = [
                'order' => (int) ($shot['order'] ?? $index + 1),
                'sceneOrder' => (int) ($shot['sceneOrder'] ?? 0),
                'start' => round($start, 2),
                'seconds' => round(max(0.0, $end - $start), 1),
                'subject' => $this->text($shot['subject'] ?? null),
                'framing' => $this->text($shot['framing'] ?? null),
                'motion' => $this->text($shot['motion'] ?? null),
                'threatStage' => $this->text($shot['threatStage'] ?? null),
                'lightStage' => $this->text($shot['lightStage'] ?? null),
                'journeyLeg' => $this->text($shot['journeyLeg'] ?? null),
                'line' => $this->text($shot['sourceText'] ?? null),
                'description' => $this->text($shot['description'] ?? null),
                'prompt' => $this->text($shot['prompt'] ?? null),
                'isIntro' => (bool) ($shot['isIntro'] ?? false),
                'isOutro' => (bool) ($shot['isOutro'] ?? false),
                'placeholder' => (bool) ($shot['placeholder'] ?? false),
                'hasImage' => is_string($shot['imagePath'] ?? null)
                    && $this->insideAllowedRoot($shot['imagePath']) !== null,
                // Fracción de historia en la que cae el plano: es lo que gobierna hasta qué
                // etapa de amenaza tenía permiso de llegar el director.
                'progress' => $total < 2 ? 0.0 : round($index / ($total - 1), 4),
            ];
        }

        return $rows;
    }

    /**
     * Ruta en disco de la imagen de un plano, o null si no la hay.
     *
     * Devuelve null también cuando shots.json apunta fuera de los directorios permitidos: una
     * ruta así no es un fallo que haya que contar, es una que no se piensa abrir.
     */
    public function imagePath(string $slug, int $order): ?string
    {
        $plan = $this->readPlan($slug);

        if ($plan === null) {
            return null;
        }

        foreach ($plan as $shot) {
            if ((int) ($shot['order'] ?? 0) !== $order) {
                continue;
            }

            $candidate = $shot['imagePath'] ?? null;

            if (! is_string($candidate) || $candidate === '') {
                return null;
            }

            return $this->insideAllowedRoot($candidate);
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function stats(string $slug): ?array
    {
        $shots = $this->shots($slug);

        if ($shots === null || $shots === []) {
            return null;
        }

        $total = count($shots);
        $counts = ['threat' => 0, 'detail' => 0, 'environment' => 0];

        foreach ($shots as $shot) {
            $subject = $shot['subject'];

            if ($subject !== null && array_key_exists($subject, $counts)) {
                $counts[$subject]++;
            }
        }

        return [
            'total' => $total,
            'withImage' => count(array_filter($shots, static fn (array $shot): bool => $shot['hasImage'])),
            'figure' => [
                'ratio' => round($counts['threat'] / $total, 4),
                'min' => $this->threatMin,
                'max' => $this->threatMax,
            ],
            'detail' => [
                'ratio' => round($counts['detail'] / $total, 4),
                'max' => $this->detailMax,
            ],
            'threat' => $this->threatProgression($shots),
        ];
    }

    /**
     * Dónde aparece por primera vez cada etapa de la amenaza, contra el punto de la historia a
     * partir del cual el director la tenía permitida.
     *
     * Adelantarse sí es una infracción: la regla del director es no usar nunca una etapa más
     * avanzada de la que permite el progreso, así que un reveal antes de su puerta quema la
     * escalada entera. Quedarse corto —que una etapa no llegue a salir— no lo es, y por eso
     * una etapa ausente no se marca.
     *
     * @param  list<array<string, mixed>>  $shots
     * @return list<array<string, mixed>>
     */
    private function threatProgression(array $shots): array
    {
        $stages = [];

        foreach (ShotDirector::THREAT_GATES as $stage => $gate) {
            $first = null;

            foreach ($shots as $shot) {
                if ($shot['threatStage'] === $stage) {
                    $first = $shot;

                    break;
                }
            }

            $stages[] = [
                'stage' => $stage,
                'gate' => $gate,
                'firstOrder' => $first === null ? null : $first['order'],
                'firstProgress' => $first === null ? null : $first['progress'],
                'early' => $first !== null && $first['progress'] < $gate,
            ];
        }

        return $stages;
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    private function readPlan(string $slug): ?array
    {
        if (array_key_exists($slug, $this->decoded)) {
            return $this->decoded[$slug];
        }

        return $this->decoded[$slug] = $this->loadPlan($slug);
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    private function loadPlan(string $slug): ?array
    {
        try {
            $path = $this->plans->pathFor($slug);
        } catch (InvalidArgumentException) {
            return null;
        }

        if (! $this->files->isFile($path)) {
            return null;
        }

        try {
            $decoded = json_decode($this->files->get($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! is_array($decoded) || ! is_array($decoded['shots'] ?? null) || $decoded['shots'] === []) {
            return null;
        }

        return array_values(array_filter(
            $decoded['shots'],
            static fn (mixed $shot): bool => is_array($shot),
        ));
    }

    /**
     * La caché de imágenes y el directorio de la historia son los dos únicos sitios de los que
     * se sirve un plano. Se compara sobre la ruta ya resuelta, así que ni un enlace simbólico
     * ni un ../ dentro del JSON llevan fuera.
     */
    private function insideAllowedRoot(string $candidate): ?string
    {
        $real = realpath($candidate);

        if ($real === false || ! is_file($real)) {
            return null;
        }

        foreach ([$this->cacheDirectory, $this->storiesDirectory] as $root) {
            $realRoot = realpath($root);

            if ($realRoot !== false && str_starts_with($real, $realRoot.DIRECTORY_SEPARATOR)) {
                return $real;
            }
        }

        return null;
    }

    private function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
