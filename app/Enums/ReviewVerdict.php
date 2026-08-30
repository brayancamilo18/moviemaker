<?php

declare(strict_types=1);

namespace App\Enums;

enum ReviewVerdict: string
{
    case Publish = 'publish';
    case Revise = 'revise';
    case Discard = 'discard';
}
