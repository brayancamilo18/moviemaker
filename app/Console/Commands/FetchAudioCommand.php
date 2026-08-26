<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Audio\SoundLibraryImporter;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

final class FetchAudioCommand extends Command
{
    protected $signature = 'audio:fetch
        {--type= : ambience, sfx o music}
        {--query= : Texto de búsqueda en Freesound}
        {--limit=5 : Máximo de resultados a considerar}
        {--dry-run : Busca y muestra la tabla sin descargar}
        {--yes : Descarga sin pedir confirmación}';

    protected $description = 'Busca clips en Freesound y los añade a la librería local';

    public function __construct(
        private SoundLibraryImporter $importer,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $type = strtolower(trim((string) $this->option('type')));
        $query = trim((string) $this->option('query'));
        $limit = max(1, (int) $this->option('limit'));
        $dryRun = (bool) $this->option('dry-run');
        $yes = (bool) $this->option('yes');

        if (! in_array($type, ['ambience', 'sfx', 'music'], true)) {
            $this->error("El tipo '{$type}' no es válido. Usa ambience, sfx o music.");

            return self::FAILURE;
        }

        if ($query === '') {
            $this->error('Indica --query.');

            return self::FAILURE;
        }

        try {
            $sounds = $this->importer->search($type, $query, $limit);
        } catch (InvalidArgumentException|Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($sounds === []) {
            $this->warn('No hay resultados con licencia CC0 o Attribution.');

            return self::SUCCESS;
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
            $this->info('Simulación: no se ha descargado nada.');

            return self::SUCCESS;
        }

        if (! $yes && ! $this->confirm('¿Descargar estos clips a la librería?', false)) {
            $this->warn('Cancelado.');

            return self::SUCCESS;
        }

        $added = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($sounds as $sound) {
            $result = $this->importer->ingest($sound, $type, SoundLibraryImporter::tagsFromQuery($query));

            match ($result['status']) {
                'added' => $added++,
                'skipped' => $skipped++,
                default => $failed++,
            };

            $label = $sound['name'];

            if ($result['status'] === 'added') {
                $this->line("<info>Añadido</info>  {$label}");
            } elseif ($result['status'] === 'skipped') {
                $this->line("<comment>Omitido</comment>  {$label}  ({$result['reason']})");
            } else {
                $this->line("<fg=red>Falló</>  {$label}  ({$result['reason']})");
            }
        }

        $this->newLine();
        $this->info("Añadidos: {$added}. Omitidos: {$skipped}. Fallidos: {$failed}.");

        return $failed > 0 && $added === 0 ? self::FAILURE : self::SUCCESS;
    }
}
