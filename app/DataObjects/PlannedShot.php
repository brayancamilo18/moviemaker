<?php

declare(strict_types=1);

namespace App\DataObjects;

/**
 * Fila persistida en shots.json: el plano dirigido más lo que produjo el generador de imágenes.
 * La ruta de la imagen vive en $shot->imagePath para no duplicar el campo.
 */
final readonly class PlannedShot
{
    public function __construct(
        public Shot $shot,
        public string $prompt,
        public int $seed,
        public bool $placeholder,
    ) {}

    /**
     * @param  array{
     *     order: int,
     *     sceneOrder: int,
     *     start?: float,
     *     end?: float,
     *     sourceText?: string,
     *     framing?: string,
     *     motion?: string,
     *     subject?: string,
     *     threatStage?: string|null,
     *     journeyLeg?: string|null,
     *     lightStage?: string|null,
     *     description?: string,
     *     characterSlugs?: list<string>,
     *     imagePath?: string|null,
     *     prompt?: string,
     *     seed?: int,
     *     placeholder?: bool
     * }  $data
     */
    public static function fromArray(array $data): self
    {
        $shot = Shot::fromArray($data);
        $placeholder = (bool) ($data['placeholder'] ?? false);

        if (is_string($shot->imagePath) && str_starts_with(basename($shot->imagePath), 'placeholder-')) {
            $placeholder = true;
        }

        return new self(
            shot: $shot,
            prompt: is_string($data['prompt'] ?? null) ? $data['prompt'] : '',
            seed: (int) ($data['seed'] ?? 0),
            placeholder: $placeholder,
        );
    }

    public static function fromShot(Shot $shot, string $prompt, int $seed, ?string $imagePath = null): self
    {
        return new self(
            shot: new Shot(
                order: $shot->order,
                sceneOrder: $shot->sceneOrder,
                start: $shot->start,
                end: $shot->end,
                sourceText: $shot->sourceText,
                framing: $shot->framing,
                motion: $shot->motion,
                subject: $shot->subject,
                threatStage: $shot->threatStage,
                journeyLeg: $shot->journeyLeg,
                lightStage: $shot->lightStage,
                description: $shot->description,
                characterSlugs: $shot->characterSlugs,
                imagePath: $imagePath,
            ),
            prompt: $prompt,
            seed: $seed,
            placeholder: is_string($imagePath) && str_starts_with(basename($imagePath), 'placeholder-'),
        );
    }

    /**
     * @return array{
     *     order: int,
     *     sceneOrder: int,
     *     start: float,
     *     end: float,
     *     sourceText: string,
     *     framing: string,
     *     motion: string,
     *     subject: string,
     *     threatStage: ?string,
     *     journeyLeg: ?string,
     *     lightStage: ?string,
     *     description: string,
     *     characterSlugs: list<string>,
     *     imagePath: ?string,
     *     prompt: string,
     *     seed: int,
     *     placeholder: bool
     * }
     */
    public function toArray(): array
    {
        return [
            ...$this->shot->toArray(),
            'prompt' => $this->prompt,
            'seed' => $this->seed,
            'placeholder' => $this->placeholder,
        ];
    }
}
