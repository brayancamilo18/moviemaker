<?php

declare(strict_types=1);

namespace App\Services\Audio;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use JsonException;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class AudioLibrary
{
    /**
     * Marca de un clip cuya procedencia no consta en ningún índice. Nunca se declara interno:
     * si no sabemos de quién es, se acredita.
     */
    public const LICENSE_UNKNOWN = 'unknown';

    public const AUTHOR_UNKNOWN = 'unknown';

    private readonly string $root;

    private readonly string $coreManifestPath;

    private readonly string $localIndexPath;

    private bool $missingReported = false;

    public function __construct(
        private Filesystem $files,
        private LoggerInterface $logger,
        Repository $config,
    ) {
        $path = (string) $config->get('stories.audio.library_path', 'audio');
        $this->root = $this->isAbsolutePath($path)
            ? rtrim($path, DIRECTORY_SEPARATOR)
            : resource_path($path);
        $this->coreManifestPath = $this->root.DIRECTORY_SEPARATOR.'manifest.json';

        $local = (string) $config->get('stories.audio.local_index_path', 'audio/library.json');
        $this->localIndexPath = $this->isAbsolutePath($local)
            ? rtrim($local, DIRECTORY_SEPARATOR)
            : storage_path('app'.DIRECTORY_SEPARATOR.ltrim(str_replace('/', DIRECTORY_SEPARATOR, $local), DIRECTORY_SEPARATOR));
    }

    public function coreManifestPath(): string
    {
        return $this->coreManifestPath;
    }

    public function localIndexPath(): string
    {
        return $this->localIndexPath;
    }

    public function root(): string
    {
        return $this->root;
    }

    public function directoryFor(string $type): string
    {
        return $this->root.DIRECTORY_SEPARATOR.$type;
    }

    /**
     * Clips indexados cuyo fichero sigue en disco.
     *
     * @return list<array<string, mixed>>
     */
    public function clips(): array
    {
        $clips = [];
        $missing = 0;

        foreach ($this->allClips() as $clip) {
            if (! $this->fileExists((string) ($clip['file'] ?? ''))) {
                $missing++;

                continue;
            }

            $clips[] = $clip;
        }

        $this->reportMissing($missing);

        return $clips;
    }

    /**
     * Unión de los dos índices tal cual, incluidos los clips cuyo fichero falta.
     *
     * @return list<array<string, mixed>>
     */
    public function allClips(): array
    {
        return $this->read()['clips'];
    }

