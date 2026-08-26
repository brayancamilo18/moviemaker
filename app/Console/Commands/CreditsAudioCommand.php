<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Audio\AudioLibrary;
use Illuminate\Console\Command;

final class CreditsAudioCommand extends Command
{
    protected $signature = 'audio:credits';

    protected $description = 'Genera el bloque de atribución para clips CC BY de la librería';

    public function __construct(
        private AudioLibrary $library,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $clips = $this->library->attributionClips();

        if ($clips === []) {
            $this->warn('No hay clips con attribution_required en la librería.');

            return self::SUCCESS;
        }

        $this->line('Sound credits:');

        foreach ($clips as $clip) {
            $name = (string) ($clip['file'] ?? '');
            $author = (string) ($clip['author'] ?? '');
            $url = (string) ($clip['source_url'] ?? '');
            $license = (string) ($clip['license'] ?? '');

            $this->line(sprintf(
                '"%s" by %s — %s — %s',
                $name,
                $author,
                $url,
                $license,
            ));
        }

        $this->newLine();
        $this->comment('Si attribution_required es true y el clip entra en un vídeo, este crédito es obligatorio.');

        return self::SUCCESS;
    }
}
