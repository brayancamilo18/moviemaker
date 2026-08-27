<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Diagnostics\EnvironmentDoctor;
use Illuminate\Console\Command;

final class DoctorCommand extends Command
{
    protected $signature = 'story:doctor
        {--warn-only : Informa y sale con éxito aunque haya fallos bloqueantes}';

    protected $description = 'Comprueba binarios, modelos, credenciales y librería de audio del pipeline';

    public function __construct(
        private EnvironmentDoctor $doctor,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $checks = $this->doctor->checks();

        $this->table(
            ['Comprobación', 'Estado', 'Detalle'],
            array_map(
                static fn (array $check): array => [
                    $check['name'],
                    $check['ok'] ? 'OK' : 'FALLO',
                    $check['detail'],
                ],
                $checks,
            ),
        );

        $blocking = $this->doctor->hasBlockingFailure($checks);
        $failed = $this->listFailures($checks);

        if ($failed !== []) {
            $this->newLine();
            $this->line('Qué hay que arreglar:');

            foreach ($failed as $line) {
                $this->line('  '.$line);
            }
        }

        if ($blocking) {
            $this->newLine();
            $this->error('Hay fallos bloqueantes: el pipeline no puede ejecutarse hasta arreglarlos.');

            if (! (bool) $this->option('warn-only')) {
                return self::FAILURE;
            }

            $this->warn('Con --warn-only el diagnóstico no interrumpe la instalación.');

            return self::SUCCESS;
        }

        $this->newLine();

        if ($failed !== []) {
            $this->warn('Entorno usable, pero con avisos.');

            return self::SUCCESS;
        }

        $this->info('Entorno listo.');

        return self::SUCCESS;
    }

    /**
     * @param  list<array{name: string, ok: bool, blocking: bool, detail: string}>  $checks
     * @return list<string>
     */
    private function listFailures(array $checks): array
    {
        $lines = [];

        foreach ($checks as $check) {
            if ($check['ok']) {
                continue;
            }

            $lines[] = sprintf(
                '[%s] %s: %s',
                $check['blocking'] ? 'bloqueante' : 'aviso',
                $check['name'],
                $check['detail'],
            );
        }

        return $lines;
    }
}
