<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Audio\CoreKitInstaller;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

final class CoreKitCommand extends Command
{
    protected $signature = 'audio:core-kit
        {--verify : Comprueba que las 24 categorías tienen fichero y pasan el verificador}
        {--force : Vuelve a descargar aunque el fichero ya exista}
        {--only= : Slugs separados por coma para tocar categorías sueltas}';

    protected $description = 'Descarga o verifica el kit de respaldo en resources/audio/core';

    public function __construct(
        private CoreKitInstaller $installer,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $only = $this->parseOnly();

            if ((bool) $this->option('verify')) {
                return $this->verify($only);
            }

            return $this->install($only, (bool) $this->option('force'));
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * @param  list<string>  $only
     */
    private function install(array $only, bool $force): int
    {
        $rows = $this->installer->install($only, $force);
        $failed = 0;

        $this->table(
            ['Categoría', 'Estado', 'Fichero', 'Detalle'],
            array_map(function (array $row) use (&$failed): array {
                if ($row['status'] === 'failed') {
                    $failed++;
                }

                return [
                    $row['slug'],
                    $this->statusLabel($row['status']),
                    $row['file'],
                    $row['reason'],
                ];
            }, $rows),
        );

        if ($failed > 0) {
            $this->error("Fallaron {$failed} categorías. El core kit tiene que estar completo.");

            return self::FAILURE;
        }

        $this->info(count($rows).' categorías listas en resources/audio/core.');

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $only
     */
    private function verify(array $only): int
    {
        $rows = $this->installer->verify($only);
        $failed = 0;

        $this->table(
            ['Categoría', 'Fichero', 'Estado', 'Detalle'],
            array_map(function (array $row) use (&$failed): array {
                if (! $row['passed']) {
                    $failed++;
                }

                return [
                    $row['slug'],
                    $row['file'],
                    $row['passed'] ? 'ok' : 'falla',
                    $row['reason'],
                ];
            }, $rows),
        );

        if ($failed > 0) {
            $this->error("Faltan o fallan {$failed} categorías del core kit.");

            return self::FAILURE;
        }

        $this->info('Core kit completo: '.count($rows).' categorías verificadas.');

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function parseOnly(): array
    {
        $raw = trim((string) $this->option('only'));

        if ($raw === '') {
            return [];
        }

        $slugs = [];

        foreach (preg_split('/\s*,\s*/', $raw) ?: [] as $slug) {
            $value = trim($slug);

            if ($value !== '' && ! in_array($value, $slugs, true)) {
                $slugs[] = $value;
            }
        }

        return $slugs;
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'downloaded' => 'descargado',
            'kept' => 'conservado',
            default => 'fallido',
        };
    }
}