    public function fileExists(string $relative): bool
    {
        $relative = trim($relative);

        return $relative !== '' && $this->files->isFile($this->absolutePath($relative));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function filter(?string $type = null, ?string $tag = null, bool $includeMissing = false): array
    {
        $type = $type !== null ? trim($type) : '';
        $tag = $tag !== null ? mb_strtolower(trim($tag)) : '';
        $clips = [];

        foreach ($includeMissing ? $this->allClips() : $this->clips() as $clip) {
            if ($type !== '' && ($clip['type'] ?? '') !== $type) {
                continue;
            }

            if ($tag !== '') {
                $tags = array_map(
                    static fn (mixed $item): string => mb_strtolower((string) $item),
                    is_array($clip['tags'] ?? null) ? $clip['tags'] : [],
                );

                if (! in_array($tag, $tags, true)) {
                    continue;
                }
            }

            $clips[] = $clip;
        }

        return $clips;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function attributionClips(): array
    {
        $clips = [];

        foreach ($this->clips() as $clip) {
            if (($clip['attribution_required'] ?? false) === true) {
                $clips[] = $clip;
            }
        }

        return $clips;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findBySourceId(int $sourceId): ?array
    {
        foreach ($this->clips() as $clip) {
            if ((string) ($clip['source_id'] ?? '') === (string) $sourceId) {
                return $clip;
            }
        }

        return null;
    }

    public function hasSourceId(int $sourceId): bool
    {
        return $this->findBySourceId($sourceId) !== null;
    }

    public function hasSha1(string $sha1): bool
    {
        foreach ($this->clips() as $clip) {
            if (($clip['sha1'] ?? '') === $sha1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Indexa un clip local en storage/app/audio/library.json, fuera de git.
     *
     * @param  array{file: string, type: string, tags: list<string>, duration: float, loopable: bool, source_id: string, source_url: string, author: string, license: string, attribution_required: bool, lufs: float, sha1: string}  $clip
     */
    public function add(array $clip): void
    {
        $this->index($this->localIndexPath, [...$clip, 'is_core' => false]);
    }

    /**
     * Indexa un clip del core kit en el manifiesto versionado: es el único que lo escribe.
     *
     * @param  array{file: string, type: string, tags: list<string>, duration: float, loopable: bool, source_id: string, source_url: string, author: string, license: string, attribution_required: bool, lufs: float, sha1: string}  $clip
     */
    public function addCore(array $clip): void
    {
        $this->index($this->coreManifestPath, [...$clip, 'is_core' => true]);
    }

    /**
     * Quita del índice local las entradas cuyo fichero ya no está en disco y devuelve cuántas ha quitado.
     *
     * El manifiesto versionado nunca se purga: un clip del core que falta no es basura, es un clip
     * que hay que volver a instalar con audio:core-kit.
     */
    public function prune(): int
    {
        $index = $this->readIndex($this->localIndexPath);
        $kept = [];

        foreach ($index['clips'] as $clip) {
            if ($this->fileExists((string) ($clip['file'] ?? ''))) {
                $kept[] = $clip;
            }
        }

        $removed = count($index['clips']) - count($kept);

        if ($removed === 0) {
            return 0;
        }

        $index['clips'] = $kept;
        $this->write($this->localIndexPath, $index);

        return $removed;
    }

    /**
     * Clips indexados cuyo fichero falta y que pertenecen al core kit: no se pueden purgar.
     *
     * @return list<array<string, mixed>>
     */
    public function missingCoreClips(): array
    {
        $clips = [];

        foreach ($this->allClips() as $clip) {
            if (($clip['is_core'] ?? false) === true && ! $this->fileExists((string) ($clip['file'] ?? ''))) {
                $clips[] = $clip;
            }
        }

        return $clips;
    }

    public function absolutePath(string $relative): string
    {
        return $this->root.DIRECTORY_SEPARATOR.ltrim(str_replace('\\', '/', $relative), '/');
    }

    /**
     * El manifiesto versionado manda en el orden; el índice local pisa las entradas repetidas.
     *
     * @return array{version: int, clips: list<array<string, mixed>>}
     */
    private function read(): array
    {
        $this->files->ensureDirectoryExists($this->root);

        $core = $this->readIndex($this->coreManifestPath);
        $local = $this->readIndex($this->localIndexPath);

        $clips = $core['clips'];
        $positions = [];

        foreach ($clips as $position => $clip) {
            $file = (string) ($clip['file'] ?? '');

            if ($file !== '') {
                $positions[$file] = $position;
            }
        }

        foreach ($local['clips'] as $clip) {
            $file = (string) ($clip['file'] ?? '');

            if ($file !== '' && isset($positions[$file])) {
                $clips[$positions[$file]] = $clip;

                continue;
            }

            $clips[] = $clip;
        }

        return [
            'version' => $core['version'],
            'clips' => array_values($clips),
        ];
    }

    /**
     * @return array{version: int, clips: list<array<string, mixed>>}
     */
    private function readIndex(string $path): array
    {
        if (! $this->files->isFile($path)) {
            return ['version' => 1, 'clips' => []];
        }

        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($this->files->get($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException("El índice de audio {$path} no es un JSON válido.", previous: $exception);
        }

        $clips = [];

        foreach (is_array($decoded['clips'] ?? null) ? $decoded['clips'] : [] as $clip) {
            if (is_array($clip)) {
                $clips[] = $clip;
            }
        }

        return [
            'version' => (int) ($decoded['version'] ?? 1),
            'clips' => $clips,
        ];
    }

    /**
     * Añade o reemplaza (por nombre de fichero) una entrada en el índice indicado.
     *
     * @param  array<string, mixed>  $clip
     */
    private function index(string $path, array $clip): void
    {
        $file = (string) ($clip['file'] ?? '');

        if (! $this->fileExists($file)) {
            throw new RuntimeException(
                $file === ''
                    ? 'No se puede indexar un clip sin fichero.'
                    : "No se indexa '{$file}': el fichero no está en la librería de audio.",
            );
        }

        $index = $this->readIndex($path);
        $clips = [];
        $replaced = false;

        foreach ($index['clips'] as $existing) {
            if ((string) ($existing['file'] ?? '') === $file) {
                $clips[] = $clip;
                $replaced = true;

                continue;
            }

            $clips[] = $existing;
        }

        if (! $replaced) {
            $clips[] = $clip;
        }

        $index['clips'] = $clips;
        $this->write($path, $index);
    }

    /**
     * @param  array{version: int, clips: list<array<string, mixed>>}  $index
     */
    private function write(string $path, array $index): void
    {
        $this->files->ensureDirectoryExists(dirname($path));

        $json = json_encode(
            $index,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        if ($json === false) {
            throw new RuntimeException('No se pudo serializar el índice de audio '.$path.'.');
        }

        $this->files->put($path, $json."\n");
    }

    private function reportMissing(int $missing): void
    {
        if ($missing === 0 || $this->missingReported) {
            return;
        }

        $this->missingReported = true;
        $this->logger->warning(sprintf(
            'La librería de audio ignora %d clip%s indexado%s cuyo fichero ya no está en disco.',
            $missing,
            $missing === 1 ? '' : 's',
            $missing === 1 ? '' : 's',
        ));
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/')
            || preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1;
    }
}
