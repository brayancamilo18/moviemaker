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
     * @return array{pending: int, oldestPendingSeconds: int|null, failed: int, likelyNoWorker: bool}
     */
    public function status(): array
    {
        if ((string) $this->config->get('queue.default') !== 'database') {
            return [
                'pending' => 0,
                'oldestPendingSeconds' => null,
                'failed' => 0,
                'likelyNoWorker' => false,
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

        $pending = (int) $jobsConnection->table($jobsTable)->count();
        // reserved_at relleno = un worker ya lo tiene; no cuenta como espera.
        $oldestAvailableAt = $jobsConnection->table($jobsTable)
            ->whereNull('reserved_at')
            ->min('available_at');
        $waiting = (int) $jobsConnection->table($jobsTable)->whereNull('reserved_at')->count();

        $oldestPendingSeconds = $oldestAvailableAt === null
            ? null
            : max(0, now()->getTimestamp() - (int) $oldestAvailableAt);

        return [
            'pending' => $pending,
            'oldestPendingSeconds' => $oldestPendingSeconds,
            'failed' => (int) $failedConnection->table($failedTable)->count(),
            'likelyNoWorker' => $waiting >= 1
                && $oldestPendingSeconds !== null
                && $oldestPendingSeconds > $staleAfter,
        ];
    }
}
