<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

final class WhisperException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $command = '',
        public readonly string $errorOutput = '',
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    public static function fromProcess(Process $process): self
    {
        $command = $process->getCommandLine();
        $stderr = $process->getErrorOutput();
        $code = $process->getExitCode() ?? 1;

        return new self(
            "whisper.cpp falló (código {$code}).\nComando: {$command}\n{$stderr}",
            $command,
            $stderr,
            $code,
        );
    }
}
