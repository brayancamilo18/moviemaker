<?php

declare(strict_types=1);

namespace App\DataObjects;

final readonly class SceneSoundEffect
{
    public const KIND_KEY = 'key';

    public const KIND_TEXTURE = 'texture';

    /**
     * @param  list<string>  $tags
     */
    public function __construct(
        public string $query,
        public array $tags,
        public string $anchorText,
        public string $kind,
    ) {}

    public static function fromLegacy(string $query): self
    {
        $query = trim($query);

        return new self(
            query: $query,
            tags: self::tagsFromQuery($query),
            anchorText: '',
            kind: self::KIND_KEY,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): ?self
    {
        $query = trim((string) ($data['query'] ?? ''));

        if ($query === '') {
            return null;
        }

        $tags = [];

        foreach (is_array($data['tags'] ?? null) ? $data['tags'] : [] as $tag) {
            $tag = mb_strtolower(trim((string) $tag));

            if ($tag !== '' && ! in_array($tag, $tags, true)) {
                $tags[] = $tag;
            }
        }

        if ($tags === []) {
            $tags = self::tagsFromQuery($query);
        }

        $kind = strtolower(trim((string) ($data['kind'] ?? $data['importance'] ?? $data['priority'] ?? self::KIND_KEY)));

        if ($kind !== self::KIND_TEXTURE) {
            $kind = self::KIND_KEY;
        }

        return new self(
            query: $query,
            tags: $tags,
            anchorText: trim((string) ($data['anchorText'] ?? '')),
            kind: $kind,
        );
    }

    /**
     * @return array{query: string, tags: list<string>, anchorText: string, kind: string}
     */
    public function toArray(): array
    {
        return [
            'query' => $this->query,
            'tags' => $this->tags,
            'anchorText' => $this->anchorText,
            'kind' => $this->kind,
        ];
    }

    /**
     * @return list<string>
     */
    private static function tagsFromQuery(string $query): array
    {
        $parts = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($query), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $tags = [];

        foreach ($parts as $part) {
            if (mb_strlen($part) >= 3 && ! in_array($part, $tags, true)) {
                $tags[] = $part;
            }
        }

        return $tags;
    }
}
