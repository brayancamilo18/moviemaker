<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Audio\AttributionWriter;
use Illuminate\Console\Command;

final class CreditsAudioCommand extends Command
{
    protected $signature = 'audio:credits
        {--write : Vuelca la atribución a ATTRIBUTION.md en la raíz del proyecto}';

    protected $description = 'Genera el bloque de atribución para clips CC BY de la librería';

    public function __construct(
        private AttributionWriter $attribution,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $credits = $this->attribution->libraryCredits();

        if ($credits === []) {
            $this->warn('No hay clips con attribution_required en la librería.');

            return self::SUCCESS;
        }

        $this->line('Sound credits:');

        foreach ($this->attribution->lines($credits) as $line) {
            $this->line($line);
        }

        $this->newLine();
        $this->comment('Si attribution_required es true y el clip entra en un vídeo, este crédito es obligatorio.');

        if ((bool) $this->option('write')) {
            $path = base_path('ATTRIBUTION.md');
            $this->attribution->write($path, $this->attribution->document($credits));
            $this->info('Atribución escrita en '.$path);
        }

        return self::SUCCESS;
    }
}
