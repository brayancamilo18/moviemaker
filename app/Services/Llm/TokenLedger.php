<?php

declare(strict_types=1);

namespace App\Services\Llm;

use Illuminate\Contracts\Config\Repository;

final class TokenLedger
{
    private const TOKENS_PER_MILLION = 1_000_000;

    private int $inputTokens = 0;

    private int $outputTokens = 0;

    private float $costUsd = 0.0;

    private int $calls = 0;

    public function __construct(
        private readonly Repository $config,
    ) {}

    public function record(
        string $provider,
        string $model,
        LlmTask $task,
        int $inputTokens,
        int $outputTokens,
    ): void {
        $rates = $this->rates($provider, $model);

        $this->inputTokens += $inputTokens;
        $this->outputTokens += $outputTokens;
        $this->costUsd += ($inputTokens / self::TOKENS_PER_MILLION) * $rates['input']
            + ($outputTokens / self::TOKENS_PER_MILLION) * $rates['output'];
        $this->calls++;
    }

    /**
     * @return array{inputTokens: int, outputTokens: int, costUsd: float, calls: int}
     */
    public function drain(): array
    {
        $snapshot = [
            'inputTokens' => $this->inputTokens,
            'outputTokens' => $this->outputTokens,
            'costUsd' => $this->costUsd,
            'calls' => $this->calls,
        ];

        $this->reset();

        return $snapshot;
    }

    public function reset(): void
    {
        $this->inputTokens = 0;
        $this->outputTokens = 0;
        $this->costUsd = 0.0;
        $this->calls = 0;
    }

    /**
     * @return array{input: float, output: float}
     */
    private function rates(string $provider, string $model): array
    {
        $pricing = $this->config->get('stories.llm.'.$provider.'.pricing', []);
        $row = is_array($pricing)
            ? ($pricing[$model] ?? $pricing['default'] ?? [])
            : [];

        return [
            'input' => (float) (is_array($row) ? ($row['input'] ?? 0.0) : 0.0),
            'output' => (float) (is_array($row) ? ($row['output'] ?? 0.0) : 0.0),
        ];
    }
}
