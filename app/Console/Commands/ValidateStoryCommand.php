<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Story\StoryValidator;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Filesystem\Filesystem;

final class ValidateStoryCommand extends Command
{
    protected $signature = 'story:validate
        {file : JSON del guion}';

    protected $description = 'Comprueba que la historia está lista para mezclar y renderizar';

    private readonly string $outputDirectory;

    public function __construct(
        private StoryValidator $validator,
        private Filesystem $files,
        Repository $config,
    ) {
        parent::__construct();

        $this->outputDirectory = storage_path('app/'.$config->get('stories.output_path'));
    }

    public function handle(): int
    {
        $storyFile = $this->resolveStoryFile((string) $this->argument('file'));

        if ($storyFile === null) {
            return self::FAILURE;
        }

        $slug = pathinfo($storyFile, PATHINFO_FILENAME);
        $report = $this->validator->validate($slug);
        $rows = [];

        foreach ($report['checks'] as $check) {
            $rows[] = [
                $check['label'],
                $this->statusCell($check),
                $check['detail'],
            ];
        }

        $this->table(['Comprobación', 'Estado', 'Detalle'], $rows);

        if ($report['passed']) {
            $this->info('Validación: sin bloqueantes.');

            return self::SUCCESS;
        }

        $this->error('Validación: hay bloqueantes.');

        foreach ($report['checks'] as $check) {
            if ($check['blocking'] && $check['status'] === 'fail') {
                $this->error('FALLO  '.$check['detail']);
            }
        }

        return self::FAILURE;
    }

    /**
     * @param  array{status: string, blocking: bool}  $check
     */
    private function statusCell(array $check): string
    {
        return match ($check['status']) {
            'fail' => $check['blocking'] ? '<fg=red>FALLO</>' : 'FALLO',
            'warn' => '<fg=yellow>AVISO</>',
            default => 'OK',
        };
    }

    private function resolveStoryFile(string $file): ?string
    {
        foreach ([$file, $this->outputDirectory.DIRECTORY_SEPARATOR.basename($file)] as $candidate) {
            if ($this->files->isFile($candidate)) {
                return realpath($candidate) ?: $candidate;
            }
        }

        $this->error("No se encontró el guion '{$file}'.");

        return null;
    }
}
