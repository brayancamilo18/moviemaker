<?php

declare(strict_types=1);

namespace App\Services\Audio;

use App\DataObjects\ResolvedSound;
use App\DataObjects\Story;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

final class StorySoundManifest
{
    private readonly string $storiesDirectory;

    public function __construct(
        private SoundCuePlanner $planner,
        private SoundResolver $resolver,
        private AudioLibrary $library,
        private LibraryClipProcessor $processor,
        private Filesystem $files,
        Repository $config,
    ) {
        $this->storiesDirectory = storage_path('app/'.$config->get('stories.output_path'));
    }

    public function pathFor(string $slug): string
    {
        return $this->directory($slug).DIRECTORY_SEPARATOR.'sounds.json';
    }

    public function exists(string $slug): bool
    {
        return $this->files->isFile($this->pathFor($slug));
    }

    /**
     * @return array{version: int, slug: string, cues: list<array<string, mixed>>}
     */
    public function load(string $slug): array
    {
        $path = $this->pathFor($slug);

        if (! $this->files->isFile($path)) {
            return [
                'version' => 1,
                'slug' => $slug,
                'cues' => [],
            ];
        }

        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($this->files->get($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('sounds.json no es un JSON válido.', previous: $exception);
        }

        $cues = [];

        foreach (is_array($decoded['cues'] ?? null) ? $decoded['cues'] : [] as $cue) {
            if (is_array($cue) && trim((string) ($cue['id'] ?? '')) !== '') {
                $cues[] = $cue;
            }
        }

        return [
            'version' => (int) ($decoded['version'] ?? 1),
            'slug' => (string) ($decoded['slug'] ?? $slug),
            'cues' => $cues,
        ];
    }

    /**
     * @param  array{scenes?: list<array<string, mixed>>}  $timings
     * @return array{version: int, slug: string, cues: list<array<string, mixed>>}
     */
    public function sync(string $slug, Story $story, array $timings = [], bool $refresh = false, ?string $refreshCue = null): array
    {
        $planned = $this->planner->cues($story, $timings);
        $existing = $this->indexed($this->load($slug)['cues']);
        $refreshCue = $this->normalizeCueId($refreshCue, $planned);
        $used = [];
        $cues = [];

        foreach ($planned as $plan) {
            $previous = $existing[$plan['id']] ?? null;
            $keep = ! $refresh
                && ($refreshCue === null || $refreshCue !== $plan['id'])
                && $this->hasStoredFile($previous);

            if ($keep && is_array($previous)) {
                $cues[] = $this->mergeKept($plan, $previous);
                $absolute = $this->absoluteFile((string) ($previous['file'] ?? ''));

                if ($absolute !== '') {
                    $used[] = $absolute;
                }

                continue;
            }

            $resolved = $this->resolver->resolve(
                $plan['tags'],
                $plan['query'],
                $plan['type'],
                (float) $plan['minDuration'],
                $used,
            );
            $entry = $this->fromResolved($plan, $resolved);

            if ($entry['file'] !== '') {
                $used[] = $this->absoluteFile($entry['file']);
            }

            $cues[] = $entry;
        }

        $manifest = [
            'version' => 1,
            'slug' => $slug,
            'cues' => $cues,
        ];
        $this->write($slug, $manifest);

        return $manifest;
    }

    /**
     * @param  array<string, mixed>  $cue
     */
    public function absoluteFile(string $file): string
    {
        $file = trim($file);

        if ($file === '') {
            return '';
        }

        if ($this->files->isFile($file)) {
            return $file;
        }

        $fromLibrary = $this->library->absolutePath($file);

        if ($this->files->isFile($fromLibrary)) {
            return $fromLibrary;
        }

        return $file;
    }

    /**
     * @param  list<array<string, mixed>>  $cues
     * @return array<string, array{path: string, gainDb: float}>
     */
    public function overrides(array $cues, ?string $type = null): array
    {
        $overrides = [];

        foreach ($cues as $cue) {
            $cueType = (string) ($cue['type'] ?? '');

            if ($type !== null && $cueType !== $type) {
                continue;
            }

            $path = $this->absoluteFile((string) ($cue['file'] ?? ''));

            if ($path === '' || ! $this->files->isFile($path)) {
                continue;
            }

            $id = (string) ($cue['id'] ?? '');
            $overrides[$id] = [
                'path' => $path,
                'gainDb' => (float) ($cue['gainDb'] ?? 0),
            ];
        }

        return $overrides;
    }

    /**
     * @param  list<array<string, mixed>>  $cues
     * @return array<int, array{path: string, gainDb: float}>
     */
    public function ambienceByScene(array $cues): array
    {
        $scenes = [];

        foreach ($cues as $cue) {
            if (($cue['type'] ?? '') !== 'ambience') {
                continue;
            }

            $order = (int) ($cue['sceneOrder'] ?? 0);
            $path = $this->absoluteFile((string) ($cue['file'] ?? ''));

            if ($order < 1 || $path === '' || ! $this->files->isFile($path)) {
                continue;
            }

            $scenes[$order] = [
                'path' => $path,
                'gainDb' => (float) ($cue['gainDb'] ?? 0),
            ];
        }

        return $scenes;
    }

    /**
     * @param  array{version: int, slug: string, cues: list<array<string, mixed>>}  $manifest
     */
    public function write(string $slug, array $manifest): void
    {
        $directory = $this->directory($slug);
        $this->files->ensureDirectoryExists($directory);
        $json = json_encode(
            $manifest,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        if ($json === false) {
            throw new RuntimeException('No se pudo serializar sounds.json.');
        }

        $this->files->put($this->pathFor($slug), $json."\n");
    }

    private function directory(string $slug): string
    {
        $slug = trim($slug);

        if ($slug === '' || basename($slug) !== $slug) {
            throw new InvalidArgumentException('El slug de la historia no es válido.');
        }

        return $this->storiesDirectory.DIRECTORY_SEPARATOR.$slug;
    }

    /**
     * @param  list<array<string, mixed>>  $cues
     * @return array<string, array<string, mixed>>
     */
    private function indexed(array $cues): array
    {
        $indexed = [];

        foreach ($cues as $cue) {
            $id = (string) ($cue['id'] ?? '');

            if ($id !== '') {
                $indexed[$id] = $cue;
            }
        }

        return $indexed;
    }

    /**
     * @param  list<array{id: string}>  $planned
     */
    private function normalizeCueId(?string $refreshCue, array $planned): ?string
    {
        $refreshCue = trim((string) $refreshCue);

        if ($refreshCue === '') {
            return null;
        }

        foreach ($planned as $plan) {
            if ($plan['id'] === $refreshCue) {
                return $refreshCue;
            }
        }

        throw new InvalidArgumentException("No existe la señal '{$refreshCue}'.");
    }

    /**
     * @param  array<string, mixed>|null  $cue
     */
    private function hasStoredFile(?array $cue): bool
    {
        if ($cue === null) {
            return false;
        }

        return trim((string) ($cue['file'] ?? '')) !== '';
    }

    /**
     * @param  array{id: string, type: string, role: string, sceneOrder: ?int, query: string, tags: list<string>, minDuration: float, intensity: ?string, kind: ?string}  $plan
     * @param  array<string, mixed>  $previous
     * @return array<string, mixed>
     */
    private function mergeKept(array $plan, array $previous): array
    {
        $entry = $this->base($plan);
        $entry['file'] = (string) ($previous['file'] ?? '');
        $entry['source'] = (string) ($previous['source'] ?? '');
        $entry['score'] = (float) ($previous['score'] ?? 0);
        $entry['gainDb'] = (float) ($previous['gainDb'] ?? 0);
        $entry['lufs'] = (float) ($previous['lufs'] ?? 0);
        $entry['license'] = $previous['license'] ?? null;
        $entry['author'] = $previous['author'] ?? null;
        $entry['sourceUrl'] = $previous['sourceUrl'] ?? null;
        $entry['attributionRequired'] = (bool) ($previous['attributionRequired'] ?? false);
        $entry['ladderLevel'] = array_key_exists('ladderLevel', $previous)
            ? ($previous['ladderLevel'] === null ? null : (int) $previous['ladderLevel'])
            : null;

        return $entry;
    }

    /**
     * @param  array{id: string, type: string, role: string, sceneOrder: ?int, query: string, tags: list<string>, minDuration: float, intensity: ?string, kind: ?string}  $plan
     * @return array<string, mixed>
     */
    private function fromResolved(array $plan, ResolvedSound $resolved): array
    {
        $entry = $this->base($plan);
        $path = $resolved->path;
        $entry['file'] = $this->storedFile($path);
        $entry['source'] = $resolved->source;
        $entry['score'] = $resolved->score;
        $entry['lufs'] = $resolved->lufs;
        $entry['license'] = $resolved->license;
        $entry['author'] = $resolved->author;
        $entry['sourceUrl'] = $resolved->sourceUrl;
        $entry['attributionRequired'] = $resolved->attributionRequired;
        $entry['ladderLevel'] = $resolved->ladderLevel;

        $target = $this->planner->targetLufs($plan['type'], $plan['intensity']);

        if ($plan['type'] === 'sfx') {
            $entry['gainDb'] = 0.0;
        } elseif ($path !== '' && $this->files->isFile($path)) {
            $lufs = $resolved->lufs !== 0.0
                ? $resolved->lufs
                : $this->processor->integratedLufs($path);
            $entry['lufs'] = $lufs;
            $entry['gainDb'] = round($target - $lufs, 3);
        } else {
            $entry['gainDb'] = 0.0;
        }

        return $entry;
    }

    /**
     * @param  array{id: string, type: string, role: string, sceneOrder: ?int, query: string, tags: list<string>, intensity: ?string, kind: ?string}  $plan
     * @return array<string, mixed>
     */
    private function base(array $plan): array
    {
        return [
            'id' => $plan['id'],
            'type' => $plan['type'],
            'role' => $plan['role'],
            'sceneOrder' => $plan['sceneOrder'],
            'query' => $plan['query'],
            'tags' => $plan['tags'],
            'intensity' => $plan['intensity'],
            'kind' => $plan['kind'],
            'file' => '',
            'source' => '',
            'score' => 0.0,
            'gainDb' => 0.0,
            'lufs' => 0.0,
            'license' => null,
            'author' => null,
            'sourceUrl' => null,
            'attributionRequired' => false,
            'ladderLevel' => null,
        ];
    }

    private function storedFile(string $absolute): string
    {
        if ($absolute === '') {
            return '';
        }

        $root = rtrim($this->library->root(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        if (str_starts_with($absolute, $root)) {
            return str_replace('\\', '/', substr($absolute, strlen($root)));
        }

        return $absolute;
    }
}
