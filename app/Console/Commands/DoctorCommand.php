<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Diagnostics\EnvironmentDoctor;
use Illuminate\Console\Command;

final class DoctorCommand extends Command
{
    protected $signature = 'story:doctor
        {--warn-only : Informa y sale con éxito aunque haya fallos bloqueantes}
        {--fix-hints : Imprime el comando que resuelve cada fallo}';

    protected $description = 'Comprueba binarios, modelos, credenciales, cola y salida a internet del pipeline';

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
                fn (array $check): array => [
                    $check['name'],
                    $this->statusLabel($check['status']),
                    $check['detail'],
                ],
                $checks,
            ),
        );

        $blocking = $this->doctor->hasBlockingFailure($checks);
        $failed = $this->failedChecks($checks);

        if ($failed !== []) {
            $this->newLine();
            $this->line('Qué hay que arreglar:');

            foreach ($failed as $check) {
                $this->line(sprintf(
                    '  [%s] %s: %s',
                    $check['blocking'] ? 'bloqueante' : 'aviso',
                    $check['name'],
                    $check['detail'],
                ));

                if ((bool) $this->option('fix-hints') && $check['fix'] !== '') {
                    $this->line('    → '.$check['fix']);
                }
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
     * @param  list<array{name: string, ok: bool, blocking: bool, status: string, detail: string, fix: string}>  $checks
     * @return list<array{name: string, ok: bool, blocking: bool, status: string, detail: string, fix: string}>
     */
    private function failedChecks(array $checks): array
    {
        return array_values(array_filter(
            $checks,
            static fn (array $check): bool => ! $check['ok'],
        ));
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'green' => '<fg=green>OK</>',
            'amber' => '<fg=yellow>AVISO</>',
            default => '<fg=red>FALLO</>',
        };
    }
}
