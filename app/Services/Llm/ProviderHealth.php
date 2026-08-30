<?php

declare(strict_types=1);

namespace App\Services\Llm;

use InvalidArgumentException;
use Throwable;

final class ProviderHealth
{
    /**
     * @var array<string, mixed>
     */
    private const PING_SCHEMA = [
        'type' => 'OBJECT',
        'properties' => [
            'reply' => [
                'type' => 'STRING',
                'description' => 'The word ok.',
            ],
        ],
        'required' => ['reply'],
    ];

    public function __construct(
        private readonly GeminiClient $gemini,
        private readonly AnthropicClient $anthropic,
    ) {}

    /**
     * @return array{gemini: array{name: string, configured: bool, reachable: bool|null, latencyMs: int|null, error: string|null, errorClass: string|null, hint: string|null}, anthropic: array{name: string, configured: bool, reachable: bool|null, latencyMs: int|null, error: string|null, errorClass: string|null, hint: string|null}}
     */
    public function check(bool $live = false): array
    {
        return [
            'gemini' => $this->probe($this->gemini, $live),
            'anthropic' => $this->probe($this->anthropic, $live),
        ];
    }

    /**
     * @return array{name: string, configured: bool, reachable: bool|null, latencyMs: int|null, error: string|null, errorClass: string|null, hint: string|null}
     */
    public function checkOne(string $provider, bool $live): array
    {
        $client = match (strtolower(trim($provider))) {
            'gemini' => $this->gemini,
            'anthropic' => $this->anthropic,
            default => throw new InvalidArgumentException(
                "Proveedor desconocido: {$provider}. Usa gemini o anthropic.",
            ),
        };

        return $this->probe($client, $live);
    }

    /**
     * @return array{name: string, configured: bool, reachable: bool|null, latencyMs: int|null, error: string|null, errorClass: string|null, hint: string|null}
     */
    private function probe(GeminiClient|AnthropicClient $client, bool $live): array
    {
        $configured = $client->isAvailable();
        $report = [
            'name' => $client->name(),
            'configured' => $configured,
            'reachable' => null,
            'latencyMs' => null,
            'error' => null,
            'errorClass' => null,
            'hint' => null,
        ];

        if (! $live) {
            return $report;
        }

        if (! $configured) {
            $report['reachable'] = false;

            return $report;
        }

        $started = hrtime(true);

        try {
            $client->generateJson(
                'You reply with JSON only.',
                'Reply with the word ok.',
                self::PING_SCHEMA,
                LlmTask::Script,
            );
            $report['reachable'] = true;
        } catch (Throwable $exception) {
            $message = $exception->getMessage();
            $report['reachable'] = false;
            $report['error'] = $message;
            $report['errorClass'] = class_basename($exception);
            $report['hint'] = $this->hint($message);
        }

        $report['latencyMs'] = (int) round((hrtime(true) - $started) / 1_000_000);

        return $report;
    }

    public function hintFor(string $message): ?string
    {
        return $this->hint($message);
    }

    private function hint(string $message): ?string
    {
        $haystack = strtolower($message);

        if (str_contains($haystack, '429') || str_contains($haystack, 'saturado')) {
            return 'Cuota diaria de Gemini agotada. Se renueva a medianoche del Pacífico, las 9:00 en España.';
        }

        if (str_contains($haystack, 'could not resolve host')) {
            return 'Sin DNS. Comprueba la conexión de red de la máquina.';
        }

        if (str_contains($haystack, 'ssl certificate') || str_contains($haystack, 'certificate verify')) {
            return 'Problema de certificados TLS en PHP. Revisa curl.cainfo en php.ini.';
        }

        if (str_contains($haystack, 'timed out') || str_contains($haystack, 'timeout')) {
            return 'La petición agotó el tiempo. Puede ser un cortafuegos o un proxy.';
        }

        if (str_contains($haystack, 'connection refused')) {
            return 'Conexión rechazada. Suele ser un proxy local o una VPN.';
        }

        return null;
    }
}
