<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Llm\ProviderHealth;
use Illuminate\Console\Command;

final class LlmHealthCommand extends Command
{
    protected $signature = 'llm:health {--live : Llama a cada proveedor para comprobar que responde}';

    protected $description = 'Comprueba si Gemini y Anthropic están configurados y, con --live, si responden';

    public function __construct(
        private ProviderHealth $health,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $report = $this->health->check((bool) $this->option('live'));
        $rows = [];

        foreach ($report as $provider => $status) {
            $rows[] = [
                $provider,
                $status['name'],
                $status['configured'] ? 'sí' : 'no',
                $this->reachableCell($status['reachable']),
                $status['latencyMs'] === null ? '—' : $status['latencyMs'].' ms',
                $status['error'] ?? '',
            ];
        }

        $this->table(
            ['Proveedor', 'Modelo', 'Configurado', 'Alcanzable', 'Latencia', 'Error'],
            $rows,
        );

        return self::SUCCESS;
    }

    private function reachableCell(?bool $reachable): string
    {
        return match ($reachable) {
            true => 'sí',
            false => 'no',
            null => '—',
        };
    }
}
