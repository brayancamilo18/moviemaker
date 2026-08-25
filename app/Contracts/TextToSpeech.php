<?php

declare(strict_types=1);

namespace App\Contracts;

interface TextToSpeech
{
    /**
     * @param  array<string, mixed>  $options
     */
    public function synthesize(string $text, array $options = []): string;

    /**
     * @param  array<string, mixed>  $options
     */
    public function isCached(string $text, array $options = []): bool;

    public function isAvailable(): bool;
}
