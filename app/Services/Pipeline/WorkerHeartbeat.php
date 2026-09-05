<?php

declare(strict_types=1);

namespace App\Services\Pipeline;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Config\Repository as Config;

/**
 * Marca de vida del worker de la cola.
 *
 * Sin esto, la única señal de que nadie atiende la cola es que un trabajo lleve
 * demasiado tiempo esperando: el panel tarda `stale_job_seconds` en admitirlo y,
 * con la cola vacía, no lo admite nunca. El worker lo escribe en cada vuelta de
 * su bucle —también cuando está ocioso—, así que la ausencia de latido significa
 * exactamente que no hay worker, y se sabe antes de encolar nada.
 */
final class WorkerHeartbeat
{
    private readonly int $ttl;

    public function __construct(
        private Repository $cache,
        Config $config,
    ) {
        $this->ttl = (int) $config->get('stories.pipeline.worker_heartbeat_ttl');
    }

    public function beat(): void
    {
        $this->cache->put(self::key(), now()->getTimestamp(), $this->ttl);
    }

    /**
     * Segundos desde el último latido, o null si no hay ninguno vivo.
     */
    public function secondsSinceBeat(): ?int
    {
        $seen = $this->cache->get(self::key());

        if (! is_numeric($seen)) {
            return null;
        }

        return max(0, now()->getTimestamp() - (int) $seen);
    }

    public function alive(): bool
    {
        return $this->secondsSinceBeat() !== null;
    }

    private static function key(): string
    {
        return 'pipeline:worker-heartbeat';
    }
}
