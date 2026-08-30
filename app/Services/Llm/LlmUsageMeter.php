<?php

declare(strict_types=1);

namespace App\Services\Llm;

use Illuminate\Contracts\Config\Repository;

final class LlmUsageMeter
{
    private const TOKENS_PER_MILLION = 1_000_000;

    private const COST_DECIMALS = 6;

    /**
     * @var list<array{provider: string, model: string, task: string, inputTokens: int, outputTokens: int, costUsd: float}>
     */
    private array $entries = [];

    public function __construct(
        private Repository $config,
    ) {}

    public function record(string $provider, string $model, LlmTask $task, int $inputTokens, int $outputTokens): void
    {
        $rates = $this->rates($provider, $model);
        $cost = ($inputTokens / self::TOKENS_PER_MILLION) * $rates['input']
            + ($outputTokens / self::TOKENS_PER_MILLION) * $rates['output'];

        $this->entries[] = [
            'provider' => $provider,
            'model' => $model,
            'task' => $task->value,
            'inputTokens' => $inputTokens,
            'outputTokens' => $outputTokens,
            'costUsd' => round($cost, self::COST_DECIMALS),
        ];
    }

    /**
     * @return array{inputTokens: int, outputTokens: int, costUsd: float, calls: int, byProvider: array<string, array{inputTokens: int, outputTokens: int, costUsd: float, calls: int}>}
     */
    public function summary(): array
    {
        $byProvider = [];
        $inputTokens = 0;
        $outputTokens = 0;
        $costUsd = 0.0;

        foreach ($this->entries as $entry) {
            $inputTokens += $entry['inputTokens'];
            $outputTokens += $entry['outputTokens'];
            $costUsd += $entry['costUsd'];

            $bucket = $byProvider[$entry['provider']] ?? [
                'inputTokens' => 0,
                'outputTokens' => 0,
                'costUsd' => 0.0,
                'calls' => 0,
            ];
            $bucket['inputTokens'] += $entry['inputTokens'];
            $bucket['outputTokens'] += $entry['outputTokens'];
            $bucket['costUsd'] += $entry['costUsd'];
            $bucket['calls']++;
            $byProvider[$entry['provider']] = $bucket;
        }

        foreach ($byProvider as $provider => $bucket) {
            $byProvider[$provider]['costUsd'] = round($bucket['costUsd'], self::COST_DECIMALS);
        }

        return [
            'inputTokens' => $inputTokens,
            'outputTokens' => $outputTokens,
            'costUsd' => round($costUsd, self::COST_DECIMALS),
            'calls' => count($this->entries),
            'byProvider' => $byProvider,
        ];
    }

    public function reset(): void
    {
        $this->entries = [];
    }

    /**
     * @return array{input: float, output: float}
     */
    private function rates(string $provider, string $model): array
    {
        /** @var array<string, array{input?: float|int, output?: float|int}> $pricing */
        $pricing = $this->config->get('stories.llm.pricing.'.$provider, []);
        $rates = $pricing[$model] ?? $pricing['default'] ?? [];

        return [
            'input' => (float) ($rates['input'] ?? 0.0),
            'output' => (float) ($rates['output'] ?? 0.0),
        ];
    }
}
