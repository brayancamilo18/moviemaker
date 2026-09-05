<?php

declare(strict_types=1);

namespace App\Contracts;

interface ImageGenerator
{
    /**
     * $negativePrompt viaja aparte a propósito: metido en el prompt positivo, un
     * "no container ships" no es una negación para el codificador de texto, es la
     * palabra "container ships" pidiendo aparecer.
     */
    public function generate(string $prompt, int $seed, string $negativePrompt = ''): string;

    public function isAvailable(): bool;
}
