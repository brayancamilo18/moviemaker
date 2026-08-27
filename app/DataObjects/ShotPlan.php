<?php

declare(strict_types=1);

namespace App\DataObjects;

/**
 * Contenido completo de shots.json.
 */
final readonly class ShotPlan
{
    /** Esquema del fichero. */
    public const VERSION = 1;

    /** Tolerancia al comparar los cortes persistidos con los recién planificados (1 ms). */
    private const TIME_TOLERANCE = 0.001;

    /**
     * @param  list<PlannedShot>  $shots
     */
    public function __construct(
        public int $version,
        public int $plannerVersion,
        public array $shots,
    ) {}

    /**
     * @param  array{version?: int, plannerVersion?: int, shots?: list<array<string, mixed>>}  $data
     */
    public static function fromArray(array $data): self
    {
        $shots = [];

        foreach (is_array($data['shots'] ?? null) ? $data['shots'] : [] as $row) {
            if (! is_array($row) || ! isset($row['order'], $row['sceneOrder'])) {
                continue;
            }

            /** @var array{order: int, sceneOrder: int} $row */
            $shots[] = PlannedShot::fromArray($row);
        }

        return new self(
            version: (int) ($data['version'] ?? self::VERSION),
            plannerVersion: (int) ($data['plannerVersion'] ?? 0),
            shots: $shots,
        );
    }

    /**
     * @return array{version: int, plannerVersion: int, shots: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'version' => $this->version,
            'plannerVersion' => $this->plannerVersion,
            'shots' => array_map(
                static fn (PlannedShot $row): array => $row->toArray(),
                $this->shots,
            ),
        ];
    }

    /**
     * Filas indexadas por el orden del plano.
     *
     * @return array<int, PlannedShot>
     */
    public function byOrder(): array
    {
        $rows = [];

        foreach ($this->shots as $row) {
            $rows[$row->shot->order] = $row;
        }

        return $rows;
    }

    /**
     * ¿Estas filas describen el mismo teselado que el plan recién calculado?
     *
     * @param  list<Shot>  $shots
     */
    public function describes(array $shots): bool
    {
        if ($shots === [] || count($shots) !== count($this->shots)) {
            return false;
        }

        foreach ($shots as $index => $shot) {
            $stored = $this->shots[$index]->shot;

            if ($stored->order !== $shot->order || $stored->sceneOrder !== $shot->sceneOrder) {
                return false;
            }

            if (abs($stored->start - $shot->start) > self::TIME_TOLERANCE) {
                return false;
            }

            if (abs($stored->end - $shot->end) > self::TIME_TOLERANCE) {
                return false;
            }
        }

        return true;
    }

    /**
     * ¿Todas las filas traen dirección aprovechable?
     */
    public function isDirected(): bool
    {
        if ($this->shots === []) {
            return false;
        }

        foreach ($this->shots as $row) {
            if (trim($row->shot->description) === '') {
                return false;
            }
        }

        return true;
    }
}
