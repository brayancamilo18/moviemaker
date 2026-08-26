<?php

declare(strict_types=1);

namespace App\DataObjects;

final readonly class Shot
{
    public function __construct(
        public int $order,
        public int $sceneOrder,
        public float $start,
        public float $end,
        public string $sourceText,
        public string $framing,
        public string $motion,
        public string $subject,
        public ?string $threatStage,
        public ?string $imagePath,
    ) {}
}
