<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Services\Llm\LlmTask;

interface JsonLlm
{
    /**
     * Genera un objeto JSON que cumple el schema. El schema se escribe en el dialecto de Gemini
     * (tipos en mayúsculas) y cada implementación lo traduce al suyo.
     *
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
    ): array;

    public function isAvailable(): bool;

    /**
     * Modelo que atiende ahora mismo, para que los comandos digan quién escribió qué.
     */
    public function name(): string;

    /**
     * Por qué se dejó de usar el proveedor principal, o null si nunca se cambió. Los comandos lo
     * presentan; el motivo con detalle de HTTP va al log.
     */
    public function fallbackNotice(): ?string;
}
