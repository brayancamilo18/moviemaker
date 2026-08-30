<?php

declare(strict_types=1);

namespace App\Services\Llm;

use Illuminate\Contracts\Cache\Repository as CacheRepository;

final class ProviderHealthStore
{
    private const KEY = 'llm:health';

    private const TTL_MINUTES = 60;

    public function __construct(
        private readonly CacheRepository $cache,
    ) {}

    /**
     * @param  array<string, mixed>  $report
     */
    public function put(array $report, ?string $onlyProvider = null, string $measuredBy = 'cli'): void
    {
        if ($onlyProvider !== null) {
            $entry = $report[$onlyProvider] ?? null;

            if (! is_array($entry)) {
                return;
            }

            $merged = $this->existingReport();
            $merged[$onlyProvider] = $entry;
            $report = $merged;
            $measuredBy = $measuredBy === 'cli' ? 'pipeline' : $measuredBy;
        }

        $this->cache->put(self::KEY, [
            'report' => $report,
            'measuredAt' => now()->toIso8601String(),
            'measuredBy' => $measuredBy,
        ], now()->addMinutes(self::TTL_MINUTES));
    }

    /**
     * @return array{report: array<string, mixed>, measuredAt: string, ageSeconds: int, measuredBy: string}|null
     */
    public function get(): ?array
    {
        $value = $this->cache->get(self::KEY);

        if (! is_array($value) || ! isset($value['report'], $value['measuredAt']) || ! is_array($value['report'])) {
            return null;
        }

        $measuredAt = (string) $value['measuredAt'];
        $timestamp = strtotime($measuredAt);

        if ($timestamp === false) {
            return null;
        }

        return [
            'report' => $value['report'],
            'measuredAt' => $measuredAt,
            'ageSeconds' => max(0, now()->getTimestamp() - $timestamp),
            'measuredBy' => (string) ($value['measuredBy'] ?? 'cli'),
        ];
    }

    public function forget(): void
    {
        $this->cache->forget(self::KEY);
    }

    /**
     * @return array<string, mixed>
     */
    private function existingReport(): array
    {
        $value = $this->cache->get(self::KEY);

        if (! is_array($value) || ! isset($value['report']) || ! is_array($value['report'])) {
            return [];
        }

        return $value['report'];
    }
}
