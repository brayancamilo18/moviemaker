<?php

declare(strict_types=1);

namespace App\Services\Audio;

final class QueryLadder
{
    /**
     * @var list<string>
     */
    private const MODIFIERS = [
        'slow',
        'distant',
        'heavy',
        'soft',
        'old',
        'single',
        'close',
        'low',
        'faint',
        'sudden',
        'loud',
        'quiet',
    ];

    /**
     * @var list<string>
     */
    private const STOPWORDS = [
        'a', 'an', 'the', 'of', 'on', 'in', 'at', 'to', 'for',
        'with', 'from', 'and', 'or', 'into', 'over', 'under', 'by',
    ];

    public function __construct(
        private SoundCategorizer $categorizer,
    ) {}

    /**
     * @param  list<string>  $tags
     * @return list<array{level: int, query: string}>
     */
    public function levels(string $query, array $tags, ?string $category): array
    {
        $query = trim($query);
        $tags = $this->normalizeList($tags);
        $steps = [];

        $this->push($steps, 1, $query);
        $this->push($steps, 2, $this->withoutModifiers($query));
        $this->push($steps, 3, $this->core($query, $tags, $category));

        if ($category !== null && trim($category) !== '') {
            $resolved = $this->categorizer->find(trim($category));
            $curated = is_array($resolved) ? trim($resolved['curatedQuery']) : '';
            $this->push($steps, 4, $curated);
        }

        return $steps;
    }

    /**
     * @param  list<array{level: int, query: string}>  $steps
     */
    private function push(array &$steps, int $level, string $query): void
    {
        $query = trim(preg_replace('/\s+/u', ' ', $query) ?? $query);

        if ($query === '') {
            return;
        }

        $normalized = mb_strtolower($query);

        foreach ($steps as $step) {
            if (mb_strtolower($step['query']) === $normalized) {
                return;
            }
        }

        $steps[] = [
            'level' => $level,
            'query' => $query,
        ];
    }

    private function withoutModifiers(string $query): string
    {
        $kept = [];

        foreach ($this->tokens($query) as $token) {
            if ($this->isModifier($token)) {
                continue;
            }

            $kept[] = $token;
        }

        return implode(' ', $kept);
    }

    /**
     * @param  list<string>  $tags
     */
    private function core(string $query, array $tags, ?string $category): string
    {
        $keywords = [];
        $slug = $category !== null ? trim($category) : '';

        if ($slug !== '') {
            $resolved = $this->categorizer->find($slug);
            $keywords = is_array($resolved) ? $resolved['keywords'] : [];
        }

        $noun = $this->firstRelevantNoun($query, $tags, $keywords);
        $mainTag = $this->mainTag($tags);

        if ($noun === '') {
            return $mainTag;
        }

        if ($mainTag === '' || $mainTag === $noun) {
            return $noun;
        }

        return $noun.' '.$mainTag;
    }

    /**
     * @param  list<string>  $tags
     * @param  list<string>  $keywords
     */
    private function firstRelevantNoun(string $query, array $tags, array $keywords): string
    {
        $tokens = [];

        foreach ($this->tokens($query) as $token) {
            if ($this->isModifier($token) || $this->isStopword($token)) {
                continue;
            }

            $tokens[] = $token;
        }

        $preferred = array_values(array_unique([...$tags, ...$keywords]));

        foreach ($tokens as $token) {
            if (in_array($token, $preferred, true)) {
                return $token;
            }
        }

        return $tokens[0] ?? '';
    }

    /**
     * @param  list<string>  $tags
     */
    private function mainTag(array $tags): string
    {
        foreach ($tags as $tag) {
            if (! $this->isModifier($tag) && ! $this->isStopword($tag)) {
                return $tag;
            }
        }

        return $tags[0] ?? '';
    }

    /**
     * @return list<string>
     */
    private function tokens(string $text): array
    {
        $parts = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower(trim($text)), -1, PREG_SPLIT_NO_EMPTY);

        return is_array($parts) ? array_values($parts) : [];
    }

    /**
     * @param  list<mixed>  $values
     * @return list<string>
     */
    private function normalizeList(array $values): array
    {
        $normalized = [];

        foreach ($values as $value) {
            $item = mb_strtolower(trim((string) $value));

            if ($item !== '' && ! in_array($item, $normalized, true)) {
                $normalized[] = $item;
            }
        }

        return $normalized;
    }

    private function isModifier(string $token): bool
    {
        return in_array($token, self::MODIFIERS, true);
    }

    private function isStopword(string $token): bool
    {
        return in_array($token, self::STOPWORDS, true);
    }
}
