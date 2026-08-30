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

final class AnthropicClient implements JsonLlm
{
    /** Temperatura máxima que acepta la API, frente al 2 que admite Gemini. */
    private const MAX_TEMPERATURE = 1.0;

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
        private string $version,
        private string $beta,
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
            'model' => $this->model($task),
            'max_tokens' => $this->tokenBudget($task),
            'temperature' => min($temperature, self::MAX_TEMPERATURE),
            'system' => $systemInstruction,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $userPrompt,
                ],
            ],
            'output_config' => [
                'format' => [
                    'type' => 'json_schema',
                    'schema' => AnthropicSchema::translate($schema),
                ],
            ],
        ]);

        /** @var array<string, mixed> $payload */
        $payload = $response->json() ?? [];

        $stopReason = $payload['stop_reason'] ?? null;

        if (in_array($stopReason, ['max_tokens', 'refusal'], true)) {
            throw new LlmGenerationException(sprintf(
                'La generación de Anthropic terminó de forma incompleta en la tarea %s. Motivo: %s. Tope usado: %d tokens de salida.',
                $task->value,
                $stopReason,
                $this->tokenBudget($task),
            ));
        }

        $usage = is_array($payload['usage'] ?? null) ? $payload['usage'] : [];
        $this->logger->debug('Anthropic respondió.', [
            'task' => $task->value,
            'input_tokens' => $usage['input_tokens'] ?? null,
            'output_tokens' => $usage['output_tokens'] ?? null,
            'max_tokens' => $this->tokenBudget($task),
        ]);

        $text = $this->text($payload);

        if ($text === null) {
            throw new LlmGenerationException('Anthropic no devolvió texto en la respuesta.');
        }

        try {
            $decoded = json_decode($text, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new LlmGenerationException(
                'Anthropic devolvió JSON inválido.',
                previous: $exception,
            );
        }

        if (! is_array($decoded)) {
            throw new LlmGenerationException('Anthropic no devolvió un objeto JSON.');
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
    private function text(array $payload): ?string
    {
        $blocks = is_array($payload['content'] ?? null) ? $payload['content'] : [];

        foreach ($blocks as $block) {
            if (! is_array($block) || ($block['type'] ?? '') !== 'text') {
                continue;
            }

            $text = $block['text'] ?? null;

            if (is_string($text) && $text !== '') {
                return $text;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function send(array $payload): Response
    {
        try {
            return $this->http
                ->timeout($this->timeout)
                ->acceptJson()
                ->asJson()
                ->withHeaders($this->headers())
                ->retry(
                    $this->retryBackoff(),
                    when: $this->shouldRetry(...),
                )
                ->post(rtrim($this->baseUrl, '/').'/messages', $payload);
        } catch (ConnectionException $exception) {
            throw new LlmUnavailableException(
                'No se pudo conectar con Anthropic: '.$exception->getMessage(),
                previous: $exception,
            );
        } catch (RequestException $exception) {
            throw $this->httpError($exception);
        }
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        $headers = [
            'x-api-key' => $this->apiKey,
            'anthropic-version' => $this->version,
        ];

        if ($this->beta !== '') {
            $headers['anthropic-beta'] = $this->beta;
        }

        return $headers;
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

        // 529 es el overloaded_error propio de Anthropic, que no existe en el resto de APIs.
        return in_array($exception->response->status(), [429, 500, 503, 529], true);
    }

    private function httpError(RequestException $exception): LlmGenerationException
    {
        $status = $exception->response->status();
        $detail = $exception->response->json('error.message') ?? $exception->response->body();

        return match ($status) {
            400 => new LlmGenerationException(
                "La petición a Anthropic es inválida (HTTP 400): {$detail}",
                previous: $exception,
            ),
            401, 403 => new LlmUnavailableException(
                "Anthropic rechazó la autenticación (HTTP {$status}). Revisa ANTHROPIC_API_KEY.",
                previous: $exception,
            ),
            402, 429 => new LlmUnavailableException(
                "Anthropic no atiende (HTTP {$status}): saturado o sin crédito.",
                previous: $exception,
            ),
            500, 503, 529 => new LlmUnavailableException(
                "Anthropic no está disponible (HTTP {$status}) tras varios reintentos.",
                previous: $exception,
            ),
            default => new LlmUnavailableException(
                "Anthropic respondió con HTTP {$status}: {$detail}",
                previous: $exception,
            ),
        };
    }
}
