<?php

declare(strict_types=1);

namespace App\DataObjects;

final readonly class CoverageReport
{
    /**
     * @param  list<string>  $blocking
     * @param  list<string>  $warnings
     * @param  array<string, int>  $sourceBreakdown
     * @param  array<int, int>  $ladderBreakdown
     */
    public function __construct(
        public bool $passed,
        public array $blocking,
        public array $warnings,
        public array $sourceBreakdown,
        public array $ladderBreakdown,
    ) {}
}
