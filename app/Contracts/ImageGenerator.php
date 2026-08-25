<?php

declare(strict_types=1);

namespace App\Contracts;

interface ImageGenerator
{
    public function generate(string $prompt, int $seed): string;

    public function isAvailable(): bool;
}
