<?php

declare(strict_types=1);

namespace App\Services\Audio;

use App\DataObjects\CoverageReport;
use App\DataObjects\ResolvedSound;
use App\DataObjects\SceneSoundEffect;
use App\DataObjects\Story;
use Illuminate\Filesystem\Filesystem;
use JsonException;
use Throwable;

final class CoverageAuditor
{
    private const TOLERANCE_SECONDS = 0.05;

    public function __construct(
        private StorySoundManifest $manifest,
        private LibraryClipProcessor $processor,
        private Filesystem $files,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $resolved
     */
    public function audit(Story $story, array $resolved, string $narrationPath): CoverageReport
    {
        $blocking = [];
        $warnings = [];
        $indexed = $this->indexed($resolved);

        $this->auditAmbiencePerScene($story, $indexed, $blocking);
        $this->auditBedCoverage($story, $narrationPath, $blocking);
        $this->auditKeyEffects($story, $indexed, $blocking, $warnings);
        $this->auditResolvedFiles($resolved, $blocking);

        return new CoverageReport(
            passed: $blocking === [],
            blocking: $blocking,
            warnings: $warnings,
            sourceBreakdown: $this->sourceBreakdown($resolved),
            ladderBreakdown: $this->ladderBreakdown($resolved),
        );
    }

    /**
     * @param  list<array<string, mixed>>  $resolved
     * @return array<string, int>
     */
    public function sourceBreakdown(array $resolved): array
    {
        $counts = [
            ResolvedSound::SOURCE_CACHE => 0,
            ResolvedSound::SOURCE_DOWNLOAD => 0,
            ResolvedSound::SOURCE_FALLBACK => 0,
            ResolvedSound::SOURCE_SYNTH => 0,
        ];

        foreach ($resolved as $cue) {
            $source = (string) ($cue['source'] ?? '');

            if (array_key_exists($source, $counts)) {
                $counts[$source]++;
            }
        }

        return $counts;
    }

    /**
     * @param  list<array<string, mixed>>  $resolved
     * @return array<int, int>
     */
    public function ladderBreakdown(array $resolved): array
    {
        $counts = [1 => 0, 2 => 0, 3 => 0, 4 => 0];

        foreach ($resolved as $cue) {
            $level = $cue['ladderLevel'] ?? null;

            if (is_int($level) && array_key_exists($level, $counts)) {
                $counts[$level]++;
            }
        }

        return $counts;
    }

    /**
     * @param  array<string, array<string, mixed>>  $indexed
     * @param  list<string>  $blocking
     */
    private function auditAmbiencePerScene(Story $story, array $indexed, array &$blocking): void
    {
        foreach ($story->scenes as $scene) {
            $cue = $indexed['ambience.'.$scene->order] ?? null;
            $path = $this->existingPath($cue);

            if ($path === null) {
                $blocking[] = "La escena {$scene->order} no tiene ambiente resuelto.";
            }
        }
    }

    /**
     * @param  list<string>  $blocking
     */
    private function auditBedCoverage(Story $story, string $narrationPath, array &$blocking): void
    {
        if (! $this->files->isFile($narrationPath)) {
            $blocking[] = 'No existe el máster de narración en disco.';

            return;
        }

        try {
            $masterDuration = $this->processor->duration($narrationPath);
        } catch (Throwable) {
            $blocking[] = 'No se pudo leer la duración del máster de narración.';

            return;
        }

        $windows = $this->sceneWindows($narrationPath);

        if ($windows === []) {
            $blocking[] = 'No hay timings.json con ventanas de escena junto al máster.';

            return;
        }

        foreach ($story->scenes as $scene) {
            if (! isset($windows[$scene->order])) {
                $blocking[] = "La escena {$scene->order} no tiene ventana en timings.json.";
            }
        }

        $ordered = array_values($windows);
        usort($ordered, static fn (array $left, array $right): int => $left['start'] <=> $right['start']);

        $first = $ordered[0];
        $last = $ordered[array_key_last($ordered)];

        if (abs($first['start'] - 0.0) > self::TOLERANCE_SECONDS) {
            $blocking[] = sprintf(
                'La cama de ambiente no empieza con el máster (inicio %.3f s).',
                $first['start'],
            );
        }

        if (abs($last['end'] - $masterDuration) > self::TOLERANCE_SECONDS) {
            $blocking[] = sprintf(
                'La cama de ambiente no cubre el máster (fin %.3f s, narración %.3f s).',
                $last['end'],
                $masterDuration,
            );
        }

        for ($index = 0; $index < count($ordered) - 1; $index++) {
            $current = $ordered[$index];
            $next = $ordered[$index + 1];
            $delta = $next['start'] - $current['end'];

            if ($delta > self::TOLERANCE_SECONDS) {
                $blocking[] = sprintf(
                    'Hueco de %.3f s en la cama entre las escenas %d y %d.',
                    $delta,
                    $current['order'],
                    $next['order'],
                );
            }

            if ($delta < -self::TOLERANCE_SECONDS) {
                $blocking[] = sprintf(
                    'Solape de %.3f s en la cama entre las escenas %d y %d.',
                    abs($delta),
                    $current['order'],
                    $next['order'],
                );
            }
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $indexed
     * @param  list<string>  $blocking
     * @param  list<string>  $warnings
     */
    private function auditKeyEffects(Story $story, array $indexed, array &$blocking, array &$warnings): void
    {
        foreach ($story->scenes as $scene) {
            foreach ($scene->soundEffectSpecs() as $index => $effect) {
                if ($effect->kind !== SceneSoundEffect::KIND_KEY) {
                    continue;
                }

                $id = 'sfx.'.$scene->order.'.'.($index + 1);
                $cue = $indexed[$id] ?? null;
                $path = $this->existingPath($cue);

                if ($path !== null) {
                    continue;
                }

                $reason = $this->omitReason($cue);

                if ($reason !== null) {
                    $warnings[] = "Efecto clave {$id} omitido: {$reason}";

                    continue;
                }

                $blocking[] = "El efecto clave {$id} no está resuelto ni omitido con motivo.";
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $resolved
     * @param  list<string>  $blocking
     */
    private function auditResolvedFiles(array $resolved, array &$blocking): void
    {
        $seen = [];

        foreach ($resolved as $cue) {
            $stored = trim((string) ($cue['file'] ?? ''));

            if ($stored === '') {
                continue;
            }

            $id = (string) ($cue['id'] ?? $stored);
            $absolute = $this->manifest->absoluteFile($stored);

            if (! $this->files->isFile($absolute)) {
                $blocking[] = "El fichero de {$id} no está en disco: {$stored}";

                continue;
            }

            if (isset($seen[$absolute])) {
                continue;
            }

            $seen[$absolute] = true;

            try {
                $duration = $this->processor->duration($absolute);
            } catch (Throwable) {
                $blocking[] = "No se pudo leer la duración de {$id} ({$stored}).";

                continue;
            }

            if ($duration <= 0.0) {
                $blocking[] = "La señal {$id} apunta a un fichero de duración cero.";
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $resolved
     * @return array<string, array<string, mixed>>
     */
    private function indexed(array $resolved): array
    {
        $indexed = [];

        foreach ($resolved as $cue) {
            $id = (string) ($cue['id'] ?? '');

            if ($id !== '') {
                $indexed[$id] = $cue;
            }
        }

        return $indexed;
    }

    /**
     * @param  array<string, mixed>|null  $cue
     */
    private function existingPath(?array $cue): ?string
    {
        if ($cue === null) {
            return null;
        }

        $stored = trim((string) ($cue['file'] ?? ''));

        if ($stored === '') {
            return null;
        }

        $absolute = $this->manifest->absoluteFile($stored);

        return $this->files->isFile($absolute) ? $absolute : null;
    }

    /**
     * @param  array<string, mixed>|null  $cue
     */
    private function omitReason(?array $cue): ?string
    {
        if ($cue === null) {
            return null;
        }

        $reason = trim((string) ($cue['omitReason'] ?? ''));

        if ($reason !== '') {
            return $reason;
        }

        if ((string) ($cue['source'] ?? '') === ResolvedSound::SOURCE_SYNTH
            && trim((string) ($cue['file'] ?? '')) === '') {
            return 'sin síntesis creíble para esta categoría';
        }

        return null;
    }

    /**
     * @return array<int, array{order: int, start: float, end: float}>
     */
    private function sceneWindows(string $narrationPath): array
    {
        $path = dirname($narrationPath).DIRECTORY_SEPARATOR.'timings.json';

        if (! $this->files->isFile($path)) {
            return [];
        }

        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($this->files->get($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        $windows = [];

        foreach (is_array($decoded['scenes'] ?? null) ? $decoded['scenes'] : [] as $row) {
            if (! is_array($row)) {
                continue;
            }

            $order = (int) ($row['order'] ?? 0);
            $start = (float) ($row['start'] ?? 0);
            $end = (float) ($row['end'] ?? 0);
            $duration = (float) ($row['duration'] ?? 0);

            if ($end <= $start && $duration > 0) {
                $end = $start + $duration;
            }

            if ($order < 1 || $end <= $start) {
                continue;
            }

            $windows[$order] = [
                'order' => $order,
                'start' => round($start, 3),
                'end' => round($end, 3),
            ];
        }

        return $windows;
    }
}
