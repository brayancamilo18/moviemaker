<?php

declare(strict_types=1);

namespace App\DataObjects;

final readonly class StoryScene
{
    /**
     * @param  list<array{description: string, subject: string, threatStage: ?string}>  $visualBeats
     * @param  list<SceneSoundEffect>  $soundEffects
     */
    public function __construct(
        public int $order,
        public string $narration,
        public string $imagePrompt,
        public ?string $soundEffect,
        public array $visualBeats = [],
        public ?SceneAmbience $ambience = null,
        public array $soundEffects = [],
    ) {}

    /**
     * @param  array{order: int, narration: string, imagePrompt: string, soundEffect: ?string, visualBeats?: list<string|array<string, mixed>>, ambience?: array<string, mixed>|null, soundEffects?: list<array<string, mixed>>}  $data
     */
    public static function fromArray(array $data): self
    {
        $beats = [];

        foreach ($data['visualBeats'] ?? [] as $beat) {
            $parsed = self::visualBeat($beat);

            if ($parsed !== null) {
                $beats[] = $parsed;
            }
        }

        $effects = [];

        foreach (is_array($data['soundEffects'] ?? null) ? $data['soundEffects'] : [] as $effect) {
            if (! is_array($effect)) {
                continue;
            }

            $parsed = SceneSoundEffect::fromArray($effect);

            if ($parsed instanceof SceneSoundEffect) {
                $effects[] = $parsed;
            }
        }

        return new self(
            order: $data['order'],
            narration: $data['narration'],
            imagePrompt: $data['imagePrompt'] ?? '',
            soundEffect: $data['soundEffect'] ?? null,
            visualBeats: $beats,
            ambience: isset($data['ambience']) && is_array($data['ambience'])
                ? SceneAmbience::fromArray($data['ambience'])
                : null,
            soundEffects: $effects,
        );
    }

    /**
     * @return array{order: int, narration: string, imagePrompt: string, soundEffect: ?string, visualBeats: list<array{description: string, subject: string, threatStage: ?string}>, ambience: ?array{query: string, tags: list<string>, intensity: string}, soundEffects: list<array{query: string, tags: list<string>, anchorText: string, kind: string}>}
     */
    public function toArray(): array
    {
        return [
            'order' => $this->order,
            'narration' => $this->narration,
            'imagePrompt' => $this->imagePrompt,
            'soundEffect' => $this->soundEffect,
            'visualBeats' => $this->visualBeats,
            'ambience' => $this->ambience?->toArray(),
            'soundEffects' => array_map(
                static fn (SceneSoundEffect $effect): array => $effect->toArray(),
                $this->soundEffects,
            ),
        ];
    }

    /**
     * @return list<SceneSoundEffect>
     */
    public function soundEffectSpecs(): array
    {
        if ($this->soundEffects !== []) {
            return $this->soundEffects;
        }

        $legacy = trim((string) $this->soundEffect);

        if ($legacy === '') {
            return [];
        }

        return [SceneSoundEffect::fromLegacy($legacy)];
    }

    /**
     * @return array{description: string, subject: string, threatStage: ?string}|null
     */
    private static function visualBeat(mixed $beat): ?array
    {
        if (is_string($beat)) {
            $description = trim($beat);

            if ($description === '') {
                return null;
            }

            return [
                'description' => $description,
                'subject' => 'environment',
                'threatStage' => null,
            ];
        }

        if (! is_array($beat)) {
            return null;
        }

        $description = trim((string) ($beat['description'] ?? ''));

        if ($description === '') {
            return null;
        }

        $subject = strtolower(trim((string) ($beat['subject'] ?? '')));

        if (! in_array($subject, ['protagonist', 'threat', 'both', 'environment', 'detail'], true)) {
            return null;
        }

        $stageRaw = $beat['threatStage'] ?? null;
        $stage = is_string($stageRaw) ? strtolower(trim($stageRaw)) : null;

        if ($stage === '') {
            $stage = null;
        }

        if (in_array($subject, ['threat', 'both'], true)) {
            if (! in_array($stage, ['hint', 'presence', 'reveal'], true)) {
                $stage = null;
            }
        } else {
            $stage = null;
        }

        return [
            'description' => $description,
            'subject' => $subject,
            'threatStage' => $stage,
        ];
    }
}
