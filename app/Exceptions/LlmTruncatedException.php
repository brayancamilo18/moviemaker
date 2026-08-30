<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Services\Llm\LlmTask;
use Throwable;

final class LlmTruncatedException extends LlmGenerationException
{
    public function __construct(
        string $message,
        public readonly LlmTask $task,
        public readonly int $budget,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }
}
