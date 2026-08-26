<?php

declare(strict_types=1);

namespace App\Services\Audio;

use InvalidArgumentException;

final class AudioDuration
{
    /**
     * @return array{min: float, max: float}
     */
    public static function range(string $type): array
    {
        return match ($type) {
            'ambience' => ['min' => 20.0, 'max' => 300.0],
            'sfx' => ['min' => 0.2, 'max' => 15.0],
            'music' => ['min' => 15.0, 'max' => 180.0],
            default => throw new InvalidArgumentException("El tipo '{$type}' no es válido. Usa ambience, sfx o music."),
        };
    }

    public static function contains(string $type, float $seconds): bool
    {
        $range = self::range($type);

        return $seconds >= $range['min'] && $seconds <= $range['max'];
    }

    public static function filter(string $type): string
    {
        $range = self::range($type);

        return sprintf('duration:[%s TO %s]', $range['min'], $range['max']);
    }
}
