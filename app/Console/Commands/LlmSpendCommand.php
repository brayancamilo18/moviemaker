<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Llm\SpendReport;
use Illuminate\Console\Command;
use InvalidArgumentException;

final class LlmSpendCommand extends Command
{
    protected $signature = 'llm:spend {--month= : Mes a listar, en formato YYYY-MM}';

    protected $description = 'Imprime el gasto LLM del mes por historia y por paso, de mayor a menor';

    public function __construct(
        private readonly SpendReport $spend,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $month = $this->option('month');

        try {
            $report = $this->spend->breakdown(is_string($month) && $month !== '' ? $month : null);
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Gasto LLM de '.$report['month'].'.');

        $this->table(
            ['Historia', 'Paso', 'Llamadas', 'Entrada', 'Salida', 'USD', 'EUR'],
            array_map(
                fn (array $row): array => [
                    $row['title'],
                    $row['step'],
                    (string) $row['calls'],
                    number_format($row['inputTokens'], 0, ',', '.'),
                    number_format($row['outputTokens'], 0, ',', '.'),
                    $this->spend->formatUsd($row['costUsd']),
                    $this->spend->formatEuro($row['costUsd']),
                ],
                $report['rows'],
            ),
        );

        $totals = $report['totals'];

        $this->line(sprintf(
            'Total: %s · %d llamadas · %s entrada · %s salida · %s',
            $totals['euro'],
            $totals['calls'],
            number_format($totals['inputTokens'], 0, ',', '.'),
            number_format($totals['outputTokens'], 0, ',', '.'),
            $totals['usd'],
        ));

        return self::SUCCESS;
    }
}
