<?php

declare(strict_types=1);

namespace App\DataObjects;

final readonly class ResolvedSound
{
    public const SOURCE_CACHE = 'cache';

    public const SOURCE_DOWNLOAD = 'download';

    public const SOURCE_FALLBACK = 'fallback';

    public const SOURCE_SYNTH = 'synth';

    public function __construct(
        public string $path,
        public string $source,
        public float $lufs,
        public bool $attributionRequired,
        public ?string $author,
        public ?string $license,
        public ?string $sourceUrl,
        public float $score,
        public ?int $ladderLevel = null,
        public ?string $omitReason = null,
    ) {}
}
