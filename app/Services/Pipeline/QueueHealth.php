<?php

declare(strict_types=1);

namespace App\Services\Pipeline;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\DatabaseManager;

final class QueueHealth
{
    public function __construct(
        private readonly Repository $config,
        private readonly DatabaseManager $db,
    ) {}

    /**
     * @return array{pending: int, waiting: int, running: int, oldestWaitingSeconds: int|null, failed: int, likelyNoWorker: bool, workerBusy: bool}
     */
    public function status(): array
    {
        if ((string) $this->config->get('queue.default') !== 'database') {
            return [
                'pending' => 0,
                'waiting' => 0,
                'running' => 0,
                'oldestWaitingSeconds' => null,
                'failed' => 0,
                'likelyNoWorker' => false,
                'workerBusy' => false,
            ];
        }

        $jobsTable = (string) $this->config->get('queue.connections.database.table', 'jobs');
        $failedTable = (string) $this->config->get('queue.failed.table', 'failed_jobs');
        $staleAfter = (int) $this->config->get('stories.pipeline.stale_job_seconds');
        $jobsConnection = $this->db->connection(
            $this->config->get('queue.connections.database.connection'),
        );
        $failedConnection = $this->db->connection(
            $this->config->get('queue.failed.database'),
        );

        $waiting = (int) $jobsConnection->table($jobsTable)->whereNull('reserved_at')->count();
        $running = (int) $jobsConnection->table($jobsTable)->whereNotNull('reserved_at')->count();
        $oldestAvailableAt = $jobsConnection->table($jobsTable)
            ->whereNull('reserved_at')
            ->min('available_at');

        $oldestWaitingSeconds = $oldestAvailableAt === null
            ? null
            : max(0, now()->getTimestamp() - (int) $oldestAvailableAt);

        return [
            'pending' => $waiting + $running,
            'waiting' => $waiting,
            'running' => $running,
            'oldestWaitingSeconds' => $oldestWaitingSeconds,
            'failed' => (int) $failedConnection->table($failedTable)->count(),
            'likelyNoWorker' => $waiting > 0
                && $running === 0
                && $oldestWaitingSeconds !== null
                && $oldestWaitingSeconds > $staleAfter,
            'workerBusy' => $running > 0 && $waiting > 0,
        ];
    }
}
