<?php

declare(strict_types=1);

namespace App\DataObjects;

final readonly class Story
{
    /**
     * @param  list<string>  $tags
     * @param  list<StoryScene>  $scenes
     * @param  list<Pronunciation>  $pronunciations
     */
    public function __construct(
        public string $title,
        public string $hook,
        public string $description,
        public array $tags,
        public string $thumbnailPrompt,
        public array $scenes,
        public array $pronunciations,
        public ?VisualBible $visualBible = null,
    ) {}

    /**
     * @param  array{title: string, hook: string, description: string, tags: list<string>, thumbnailPrompt: string, scenes: list<array{order: int, narration: string, imagePrompt?: string, visualSummary?: string, ambience?: array<string, mixed>|null}>, pronunciations?: list<array{term: string, phonetic: string}>, visualBible?: array<string, mixed>}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            title: $data['title'],
            hook: $data['hook'],
            description: $data['description'],
            tags: $data['tags'],
            thumbnailPrompt: $data['thumbnailPrompt'],
            scenes: array_map(
                static fn (array $scene): StoryScene => StoryScene::fromArray($scene),
                $data['scenes'],
            ),
            pronunciations: array_map(
                static fn (array $pronunciation): Pronunciation => Pronunciation::fromArray($pronunciation),
                $data['pronunciations'] ?? [],
            ),
            visualBible: isset($data['visualBible']) && is_array($data['visualBible'])
                ? VisualBible::fromArray($data['visualBible'])
                : null,
        );
    }

    /**
     * @return array{title: string, hook: string, description: string, tags: list<string>, thumbnailPrompt: string, scenes: list<array{order: int, narration: string, imagePrompt: string, visualSummary: string, ambience: ?array{query: string, tags: list<string>, intensity: string}}>, pronunciations: list<array{term: string, phonetic: string}>, visualBible?: array{setting: string, era: string, timeOfDay: string, weather: string, palette: list<string>, characters: list<array{slug: string, bodyDescriptor: string, framingOptions: list<string>}>, recurringObjects: list<array{slug: string, descriptor: string}>, avoid: list<string>, threat: array{nature: string, stages: list<array{stage: string, descriptor: string}>}}}
     */
    public function toArray(): array
    {
        $payload = [
            'title' => $this->title,
            'hook' => $this->hook,
            'description' => $this->description,
            'tags' => $this->tags,
            'thumbnailPrompt' => $this->thumbnailPrompt,
            'scenes' => array_map(
                static fn (StoryScene $scene): array => $scene->toArray(),
                $this->scenes,
            ),
            'pronunciations' => array_map(
                static fn (Pronunciation $pronunciation): array => $pronunciation->toArray(),
                $this->pronunciations,
            ),
        ];

        if ($this->visualBible instanceof VisualBible) {
            $payload['visualBible'] = $this->visualBible->toArray();
        }

        return $payload;
    }

    public function withVisualBible(VisualBible $visualBible): self
    {
        return new self(
            title: $this->title,
            hook: $this->hook,
            description: $this->description,
            tags: $this->tags,
            thumbnailPrompt: $this->thumbnailPrompt,
            scenes: $this->scenes,
            pronunciations: $this->pronunciations,
            visualBible: $visualBible,
        );
    }

    public function wordCount(): int
    {
        $narration = implode(' ', array_map(
            static fn (StoryScene $scene): string => $scene->narration,
            $this->scenes,
        ));

        $words = preg_split('/\s+/u', trim($narration), -1, PREG_SPLIT_NO_EMPTY);

        return $words === false ? 0 : count($words);
    }

    public function estimatedDurationSeconds(): int
    {
        return (int) round($this->wordCount() / 130 * 60);
    }

    public function narrationForTts(): string
    {
        return implode(' ', array_map(
            fn (StoryScene $scene): string => $this->textForTts($scene->narration),
            $this->scenes,
        ));
    }

    /**
     * Misma fonética que narrationForTts(), partida por escena para conservar pausas.
     *
     * @return list<array{order: int, text: string}>
     */
    public function scenesForTts(): array
    {
        return array_map(
            fn (StoryScene $scene): array => [
                'order' => $scene->order,
                'text' => $this->textForTts($scene->narration),
            ],
            $this->scenes,
        );
    }

    public function textForTts(string $text): string
    {
        $pronunciations = $this->pronunciations;

        usort(
            $pronunciations,
            static fn (Pronunciation $left, Pronunciation $right): int => mb_strlen($right->term) <=> mb_strlen($left->term),
        );

        foreach ($pronunciations as $pronunciation) {
            if ($pronunciation->term === '') {
                continue;
            }

            $text = str_replace($pronunciation->term, $pronunciation->phonetic, $text);
        }

        return $text;
    }
}
