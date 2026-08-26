<?php

declare(strict_types=1);

namespace App\DataObjects;

final readonly class StoryScene
{
    public function __construct(
        public int $order,
        public string $narration,
        public string $imagePrompt,
        public string $visualSummary = '',
        public ?SceneAmbience $ambience = null,
    ) {}

    /**
     * @param  array{order: int, narration: string, imagePrompt?: string, visualSummary?: string, ambience?: array<string, mixed>|null}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            order: $data['order'],
            narration: $data['narration'],
            imagePrompt: (string) ($data['imagePrompt'] ?? ''),
            visualSummary: trim((string) ($data['visualSummary'] ?? '')),
            ambience: isset($data['ambience']) && is_array($data['ambience'])
                ? SceneAmbience::fromArray($data['ambience'])
                : null,
        );
    }

    /**
     * @return array{order: int, narration: string, imagePrompt: string, visualSummary: string, ambience: ?array{query: string, tags: list<string>, intensity: string}}
     */
    public function toArray(): array
    {
        return [
            'order' => $this->order,
            'narration' => $this->narration,
            'imagePrompt' => $this->imagePrompt,
            'visualSummary' => $this->visualSummary,
            'ambience' => $this->ambience?->toArray(),
        ];
    }
}
