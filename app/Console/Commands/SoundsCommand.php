<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\DataObjects\ResolvedSound;
use App\DataObjects\Story;
use App\Services\Audio\StorySoundManifest;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use InvalidArgumentException;
use JsonException;
use Throwable;

final class SoundsCommand extends Command
{
    protected $signature = 'story:sounds
        {file : JSON del guion}
        {--refresh : Vuelve a resolver todas las señales}
        {--refresh-cue= : Fuerza la resolución de una señal concreta}';

    protected $description = 'Resuelve las señales de audio de una historia y escribe sounds.json';

    private readonly string $outputDirectory;

    public function __construct(
        private StorySoundManifest $manifest,
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

        $payload = $this->readStoryPayload($storyFile);

        if ($payload === null) {
            return self::FAILURE;
        }

        $slug = pathinfo($storyFile, PATHINFO_FILENAME);
        $timings = $this->readTimings($slug);
        $refresh = (bool) $this->option('refresh');
        $refreshCue = trim((string) $this->option('refresh-cue'));

        try {
            $manifest = $this->manifest->sync(
                $slug,
                Story::fromArray($payload),
                $timings,
                $refresh,
                $refreshCue !== '' ? $refreshCue : null,
            );
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->renderTable($manifest['cues']);
        $this->renderSummary($manifest['cues']);
        $this->line('sounds.json: '.$this->manifest->pathFor($slug));
        $this->comment('Si el algoritmo elige mal, edita la ruta en sounds.json y vuelve a mezclar.');

        return self::SUCCESS;
    }

    /**
     * @param  list<array<string, mixed>>  $cues
     */
    private function renderTable(array $cues): void
    {
        $rows = [];

        foreach ($cues as $cue) {
            $source = (string) ($cue['source'] ?? '');
            $rows[] = [
                (string) ($cue['id'] ?? ''),
                $this->truncate((string) ($cue['query'] ?? ''), 36),
                $this->truncate(basename((string) ($cue['file'] ?? '')), 32),
                $this->colorSource($source),
                sprintf('%.2f', (float) ($cue['score'] ?? 0)),
                sprintf('%+.1f dB', (float) ($cue['gainDb'] ?? 0)),
            ];
        }

        $this->table(['Señal', 'Query', 'Fichero', 'Origen', 'Puntuación', 'Nivel'], $rows);
    }

    /**
     * @param  list<array<string, mixed>>  $cues
     */
    private function renderSummary(array $cues): void
    {
        $counts = [
            ResolvedSound::SOURCE_CACHE => 0,
            ResolvedSound::SOURCE_DOWNLOAD => 0,
            ResolvedSound::SOURCE_FALLBACK => 0,
            ResolvedSound::SOURCE_SYNTH => 0,
        ];

        foreach ($cues as $cue) {
            $source = (string) ($cue['source'] ?? '');

            if (array_key_exists($source, $counts)) {
                $counts[$source]++;
            }
        }

        $this->newLine();
        $this->line('Caché: '.$counts[ResolvedSound::SOURCE_CACHE]);
        $this->line('Descargadas: '.$counts[ResolvedSound::SOURCE_DOWNLOAD]);
        $this->line('Respaldo: '.$counts[ResolvedSound::SOURCE_FALLBACK]);

        if ($counts[ResolvedSound::SOURCE_SYNTH] > 0) {
            $this->line('<fg=red>Sintetizadas: '.$counts[ResolvedSound::SOURCE_SYNTH].'</>');
        }

        $ladder = [1 => 0, 2 => 0, 3 => 0, 4 => 0];

        foreach ($cues as $cue) {
            $level = $cue['ladderLevel'] ?? null;

            if (is_int($level) && array_key_exists($level, $ladder)) {
                $ladder[$level]++;
            }
        }

        $this->line(sprintf(
            'Escalera Freesound: 1=%d  2=%d  3=%d  4=%d',
            $ladder[1],
            $ladder[2],
            $ladder[3],
            $ladder[4],
        ));
    }

    private function colorSource(string $source): string
    {
        return match ($source) {
            ResolvedSound::SOURCE_FALLBACK => '<fg=yellow>respaldo</>',
            ResolvedSound::SOURCE_SYNTH => '<fg=red>synth</>',
            ResolvedSound::SOURCE_CACHE => 'caché',
            ResolvedSound::SOURCE_DOWNLOAD => 'descarga',
            default => $source,
        };
    }

    /**
     * @return array{scenes?: list<array<string, mixed>>, sentences?: list<array<string, mixed>>}
     */
    private function readTimings(string $slug): array
    {
        $path = $this->outputDirectory.DIRECTORY_SEPARATOR.$slug.DIRECTORY_SEPARATOR.'timings.json';

        if (! $this->files->isFile($path)) {
            return [];
        }

        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($this->files->get($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        return $decoded;
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

    /**
     * @return array<string, mixed>|null
     */
    private function readStoryPayload(string $path): ?array
    {
        try {
            $decoded = json_decode($this->files->get($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->error('El guion no es un JSON válido.');

            return null;
        }

        if (! is_array($decoded) || ! isset($decoded['scenes']) || ! is_array($decoded['scenes'])) {
            $this->error('El JSON no contiene un guion de historia.');

            return null;
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private function truncate(string $value, int $limit): string
    {
        if (mb_strlen($value) <= $limit) {
            return $value;
        }

        return mb_substr($value, 0, $limit - 1).'…';
    }
}
