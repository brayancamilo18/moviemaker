<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Audio\AudioLibrary;
use Illuminate\Console\Command;

final class ListAudioCommand extends Command
{
    protected $signature = 'audio:list
        {--type= : ambience, sfx o music}
        {--tag= : Filtra por tag}
        {--prune : Quita del índice local los clips cuyo fichero ya no está en disco}';

    protected $description = 'Lista los clips indexados en la librería de sonido';

    public function __construct(
        private AudioLibrary $library,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $type = trim((string) $this->option('type'));
        $tag = trim((string) $this->option('tag'));

        if ($type !== '' && ! in_array($type, ['ambience', 'sfx', 'music'], true)) {
            $this->error("El tipo '{$type}' no es válido. Usa ambience, sfx o music.");

            return self::FAILURE;
        }

        if ((bool) $this->option('prune')) {
            $this->prune();
        }

        $clips = $this->library->filter(
            $type !== '' ? $type : null,
            $tag !== '' ? $tag : null,
            includeMissing: true,
        );

        if ($clips === []) {
            $this->warn('La librería no tiene clips que coincidan.');

            return self::SUCCESS;
        }

        $missing = 0;
        $rows = [];

        foreach ($clips as $clip) {
            $onDisk = $this->library->fileExists((string) ($clip['file'] ?? ''));

            if (! $onDisk) {
                $missing++;
            }

            $rows[] = [
                (string) ($clip['file'] ?? ''),
                (string) ($clip['type'] ?? ''),
                sprintf('%.2fs', (float) ($clip['duration'] ?? 0)),
                sprintf('%.1f', (float) ($clip['lufs'] ?? 0)),
                ($clip['loopable'] ?? false) ? 'sí' : 'no',
                (string) ($clip['license'] ?? ''),
                (string) ($clip['author'] ?? ''),
                $onDisk ? 'sí' : 'FALTA',
            ];
        }

        $this->table(
            ['Fichero', 'Tipo', 'Duración', 'LUFS', 'Loop', 'Licencia', 'Autor', 'En disco'],
            $rows,
        );

        $this->info(count($clips).' clip'.(count($clips) === 1 ? '' : 's').'.');

        if ($missing > 0) {
            $this->warn($missing.' clip'.($missing === 1 ? '' : 's').' indexado'.($missing === 1 ? '' : 's').' sin fichero en disco; la resolución los ignora.');

            if (! (bool) $this->option('prune')) {
                $this->line('Quítalos del índice local con: php artisan audio:list --prune');
            }
        }

        return self::SUCCESS;
    }

    private function prune(): void
    {
        $removed = $this->library->prune();

        if ($removed === 0) {
            $this->info('El índice local no tenía clips que purgar.');
        } else {
            $this->info($removed.' clip'.($removed === 1 ? '' : 's').' sin fichero purgado'.($removed === 1 ? '' : 's').' del índice local.');
        }

        $core = $this->library->missingCoreClips();

        if ($core !== []) {
            $this->warn(count($core).' clip'.(count($core) === 1 ? '' : 's').' del core kit falta'.(count($core) === 1 ? '' : 'n').' en disco y no se purgan.');
            $this->line('Recupéralos con: php artisan audio:core-kit');
        }
    }
}
