<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Llm\ProviderHealth;
use App\Services\Llm\ProviderHealthStore;
use Illuminate\Console\Command;
use InvalidArgumentException;

final class LlmHealthCommand extends Command
{
    protected $signature = 'llm:health
        {--live : Llama a cada proveedor para comprobar que responde}
        {--only= : Solo gemini o anthropic}';

    protected $description = 'Comprueba si Gemini y Anthropic están configurados y, con --live, si responden';

    public function __construct(
        private ProviderHealth $health,
        private ProviderHealthStore $store,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $live = (bool) $this->option('live');
        $only = strtolower(trim((string) $this->option('only')));

        try {
            $report = $only === ''
                ? $this->health->check($live)
                : [$only => $this->health->checkOne($only, $live)];
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $rows = [];
        $hints = [];

        foreach ($report as $provider => $status) {
            $rows[] = [
                $provider,
                $status['name'],
                $status['configured'] ? 'sí' : 'no',
                $this->reachableCell($status['reachable']),
                $status['latencyMs'] === null ? '—' : $status['latencyMs'].' ms',
                $status['error'] ?? '',
                $status['errorClass'] ?? '',
                $status['hint'] ?? '',
            ];

            if (is_string($status['hint'] ?? null) && $status['hint'] !== '') {
                $hints[] = $provider.': '.$status['hint'];
            }
        }

        $this->table(
            ['Proveedor', 'Modelo', 'Configurado', 'Alcanzable', 'Latencia', 'Error', 'Clase', 'Pista'],
            $rows,
        );

        foreach ($hints as $hint) {
            $this->warn($hint);
        }

        $this->store->put($report, measuredBy: 'cli');

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
