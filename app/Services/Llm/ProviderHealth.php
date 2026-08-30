<?php

declare(strict_types=1);

namespace App\Services\Llm;

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
     * @return array{gemini: array{name: string, configured: bool, reachable: bool|null, latencyMs: int|null, error: string|null}, anthropic: array{name: string, configured: bool, reachable: bool|null, latencyMs: int|null, error: string|null}}
     */
    public function check(bool $live = false): array
    {
        return [
            'gemini' => $this->probe($this->gemini, $live),
            'anthropic' => $this->probe($this->anthropic, $live),
        ];
    }

    /**
     * @return array{name: string, configured: bool, reachable: bool|null, latencyMs: int|null, error: string|null}
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
            $report['reachable'] = false;
            $report['error'] = $exception->getMessage();
        }

        $report['latencyMs'] = (int) round((hrtime(true) - $started) / 1_000_000);

        return $report;
    }
}
