<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Audio\AudioLibrary;
use Illuminate\Console\Command;

final class ListAudioCommand extends Command
{
    protected $signature = 'audio:list {--type= : ambience, sfx o music} {--tag= : Filtra por tag}';

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

        $clips = $this->library->filter(
            $type !== '' ? $type : null,
            $tag !== '' ? $tag : null,
        );

        if ($clips === []) {
            $this->warn('La librería no tiene clips que coincidan.');

            return self::SUCCESS;
        }

        $this->table(
            ['Fichero', 'Tipo', 'Duración', 'LUFS', 'Loop', 'Licencia', 'Autor'],
            array_map(static fn (array $clip): array => [
                (string) ($clip['file'] ?? ''),
                (string) ($clip['type'] ?? ''),
                sprintf('%.2fs', (float) ($clip['duration'] ?? 0)),
                sprintf('%.1f', (float) ($clip['lufs'] ?? 0)),
                ($clip['loopable'] ?? false) ? 'sí' : 'no',
                (string) ($clip['license'] ?? ''),
                (string) ($clip['author'] ?? ''),
            ], $clips),
        );

        $this->info(count($clips).' clip'.(count($clips) === 1 ? '' : 's').'.');

        return self::SUCCESS;
    }
}
