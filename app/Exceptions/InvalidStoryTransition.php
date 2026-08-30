<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\StoryStatus;
use RuntimeException;

final class InvalidStoryTransition extends RuntimeException
{
    public function __construct(StoryStatus $from, StoryStatus $to)
    {
        parent::__construct(sprintf(
            'Transición inválida: de «%s» a «%s».',
            $from->value,
            $to->value,
        ));
    }
}
