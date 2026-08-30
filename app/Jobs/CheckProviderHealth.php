<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Llm\ProviderHealth;
use App\Services\Llm\ProviderHealthStore;
use Illuminate\Container\Container;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

final class CheckProviderHealth implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct() {}

    public function handle(ProviderHealth $health, ProviderHealthStore $store): void
    {
        $store->put($health->check(live: true), measuredBy: 'worker');
    }

    public function failed(?Throwable $e): void
    {
        $message = $e?->getMessage() ?? '';

        Container::getInstance()->make(ProviderHealthStore::class)->put([
            'gemini' => $this->unreachable('gemini', $message, $e),
            'anthropic' => $this->unreachable('anthropic', $message, $e),
        ], measuredBy: 'worker');
    }

    /**
     * @return array{name: string, configured: bool, reachable: false, latencyMs: null, error: string|null, errorClass: string|null, hint: null}
     */
    private function unreachable(string $name, string $message, ?Throwable $e): array
    {
        return [
            'name' => $name,
            'configured' => true,
            'reachable' => false,
            'latencyMs' => null,
            'error' => $message !== '' ? $message : null,
            'errorClass' => $e !== null ? class_basename($e) : null,
            'hint' => null,
        ];
    }
}
