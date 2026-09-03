<?php

declare(strict_types=1);

namespace App\Services\Pipeline;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Config\Repository as Config;

final class PipelineProgress
{
    private readonly int $ttl;

    public function __construct(
        private Repository $cache,
        Config $config,
    ) {
        $this->ttl = (int) $config->get('stories.pipeline.progress_ttl');
    }

    public function put(
        int $storyId,
        string $step,
        string $label,
        int $done,
        int $total,
        ?string $stage = null,
        bool $queued = false,
    ): void {
        $this->cache->put($this->key($storyId), [
            'step' => $step,
            'label' => $label,
            'done' => $done,
            'total' => $total,
            'stage' => $stage,
            'queued' => $queued,
            'started_at' => $queued ? null : $this->startedAt($storyId, $step),
        ], $this->ttl);
    }

    /**
     * The moment a worker picked the step up, kept across every later tick of the
     * same step so the panel can time the work instead of the wait before it.
     */
    private function startedAt(int $storyId, string $step): int
    {
        $current = $this->get($storyId);

        if ($current !== null && ! $current['queued'] && $current['step'] === $step && $current['started_at'] !== null) {
            return $current['started_at'];
        }

        return now()->getTimestamp();
    }

    /**
     * @return array{step: string, label: string, done: int, total: int, stage: string|null, queued: bool, started_at: int|null}|null
     */
    public function get(int $storyId): ?array
    {
        $value = $this->cache->get($this->key($storyId));

        if (! is_array($value) || ! isset($value['step'], $value['label'], $value['done'], $value['total'])) {
            return null;
        }

        $stage = $value['stage'] ?? null;

        return [
            'step' => (string) $value['step'],
            'label' => (string) $value['label'],
            'done' => (int) $value['done'],
            'total' => (int) $value['total'],
            'stage' => is_string($stage) && $stage !== '' ? $stage : null,
            'queued' => (bool) ($value['queued'] ?? false),
            'started_at' => is_numeric($value['started_at'] ?? null) ? (int) $value['started_at'] : null,
        ];
    }

    public function clear(int $storyId): void
    {
        $this->cache->forget($this->key($storyId));
    }

    private function key(int $storyId): string
    {
        return 'pipeline:'.$storyId;
    }
}
