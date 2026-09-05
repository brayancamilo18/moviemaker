<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Pipeline\QueueHealth;
use App\Services\Pipeline\WorkerHeartbeat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class QueueHealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_non_database_connection_reports_idle(): void
    {
        $status = $this->health()->status();

        $this->assertSame(0, $status['pending']);
        $this->assertSame(0, $status['waiting']);
        $this->assertSame(0, $status['running']);
        $this->assertNull($status['oldestWaitingSeconds']);
        $this->assertSame(0, $status['failed']);
        $this->assertFalse($status['likelyNoWorker']);
        $this->assertFalse($status['workerBusy']);
    }

    public function test_a_reserved_job_and_a_stale_waiter_means_the_worker_is_busy(): void
    {
        $this->useDatabaseQueue();

        DB::table('jobs')->insert([
            [
                'queue' => 'default',
                'payload' => '{}',
                'attempts' => 1,
                'reserved_at' => now()->getTimestamp(),
                'available_at' => now()->getTimestamp() - 60,
                'created_at' => now()->getTimestamp() - 60,
            ],
            [
                'queue' => 'default',
                'payload' => '{}',
                'attempts' => 0,
                'reserved_at' => null,
                'available_at' => now()->getTimestamp() - 60,
                'created_at' => now()->getTimestamp() - 60,
            ],
        ]);

        $status = $this->health()->status();

        $this->assertSame(2, $status['pending']);
        $this->assertSame(1, $status['waiting']);
        $this->assertSame(1, $status['running']);
        $this->assertFalse($status['likelyNoWorker']);
        $this->assertTrue($status['workerBusy']);
    }

    public function test_two_stale_unreserved_jobs_mean_no_worker(): void
    {
        $this->useDatabaseQueue();

        DB::table('jobs')->insert([
            [
                'queue' => 'default',
                'payload' => '{}',
                'attempts' => 0,
                'reserved_at' => null,
                'available_at' => now()->getTimestamp() - 60,
                'created_at' => now()->getTimestamp() - 60,
            ],
            [
                'queue' => 'default',
                'payload' => '{}',
                'attempts' => 0,
                'reserved_at' => null,
                'available_at' => now()->getTimestamp() - 10,
                'created_at' => now()->getTimestamp() - 10,
            ],
        ]);

        $status = $this->health()->status();

        $this->assertSame(2, $status['waiting']);
        $this->assertSame(0, $status['running']);
        $this->assertGreaterThan(15, $status['oldestWaitingSeconds']);
        $this->assertTrue($status['likelyNoWorker']);
        $this->assertFalse($status['workerBusy']);
    }

    public function test_a_fresh_waiting_job_is_within_the_margin(): void
    {
        $this->useDatabaseQueue();
        $this->workerIsAlive();

        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => '{}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->getTimestamp() - 5,
            'created_at' => now()->getTimestamp() - 5,
        ]);

        $status = $this->health()->status();

        $this->assertSame(1, $status['waiting']);
        $this->assertSame(0, $status['running']);
        $this->assertFalse($status['likelyNoWorker']);
        $this->assertFalse($status['workerBusy']);
    }

    public function test_an_empty_table_with_a_live_worker_is_idle(): void
    {
        $this->useDatabaseQueue();
        $this->workerIsAlive();

        $status = $this->health()->status();

        $this->assertSame(0, $status['pending']);
        $this->assertSame(0, $status['waiting']);
        $this->assertSame(0, $status['running']);
        $this->assertNull($status['oldestWaitingSeconds']);
        $this->assertFalse($status['likelyNoWorker']);
        $this->assertFalse($status['workerBusy']);
        $this->assertTrue($status['workerAlive']);
    }

    /**
     * Antes esto se daba por sano: sin nada esperando no había con qué delatar al worker
     * ausente, así que el panel decía que todo iba bien hasta que alguien encolaba algo y
     * se comía los quince segundos de margen. El latido lo dice de entrada.
     */
    public function test_an_empty_table_without_a_heartbeat_means_no_worker(): void
    {
        $this->useDatabaseQueue();

        $status = $this->health()->status();

        $this->assertSame(0, $status['waiting']);
        $this->assertTrue($status['likelyNoWorker']);
        $this->assertFalse($status['workerAlive']);
        $this->assertNull($status['workerSeenSeconds']);
    }

    /**
     * Un paso de imágenes se pasa media hora dentro del job y no da una sola vuelta al bucle,
     * así que deja de latir. Tener el trabajo reservado es la otra forma de estar vivo.
     */
    public function test_a_reserved_job_keeps_the_worker_alive_without_a_heartbeat(): void
    {
        $this->useDatabaseQueue();

        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => '{}',
            'attempts' => 1,
            'reserved_at' => now()->getTimestamp(),
            'available_at' => now()->getTimestamp() - 60,
            'created_at' => now()->getTimestamp() - 60,
        ]);

        $status = $this->health()->status();

        $this->assertSame(1, $status['running']);
        $this->assertTrue($status['workerAlive']);
        $this->assertFalse($status['likelyNoWorker']);
    }

    private function workerIsAlive(): void
    {
        $this->app->make(WorkerHeartbeat::class)->beat();
    }

    private function useDatabaseQueue(): void
    {
        $this->app->make('config')->set('queue.default', 'database');
    }

    private function health(): QueueHealth
    {
        return $this->app->make(QueueHealth::class);
    }
}
