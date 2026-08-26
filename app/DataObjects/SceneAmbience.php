<?php

declare(strict_types=1);

namespace App\DataObjects;

final readonly class SceneAmbience
{
    public const INTENSITY_SUBTLE = 'subtle';

    public const INTENSITY_MODERATE = 'moderate';

    public const INTENSITY_HEAVY = 'heavy';

    /**
     * @param  list<string>  $tags
     */
    public function __construct(
        public string $query,
        public array $tags,
        public string $intensity,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $tags = [];

        foreach (is_array($data['tags'] ?? null) ? $data['tags'] : [] as $tag) {
            $tag = mb_strtolower(trim((string) $tag));

            if ($tag !== '' && ! in_array($tag, $tags, true)) {
                $tags[] = $tag;
            }
        }

        $intensity = strtolower(trim((string) ($data['intensity'] ?? self::INTENSITY_MODERATE)));

        if (! in_array($intensity, [
            self::INTENSITY_SUBTLE,
            self::INTENSITY_MODERATE,
            self::INTENSITY_HEAVY,
        ], true)) {
            $intensity = self::INTENSITY_MODERATE;
        }

        return new self(
            query: trim((string) ($data['query'] ?? '')),
            tags: $tags,
            intensity: $intensity,
        );
    }

    /**
     * @return array{query: string, tags: list<string>, intensity: string}
     */
    public function toArray(): array
    {
        return [
            'query' => $this->query,
            'tags' => $this->tags,
            'intensity' => $this->intensity,
        ];
    }
}
