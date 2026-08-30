<?php

declare(strict_types=1);

namespace App\Enums;

enum DiscardReason: string
{
    case WeakScript = 'weak_script';
    case Voice = 'voice';
    case Images = 'images';
    case Sound = 'sound';
    case Pacing = 'pacing';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::WeakScript => 'Guion flojo',
            self::Voice => 'Voz o pronunciación',
            self::Images => 'Imágenes incoherentes',
            self::Sound => 'Sonido',
            self::Pacing => 'Ritmo',
            self::Other => 'Otro',
        };
    }
}
