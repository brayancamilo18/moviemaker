<?php

declare(strict_types=1);

namespace App\DataObjects;

final readonly class NarrationSentence
{
    public function __construct(
        public int $order,
        public int $sceneOrder,
        public string $text,
        public float $pauseAfter,
    ) {}
}
