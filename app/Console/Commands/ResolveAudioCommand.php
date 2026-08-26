<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\DataObjects\ResolvedSound;
use App\DataObjects\Story;
use App\Services\Audio\SoundLibraryImporter;
use App\Services\Audio\SoundResolver;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use InvalidArgumentException;
use JsonException;
use Throwable;

final class ResolveAudioCommand extends Command
{
    protected $signature = 'audio:resolve
        {file? : JSON del guion (opcional)}
        {--type= : ambience, sfx o music (si no pasas un guion)}
        {--query= : Consulta (si no pasas un guion)}';

    protected $description = 'Resuelve una señal de audio: caché, Freesound, respaldo o bed sintetizado';

    private readonly string $outputDirectory;

    public function __construct(
        private SoundResolver $resolver,
        private Filesystem $files,
        Repository $config,
    ) {
        parent::__construct();

        $this->outputDirectory = storage_path('app/'.$config->get('stories.output_path'));
    }

    public function handle(): int
    {
        $file = trim((string) $this->argument('file'));

        try {
            $signals = $file !== ''
                ? $this->signalsFromStory($file)
                : $this->signalsFromOptions();
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($signals === []) {
            $this->warn('No hay señales de audio que resolver.');

            return self::SUCCESS;
        }

        $rows = [];

        foreach ($signals as $signal) {
            $resolved = $this->resolver->resolve(
                $signal['tags'],
                $signal['query'],
                $signal['type'],
            );
            $rows[] = [
                $signal['type'],
                $this->truncate($signal['query'], 40),
                $this->sourceLabel($resolved->source),
                $this->truncate(basename($resolved->path), 36),
                sprintf('%.2f', $resolved->score),
                $resolved->ladderLevel === null ? '—' : (string) $resolved->ladderLevel,
                $resolved->path === '' ? 'sin fichero' : 'ok',
            ];
        }

        $this->table(['Tipo', 'Consulta', 'Origen', 'Fichero', 'Score', 'Escalera', 'Estado'], $rows);
        $this->comment('La mezcla no se detiene si un clip falla: se registra y se sigue.');

        return self::SUCCESS;
    }

    /**
     * @return list<array{type: string, query: string, tags: list<string>, role: string, sceneOrder?: int}>
     */
    private function signalsFromStory(string $file): array
    {
        $path = $this->resolveStoryFile($file);

        try {
            $decoded = json_decode($this->files->get($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException|Throwable $exception) {
            throw new InvalidArgumentException('El guion no es un JSON válido.', 0, $exception);
        }

        if (! is_array($decoded) || ! isset($decoded['scenes']) || ! is_array($decoded['scenes'])) {
            throw new InvalidArgumentException('El JSON no contiene un guion de historia.');
        }

        /** @var array<string, mixed> $decoded */
        return $this->resolver->signalsFor(Story::fromArray($decoded));
    }

    /**
     * @return list<array{type: string, query: string, tags: list<string>, role: string}>
     */
    private function signalsFromOptions(): array
    {
        $type = strtolower(trim((string) $this->option('type')));
        $query = trim((string) $this->option('query'));

        if (! in_array($type, ['ambience', 'sfx', 'music'], true)) {
            throw new InvalidArgumentException('Indica --type=ambience|sfx|music o un JSON de guion.');
        }

        if ($query === '') {
            throw new InvalidArgumentException('Indica --query.');
        }

        return [[
            'type' => $type,
            'query' => $query,
            'tags' => SoundLibraryImporter::tagsFromQuery($query),
            'role' => 'manual',
        ]];
    }

    private function resolveStoryFile(string $file): string
    {
        foreach ([$file, $this->outputDirectory.DIRECTORY_SEPARATOR.basename($file)] as $candidate) {
            if ($this->files->isFile($candidate)) {
                return realpath($candidate) ?: $candidate;
            }
        }

        throw new InvalidArgumentException("No se encontró el guion '{$file}'.");
    }

    private function sourceLabel(string $source): string
    {
        return match ($source) {
            ResolvedSound::SOURCE_CACHE => 'caché',
            ResolvedSound::SOURCE_DOWNLOAD => 'Freesound',
            ResolvedSound::SOURCE_FALLBACK => 'respaldo',
            ResolvedSound::SOURCE_SYNTH => 'sintetizado',
            default => $source,
        };
    }

    private function truncate(string $value, int $limit): string
    {
        if (mb_strlen($value) <= $limit) {
            return $value;
        }

        return mb_substr($value, 0, $limit - 1).'…';
    }
}
