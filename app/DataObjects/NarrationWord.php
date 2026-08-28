<?php

declare(strict_types=1);

namespace App\DataObjects;

/**
 * Una palabra del máster con su ventana real, tal como la oyó whisper. Solo existen para las frases
 * que anclaron por texto: en las que se colocaron por posición los tiempos por palabra serían
 * inventados, y publicar tiempos inventados es peor que no publicarlos.
 *
 * Sirve para colgar un efecto de la palabra que lo nombra en vez de estimarlo con una regla de tres
 * sobre el plano. El token viene normalizado igual que en la alineación: minúsculas y sin puntuación.
 */
final readonly class NarrationWord
{
    public function __construct(
        public string $token,
        public float $start,
        public float $end,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $start = round((float) ($data['start'] ?? 0), 3);
        $end = round((float) ($data['end'] ?? 0), 3);

        return new self(
            token: mb_strtolower(trim((string) ($data['token'] ?? ''))),
            start: $start,
            end: max($start, $end),
        );
    }

    /**
     * @return array{token: string, start: float, end: float}
     */
    public function toArray(): array
    {
        return [
            'token' => $this->token,
            'start' => $this->start,
            'end' => $this->end,
        ];
    }
}
