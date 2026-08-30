<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Pipeline\QueueHealth;
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
        $this->assertNull($status['oldestPendingSeconds']);
        $this->assertSame(0, $status['failed']);
        $this->assertFalse($status['likelyNoWorker']);
    }

    public function test_a_stale_unreserved_job_means_no_worker(): void
    {
        $this->useDatabaseQueue();

        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => '{}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->getTimestamp() - 20,
            'created_at' => now()->getTimestamp() - 20,
        ]);

        $status = $this->health()->status();

        $this->assertSame(1, $status['pending']);
        $this->assertGreaterThan(15, $status['oldestPendingSeconds']);
        $this->assertTrue($status['likelyNoWorker']);
    }

    public function test_a_fresh_job_is_not_stale(): void
    {
        $this->useDatabaseQueue();

        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => '{}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->getTimestamp(),
            'created_at' => now()->getTimestamp(),
        ]);

        $status = $this->health()->status();

        $this->assertSame(1, $status['pending']);
        $this->assertFalse($status['likelyNoWorker']);
    }

    public function test_a_reserved_job_is_not_a_missing_worker(): void
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

        $this->assertSame(1, $status['pending']);
        $this->assertNull($status['oldestPendingSeconds']);
        $this->assertFalse($status['likelyNoWorker']);
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
