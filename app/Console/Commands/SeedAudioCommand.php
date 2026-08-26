<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Audio\SoundLibraryImporter;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Throwable;

final class SeedAudioCommand extends Command
{
    protected $signature = 'audio:seed {--dry-run : Lista las búsquedas y resultados sin descargar}';

    protected $description = 'Puebla la librería con las búsquedas predefinidas de ambiente y efectos';

    private const PER_QUERY = 5;

    /**
     * @var array<string, list<string>>
     */
    private readonly array $queries;

    public function __construct(
        private SoundLibraryImporter $importer,
        Repository $config,
    ) {
        parent::__construct();

        /** @var array<string, list<string>> $seed */
        $seed = $config->get('stories.audio.seed', []);
        $this->queries = $seed;
    }

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $added = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($this->queries as $type => $queries) {
            if (! in_array($type, ['ambience', 'sfx', 'music'], true) || ! is_array($queries)) {
                continue;
            }

            foreach ($queries as $query) {
                if (! is_string($query) || trim($query) === '') {
                    continue;
                }

                $this->info("[{$type}] {$query}");

                try {
                    $sounds = $this->importer->search($type, $query, self::PER_QUERY);
                } catch (Throwable $exception) {
                    $this->error($exception->getMessage());
                    $failed++;

                    continue;
                }

                if ($sounds === []) {
                    $this->warn('  Sin resultados CC0/Attribution.');

                    continue;
                }

                $this->table(
                    ['Nombre', 'Autor', 'Licencia', 'Duración', 'Valoración'],
                    array_map(static fn (array $sound): array => [
                        $sound['name'],
                        $sound['author'],
                        $sound['license'],
                        sprintf('%.1fs', $sound['duration']),
                        sprintf('%.1f', $sound['rating']),
                    ], $sounds),
                );

                if ($dryRun) {
                    continue;
                }

                foreach ($sounds as $sound) {
                    $result = $this->importer->ingest($sound, $type, SoundLibraryImporter::tagsFromQuery($query));

                    match ($result['status']) {
                        'added' => $added++,
                        'skipped' => $skipped++,
                        default => $failed++,
                    };

                    if ($result['status'] !== 'added') {
                        $this->line('  '.$result['status'].': '.$sound['name'].' ('.($result['reason'] ?? '').')');
                    }
                }
            }
        }

        if ($dryRun) {
            $this->info('Simulación: no se ha descargado nada.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->info("Añadidos: {$added}. Omitidos: {$skipped}. Fallidos: {$failed}.");

        return $failed > 0 && $added === 0 ? self::FAILURE : self::SUCCESS;
    }
}
