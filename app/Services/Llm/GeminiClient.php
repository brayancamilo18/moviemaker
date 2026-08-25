<?php

declare(strict_types=1);

namespace App\Services\Llm;

use App\Exceptions\LlmGenerationException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use JsonException;
use Throwable;

final class GeminiClient
{
    public function __construct(
        private Factory $http,
        private string $apiKey,
        private string $model,
        private string $baseUrl,
        private int $timeout,
        private int $maxRetries,
    ) {}

    /**
     * @param  array<string, mixed>  $responseSchema
     * @return array<string, mixed>
     */
    public function generateJson(
        string $systemInstruction,
        string $userPrompt,
        array $responseSchema,
        float $temperature = 1.0,
        ?string $model = null,
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
                'responseMimeType' => 'application/json',
                'responseSchema' => $responseSchema,
            ],
        ], $model);

        /** @var array<string, mixed> $payload */
        $payload = $response->json() ?? [];

        $finishReason = $payload['candidates'][0]['finishReason'] ?? null;

        if (in_array($finishReason, ['MAX_TOKENS', 'SAFETY'], true)) {
            throw new LlmGenerationException(
                "La generación de Gemini terminó de forma incompleta. Motivo: {$finishReason}.",
            );
        }

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

    /**
     * @param  array<string, mixed>  $payload
     */
    private function send(array $payload, ?string $model = null): Response
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
                ->post($this->endpoint($model), $payload);
        } catch (ConnectionException $exception) {
            throw new LlmGenerationException(
                'No se pudo conectar con Gemini.',
                previous: $exception,
            );
        } catch (RequestException $exception) {
            throw new LlmGenerationException(
                $this->httpErrorMessage($exception),
                previous: $exception,
            );
        }
    }

    private function endpoint(?string $model = null): string
    {
        return sprintf(
            '%s/models/%s:generateContent',
            rtrim($this->baseUrl, '/'),
            $model ?? $this->model,
        );
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

    private function httpErrorMessage(RequestException $exception): string
    {
        $status = $exception->response->status();
        $detail = $exception->response->json('error.message') ?? $exception->response->body();

        return match ($status) {
            400 => "La petición a Gemini es inválida (HTTP 400): {$detail}",
            403 => 'Gemini rechazó la autenticación (HTTP 403). Revisa la clave API.',
            429 => 'Gemini está saturado (HTTP 429) tras varios reintentos.',
            500, 503 => "Gemini no está disponible (HTTP {$status}) tras varios reintentos.",
            default => "Gemini respondió con HTTP {$status}: {$detail}",
        };
    }
}
