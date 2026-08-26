<?php

declare(strict_types=1);

namespace App\Services\Audio;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use JsonException;
use RuntimeException;

final class AudioLibrary
{
    private readonly string $root;

    private readonly string $manifestPath;

    public function __construct(
        private Filesystem $files,
        Repository $config,
    ) {
        $path = (string) $config->get('stories.audio.library_path', 'audio');
        $this->root = $this->isAbsolutePath($path)
            ? rtrim($path, DIRECTORY_SEPARATOR)
            : resource_path($path);
        $this->manifestPath = $this->root.DIRECTORY_SEPARATOR.'manifest.json';
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
     * @return list<array<string, mixed>>
     */
    public function clips(): array
    {
        return $this->read()['clips'];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function filter(?string $type = null, ?string $tag = null): array
    {
        $type = $type !== null ? trim($type) : '';
        $tag = $tag !== null ? mb_strtolower(trim($tag)) : '';
        $clips = [];

        foreach ($this->clips() as $clip) {
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
     * @param  array{file: string, type: string, tags: list<string>, duration: float, loopable: bool, source_id: string, source_url: string, author: string, license: string, attribution_required: bool, lufs: float, sha1: string}  $clip
     */
    public function add(array $clip): void
    {
        $manifest = $this->read();
        $file = (string) ($clip['file'] ?? '');
        $clips = [];
        $replaced = false;

        foreach ($manifest['clips'] as $existing) {
            if ($file !== '' && (string) ($existing['file'] ?? '') === $file) {
                $clips[] = $clip;
                $replaced = true;

                continue;
            }

            $clips[] = $existing;
        }

        if (! $replaced) {
            $clips[] = $clip;
        }

        $manifest['clips'] = $clips;
        $this->write($manifest);
    }

    public function absolutePath(string $relative): string
    {
        return $this->root.DIRECTORY_SEPARATOR.ltrim(str_replace('\\', '/', $relative), '/');
    }

    /**
     * @return array{version: int, clips: list<array<string, mixed>>}
     */
    private function read(): array
    {
        $this->files->ensureDirectoryExists($this->root);

        if (! $this->files->isFile($this->manifestPath)) {
            return ['version' => 1, 'clips' => []];
        }

        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($this->files->get($this->manifestPath), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('El manifest de audio no es un JSON válido.', previous: $exception);
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
     * @param  array{version: int, clips: list<array<string, mixed>>}  $manifest
     */
    private function write(array $manifest): void
    {
        $this->files->ensureDirectoryExists($this->root);

        $json = json_encode(
            $manifest,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        if ($json === false) {
            throw new RuntimeException('No se pudo serializar el manifest de audio.');
        }

        $this->files->put($this->manifestPath, $json."\n");
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/')
            || preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1;
    }
}
