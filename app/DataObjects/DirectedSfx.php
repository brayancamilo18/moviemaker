<?php

declare(strict_types=1);

namespace App\DataObjects;

final readonly class DirectedSfx
{
    public const IMPORTANCE_KEY = 'key';

    public const IMPORTANCE_TEXTURE = 'texture';

    /**
     * @param  list<string>  $tags
     * @param  string  $anchorWord  Palabra de la narración que nombra el sonido. Es de donde cuelga el
     *                              golpe: sin ella solo queda estimar con offsetRatio, que en un plano
     *                              de cuatro segundos se equivoca por más de lo que el oído perdona.
     */
    public function __construct(
        public int $shotIndex,
        public float $offsetRatio,
        public string $query,
        public array $tags,
        public string $importance,
        public string $anchorWord = '',
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

        $importance = strtolower(trim((string) ($data['importance'] ?? self::IMPORTANCE_TEXTURE)));

        if (! in_array($importance, [self::IMPORTANCE_KEY, self::IMPORTANCE_TEXTURE], true)) {
            $importance = self::IMPORTANCE_TEXTURE;
        }

        $ratio = (float) ($data['offsetRatio'] ?? 0.0);

        return new self(
            shotIndex: (int) ($data['shotIndex'] ?? 0),
            offsetRatio: max(0.0, min(1.0, $ratio)),
            query: trim((string) ($data['query'] ?? '')),
            tags: $tags,
            importance: $importance,
            anchorWord: mb_strtolower(trim((string) ($data['anchorWord'] ?? ''))),
        );
    }

    /**
     * @return array{shotIndex: int, offsetRatio: float, query: string, tags: list<string>, importance: string, anchorWord: string}
     */
    public function toArray(): array
    {
        return [
            'shotIndex' => $this->shotIndex,
            'offsetRatio' => $this->offsetRatio,
            'query' => $this->query,
            'tags' => $this->tags,
            'importance' => $this->importance,
            'anchorWord' => $this->anchorWord,
        ];
    }
}
