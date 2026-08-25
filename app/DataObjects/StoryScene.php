<?php

declare(strict_types=1);

namespace App\DataObjects;

final readonly class StoryScene
{
    /**
     * @param  list<string>  $visualBeats
     */
    public function __construct(
        public int $order,
        public string $narration,
        public string $imagePrompt,
        public ?string $soundEffect,
        public array $visualBeats = [],
    ) {}

    /**
     * @param  array{order: int, narration: string, imagePrompt: string, soundEffect: ?string, visualBeats?: list<string>}  $data
     */
    public static function fromArray(array $data): self
    {
        $beats = [];

        foreach ($data['visualBeats'] ?? [] as $beat) {
            if (is_string($beat) && trim($beat) !== '') {
                $beats[] = trim($beat);
            }
        }

        return new self(
            order: $data['order'],
            narration: $data['narration'],
            imagePrompt: $data['imagePrompt'],
            soundEffect: $data['soundEffect'] ?? null,
            visualBeats: $beats,
        );
    }

    /**
     * @return array{order: int, narration: string, imagePrompt: string, soundEffect: ?string, visualBeats: list<string>}
     */
    public function toArray(): array
    {
        return [
            'order' => $this->order,
            'narration' => $this->narration,
            'imagePrompt' => $this->imagePrompt,
            'soundEffect' => $this->soundEffect,
            'visualBeats' => $this->visualBeats,
        ];
    }
}
