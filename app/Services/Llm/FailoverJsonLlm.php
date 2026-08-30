<?php

declare(strict_types=1);

namespace App\Services\Llm;

use App\Contracts\JsonLlm;
use App\Exceptions\LlmTruncatedException;
use App\Exceptions\LlmUnavailableException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Manda al respaldo cuando el proveedor principal no está disponible. El cambio es pegajoso: una
 * historia completa son unas treinta llamadas y cada intento fallido cuesta la tanda entera de
 * reintentos, así que en cuanto el principal se cae no se le vuelve a preguntar en este proceso.
 */
final class FailoverJsonLlm implements JsonLlm
{
    private ?string $reason = null;

    public function __construct(
        private JsonLlm $primary,
        private JsonLlm $fallback,
        private LoggerInterface $logger,
        private ProviderHealthStore $store,
        private ProviderHealth $health,
        private int $truncationRetryCap = 64000,
    ) {}

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    public function generateJson(
        string $systemInstruction,
        string $userPrompt,
        array $schema,
        LlmTask $task = LlmTask::Script,
        float $temperature = 1.0,
        ?int $maxTokensOverride = null,
    ): array {
        if ($this->reason === null && $this->primary->isAvailable()) {
            $started = hrtime(true);

            try {
                $result = $this->generateOn(
                    $this->primary,
                    $systemInstruction,
                    $userPrompt,
                    $schema,
                    $task,
                    $temperature,
                    $maxTokensOverride,
                );
                $this->record($this->primary, 'gemini', true, $this->elapsedMs($started), null);

                return $result;
            } catch (LlmUnavailableException $exception) {
                $this->record($this->primary, 'gemini', false, $this->elapsedMs($started), $exception);
                $this->fallBack($exception->getMessage());
            }
        }

        if ($this->reason === null) {
            $this->fallBack($this->primary->name().' no tiene credencial configurada.');
        }

        $started = hrtime(true);

        try {
            $result = $this->generateOn(
                $this->fallback,
                $systemInstruction,
                $userPrompt,
                $schema,
                $task,
                $temperature,
                $maxTokensOverride,
            );
            $this->record($this->fallback, 'anthropic', true, $this->elapsedMs($started), null);

            return $result;
        } catch (LlmUnavailableException $exception) {
            $this->record($this->fallback, 'anthropic', false, $this->elapsedMs($started), $exception);

            throw $exception;
        }
    }

    public function isAvailable(): bool
    {
        return $this->primary->isAvailable() || $this->fallback->isAvailable();
    }

    public function name(): string
    {
        return $this->reason === null ? $this->primary->name() : $this->fallback->name();
    }

    public function fallbackNotice(): ?string
    {
        if ($this->reason === null) {
            return null;
        }

        return sprintf(
            '%s no atendió: %s Lo que queda de esta ejecución lo hace %s.',
            $this->primary->name(),
            rtrim($this->reason, '.').'.',
            $this->fallback->name(),
        );
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private function generateOn(
        JsonLlm $client,
        string $systemInstruction,
        string $userPrompt,
        array $schema,
        LlmTask $task,
        float $temperature,
        ?int $maxTokensOverride,
    ): array {
        try {
            return $client->generateJson(
                $systemInstruction,
                $userPrompt,
                $schema,
                $task,
                $temperature,
                $maxTokensOverride,
            );
        } catch (LlmTruncatedException $exception) {
            $retryBudget = min($exception->budget * 2, $this->truncationRetryCap);

            $this->logger->warning('La generación se truncó; se reintenta con más presupuesto.', [
                'task' => $task->value,
                'original' => $exception->budget,
                'retry' => $retryBudget,
            ]);

            return $client->generateJson(
                $systemInstruction,
                $userPrompt,
                $schema,
                $task,
                $temperature,
                $retryBudget,
            );
        }
    }

    private function fallBack(string $reason): void
    {
        $this->reason = $reason;

        $this->logger->warning(sprintf(
            'El LLM principal (%s) no está disponible, se continúa con %s. Motivo: %s',
            $this->primary->name(),
            $this->fallback->name(),
            $reason,
        ));
    }

    private function elapsedMs(int $started): int
    {
        return (int) round((hrtime(true) - $started) / 1_000_000);
    }

    private function record(
        JsonLlm $client,
        string $provider,
        bool $reachable,
        ?int $latencyMs,
        ?LlmUnavailableException $exception,
    ): void {
        try {
            $this->store->put([
                $provider => [
                    'name' => $client->name(),
                    'configured' => $client->isAvailable(),
                    'reachable' => $reachable,
                    'latencyMs' => $latencyMs,
                    'error' => $exception?->getMessage(),
                    'errorClass' => $exception !== null ? class_basename($exception) : null,
                    'hint' => $exception !== null ? $this->health->hintFor($exception->getMessage()) : null,
                    'measuredBy' => 'pipeline',
                ],
            ], $provider);
        } catch (Throwable $e) {
            $this->logger->warning('No se pudo guardar la salud del LLM: '.$e->getMessage());
        }
    }
}
