<?php

declare(strict_types=1);

namespace App\Services\Llm;

use App\Contracts\JsonLlm;
use App\Exceptions\LlmUnavailableException;
use Psr\Log\LoggerInterface;

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
    ): array {
        if ($this->reason === null && $this->primary->isAvailable()) {
            try {
                return $this->primary->generateJson($systemInstruction, $userPrompt, $schema, $task, $temperature);
            } catch (LlmUnavailableException $exception) {
                $this->fallBack($exception->getMessage());
            }
        }

        if ($this->reason === null) {
            $this->fallBack($this->primary->name().' no tiene credencial configurada.');
        }

        return $this->fallback->generateJson($systemInstruction, $userPrompt, $schema, $task, $temperature);
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
}
