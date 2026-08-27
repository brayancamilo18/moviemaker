<?php

declare(strict_types=1);

namespace App\Services\Audio;

use App\Exceptions\FreesoundException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Corta el acceso a Freesound cuando el proveedor está caído o rechaza las credenciales.
 * Los fallos transitorios necesitan repetirse; los deterministas abren el circuito a la primera.
 */
final class ProviderCircuit
{
    private const FAILURE_THRESHOLD = 3;

    private int $consecutiveFailures = 0;

    private bool $open = false;

    public function __construct(
        private LoggerInterface $logger,
    ) {}

    public function isOpen(): bool
    {
        return $this->open;
    }

    public function recordSuccess(): void
    {
        $this->consecutiveFailures = 0;
    }

    public function recordFailure(Throwable $exception): void
    {
        if ($this->open) {
            return;
        }

        if ($this->isAuthFailure($exception)) {
            $this->open = true;
            $this->logger->warning(
                'Freesound rechaza la autenticación: revisa FREESOUND_TOKEN. '
                .'El resto de la historia se resolverá con el kit local y síntesis.',
            );

            return;
        }

        if (! $this->isTransientFailure($exception)) {
            return;
        }

        $this->consecutiveFailures++;

        if ($this->consecutiveFailures < self::FAILURE_THRESHOLD) {
            return;
        }

        $this->open = true;
        $this->logger->warning(sprintf(
            'Freesound marcado como caído tras %d fallos consecutivos; el resto de la ejecución no tocará la red.',
            self::FAILURE_THRESHOLD,
        ));
    }

    /**
     * Token ausente, caducado o sin permisos: reintentar no cambia nada.
     */
    private function isAuthFailure(Throwable $exception): bool
    {
        $current = $exception;

        while ($current instanceof Throwable) {
            if ($current instanceof RequestException) {
                $status = $current->response->status();

                if ($status === 401 || $status === 403) {
                    return true;
                }
            }

            $current = $current->getPrevious();
        }

        // La falta de token no llega a hacer petición: no hay respuesta HTTP que inspeccionar.
        return $exception instanceof FreesoundException
            && str_contains($exception->getMessage(), 'FREESOUND_TOKEN');
    }

    private function isTransientFailure(Throwable $exception): bool
    {
        $current = $exception;

        while ($current instanceof Throwable) {
            if ($current instanceof ConnectionException) {
                return true;
            }

            if ($current instanceof RequestException) {
                $status = $current->response->status();

                return $status >= 500 || $status === 408;
            }

            $current = $current->getPrevious();
        }

        if ($exception instanceof FreesoundException) {
            $message = $exception->getMessage();

            if (preg_match('/HTTP 5\d\d/', $message) === 1) {
                return true;
            }

            return str_contains(mb_strtolower($message), 'conectar')
                || str_contains(mb_strtolower($message), 'timeout')
                || str_contains(mb_strtolower($message), 'timed out');
        }

        return false;
    }
}
