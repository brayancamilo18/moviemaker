<?php

declare(strict_types=1);

namespace App\Services\Audio;

final readonly class VerificationResult
{
    /**
     * @param  list<string>  $failures
     */
    public function __construct(
        public bool $passed,
        public array $failures,
    ) {}

    public static function ok(): self
    {
        return new self(true, []);
    }

    public static function fail(string $reason): self
    {
        return new self(false, [$reason]);
    }
}
