<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Story\StoryImporter;
use Illuminate\Console\Command;

final class ImportStoriesCommand extends Command
{
    private const TITLE_COLUMN = 50;

    protected $signature = 'stories:import
        {--dry-run : Imprime la tabla sin escribir nada en base de datos}';

    protected $description = 'Importa las historias que ya están en disco y aún no tienen fila, o actualiza sus métricas';

    public function __construct(
        private StoryImporter $importer,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('Modo simulación: no se escribirá nada en la base de datos.');
        }

        $result = $this->importer->import($dryRun);

        $this->table(
            ['Slug', 'Título', 'Modo', 'Estado', 'Acción'],
            array_map(
                fn (array $row): array => [
                    $row['slug'],
                    $this->truncatedTitle($row['title']),
                    $row['mode'],
                    $row['status'],
                    $row['action'],
                ],
                $result['rows'],
            ),
        );

        $this->info(sprintf(
            'Creadas: %d. Actualizadas: %d. Omitidas: %d.',
            $result['created'],
            $result['updated'],
            $result['omitted'],
        ));

        return self::SUCCESS;
    }

    private function truncatedTitle(string $title): string
    {
        return mb_strlen($title) <= self::TITLE_COLUMN
            ? $title
            : mb_substr($title, 0, self::TITLE_COLUMN);
    }
}
