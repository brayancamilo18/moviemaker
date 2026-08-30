<?php

declare(strict_types=1);

namespace App\Services\Llm;

use App\Contracts\JsonLlm;
use App\Exceptions\LlmGenerationException;
use App\Exceptions\LlmUnavailableException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use JsonException;
use Psr\Log\LoggerInterface;
use Throwable;

final class GeminiClient implements JsonLlm
{
    /**
     * @param  array<string, string>  $models  modelo por tarea, con 'default' como respaldo
     * @param  array<string, int>  $maxTokens  tope de salida por tarea, con 'default' como respaldo
     */
    public function __construct(
        private Factory $http,
        private string $apiKey,
        private array $models,
        private array $maxTokens,
        private string $baseUrl,
        private int $timeout,
        private int $maxRetries,
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
        $response = $this->send([
            'systemInstruction' => [
                'parts' => [
                    ['text' => $systemInstruction],
                ],
            ],
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [
                        ['text' => $userPrompt],
                    ],
                ],
            ],
            'generationConfig' => [
                'temperature' => $temperature,
                'maxOutputTokens' => $this->tokenBudget($task),
                'responseMimeType' => 'application/json',
                'responseSchema' => $schema,
            ],
        ], $task);

        /** @var array<string, mixed> $payload */
        $payload = $response->json() ?? [];

        $finishReason = $payload['candidates'][0]['finishReason'] ?? null;

        if (in_array($finishReason, ['MAX_TOKENS', 'SAFETY'], true)) {
            throw new LlmGenerationException(sprintf(
                'La generación de Gemini terminó de forma incompleta en la tarea %s. Motivo: %s. Tope usado: %d tokens de salida.',
                $task->value,
                $finishReason,
                $this->tokenBudget($task),
            ));
        }

        $usage = is_array($payload['usageMetadata'] ?? null) ? $payload['usageMetadata'] : [];
        $this->logger->debug('Gemini respondió.', [
            'task' => $task->value,
            'input_tokens' => $usage['promptTokenCount'] ?? null,
            'output_tokens' => $usage['candidatesTokenCount'] ?? null,
            'max_tokens' => $this->tokenBudget($task),
        ]);

        $text = $payload['candidates'][0]['content']['parts'][0]['text'] ?? null;

        if (! is_string($text) || $text === '') {
            throw new LlmGenerationException('Gemini no devolvió texto en la respuesta.');
        }

        try {
            $decoded = json_decode($text, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new LlmGenerationException(
                'Gemini devolvió JSON inválido.',
                previous: $exception,
            );
        }

        if (! is_array($decoded)) {
            throw new LlmGenerationException('Gemini no devolvió un objeto JSON.');
        }

        return $decoded;
    }

    public function isAvailable(): bool
    {
        return trim($this->apiKey) !== '';
    }

    public function name(): string
    {
        return $this->models['default'];
    }

    public function fallbackNotice(): ?string
    {
        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function send(array $payload, LlmTask $task): Response
    {
        try {
            return $this->http
                ->timeout($this->timeout)
                ->acceptJson()
                ->asJson()
                ->retry(
                    $this->retryBackoff(),
                    when: $this->shouldRetry(...),
                )
                ->withQueryParameters(['key' => $this->apiKey])
                ->post($this->endpoint($task), $payload);
        } catch (ConnectionException $exception) {
            throw new LlmUnavailableException(
                'No se pudo conectar con Gemini: '.$exception->getMessage(),
                previous: $exception,
            );
        } catch (RequestException $exception) {
            throw $this->httpError($exception);
        }
    }

    private function endpoint(LlmTask $task): string
    {
        return sprintf(
            '%s/models/%s:generateContent',
            rtrim($this->baseUrl, '/'),
            $this->model($task),
        );
    }

    private function model(LlmTask $task): string
    {
        return $this->models[$task->value] ?? $this->models['default'];
    }

    private function tokenBudget(LlmTask $task): int
    {
        return $this->maxTokens[$task->value] ?? $this->maxTokens['default'];
    }

    /**
     * @return int|list<int>
     */
    private function retryBackoff(): int|array
    {
        if ($this->maxRetries < 1) {
            return 1;
        }

        return array_map(
            static fn (int $attempt): int => 1000 * (2 ** $attempt),
            range(0, $this->maxRetries - 1),
        );
    }

    private function shouldRetry(Throwable $exception): bool
    {
        if (! $exception instanceof RequestException) {
            return false;
        }

        return in_array($exception->response->status(), [429, 500, 503], true);
    }

    /**
     * Separa «este proveedor no está disponible», que justifica ir al respaldo, de «ha contestado
     * y la respuesta no sirve», que es cosa nuestra y en otro proveedor fallaría igual.
     */
    private function httpError(RequestException $exception): LlmGenerationException
    {
        $status = $exception->response->status();
        $detail = $exception->response->json('error.message') ?? $exception->response->body();

        return match ($status) {
            400 => new LlmGenerationException(
                "La petición a Gemini es inválida (HTTP 400): {$detail}",
                previous: $exception,
            ),
            403 => new LlmUnavailableException(
                'Gemini rechazó la autenticación (HTTP 403). Revisa la clave API.',
                previous: $exception,
            ),
            429 => new LlmUnavailableException(
                'Gemini está saturado (HTTP 429) tras varios reintentos.',
                previous: $exception,
            ),
            500, 503 => new LlmUnavailableException(
                "Gemini no está disponible (HTTP {$status}) tras varios reintentos.",
                previous: $exception,
            ),
            default => new LlmUnavailableException(
                "Gemini respondió con HTTP {$status}: {$detail}",
                previous: $exception,
            ),
        };
    }
}
