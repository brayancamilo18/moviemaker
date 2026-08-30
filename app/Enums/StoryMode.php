<?php

declare(strict_types=1);

namespace App\Enums;

enum StoryMode: string
{
    case Folklore = 'folclore';
    case Original = 'original';

    public function label(): string
    {
        return match ($this) {
            self::Folklore => 'Folclore',
            self::Original => 'Original',
        };
    }
}
