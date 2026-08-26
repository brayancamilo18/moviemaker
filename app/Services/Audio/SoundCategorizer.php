<?php

declare(strict_types=1);

namespace App\Services\Audio;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use JsonException;
use RuntimeException;

final class SoundCategorizer
{
    private const REQUIRED_KEYS = [
        'slug',
        'keywords',
        'type',
        'curatedQuery',
        'coreFile',
        'synthProfile',
    ];

    /**
     * @var list<string>
     */
    private const TYPES = ['ambience', 'sfx'];

    /**
     * @var list<string>
     */
    private const SYNTH_PROFILES = ['wind', 'room', 'drone', 'impact', 'friction', 'none'];

    private readonly float $threshold;

    private readonly string $path;

    /**
     * @var list<array{slug: string, keywords: list<string>, type: string, curatedQuery: string, coreFile: string, synthProfile: string}>|null
     */
    private ?array $categories = null;

    public function __construct(
        private Filesystem $files,
        Repository $config,
    ) {
        $this->threshold = (float) $config->get('stories.audio.category_threshold', 0.3);
        $configured = (string) $config->get('stories.audio.categories_path', 'audio/categories.json');
        $this->path = $this->isAbsolutePath($configured)
            ? $configured
            : resource_path($configured);
    }

    /**
     * @param  list<string>  $tags
     */
    public function categorize(array $tags, string $query): ?string
    {
        $haystack = $this->haystack($tags, $query);
        $bestSlug = null;
        $bestScore = -1.0;
        $bestHits = 0;

        foreach ($this->all() as $category) {
            $hits = $this->keywordHits($category['keywords'], $haystack);
            $score = count($category['keywords']) > 0
                ? $hits / count($category['keywords'])
                : 0.0;

            if ($score < $this->threshold) {
                continue;
            }

            if ($bestSlug === null || $score > $bestScore || ($score === $bestScore && $hits > $bestHits)) {
                $bestScore = $score;
                $bestHits = $hits;
                $bestSlug = $category['slug'];
            }
        }

        return $bestSlug;
    }

    /**
     * @return list<array{slug: string, keywords: list<string>, type: string, curatedQuery: string, coreFile: string, synthProfile: string}>
     */
    public function all(): array
    {
        if ($this->categories !== null) {
            return $this->categories;
        }

        if (! $this->files->isFile($this->path)) {
            throw new RuntimeException('No existe el fichero de categorías en '.$this->path.'.');
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($this->files->get($this->path), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('El fichero de categorías no es un JSON válido.', previous: $exception);
        }

        if (! is_array($decoded)) {
            throw new RuntimeException('El fichero de categorías debe ser un array.');
        }

        $categories = [];
        $slugs = [];

        foreach ($decoded as $row) {
            if (! is_array($row)) {
                continue;
            }

            $category = $this->hydrate($row);

            if (isset($slugs[$category['slug']])) {
                throw new RuntimeException("La categoría '{$category['slug']}' está duplicada.");
            }

            $slugs[$category['slug']] = true;
            $categories[] = $category;
        }

        if ($categories === []) {
            throw new RuntimeException('El fichero de categorías está vacío.');
        }

        $this->categories = $categories;

        return $this->categories;
    }

    /**
     * @return array{slug: string, keywords: list<string>, type: string, curatedQuery: string, coreFile: string, synthProfile: string}|null
     */
    public function find(string $slug): ?array
    {
        $slug = trim($slug);

        foreach ($this->all() as $category) {
            if ($category['slug'] === $slug) {
                return $category;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{slug: string, keywords: list<string>, type: string, curatedQuery: string, coreFile: string, synthProfile: string}
     */
    private function hydrate(array $row): array
    {
        foreach (self::REQUIRED_KEYS as $key) {
            if (! array_key_exists($key, $row)) {
                throw new RuntimeException("A una categoría le falta el campo '{$key}'.");
            }
        }

        $slug = trim((string) $row['slug']);
        $type = strtolower(trim((string) $row['type']));
        $query = trim((string) $row['curatedQuery']);
        $coreFile = basename(trim((string) $row['coreFile']));
        $profile = strtolower(trim((string) $row['synthProfile']));

        $keywords = [];

        foreach (is_array($row['keywords'] ?? null) ? $row['keywords'] : [] as $keyword) {
            $value = mb_strtolower(trim((string) $keyword));

            if ($value !== '' && ! in_array($value, $keywords, true)) {
                $keywords[] = $value;
            }
        }

        if ($slug === '' || $keywords === [] || $query === '' || $coreFile === '') {
            throw new RuntimeException("La categoría '{$slug}' tiene campos vacíos.");
        }

        if (! in_array($type, self::TYPES, true)) {
            throw new RuntimeException("La categoría '{$slug}' tiene un type inválido.");
        }

        if (! in_array($profile, self::SYNTH_PROFILES, true)) {
            throw new RuntimeException("La categoría '{$slug}' tiene un synthProfile inválido.");
        }

        if (! str_ends_with(mb_strtolower($coreFile), '.wav')) {
            throw new RuntimeException("El coreFile de '{$slug}' debe ser un WAV.");
        }

        return [
            'slug' => $slug,
            'keywords' => $keywords,
            'type' => $type,
            'curatedQuery' => $query,
            'coreFile' => $coreFile,
            'synthProfile' => $profile,
        ];
    }

    /**
     * @param  list<string>  $tags
     * @return list<string>
     */
    private function haystack(array $tags, string $query): array
    {
        $parts = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($query), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $tokens = [];

        foreach ([...$tags, ...$parts] as $part) {
            $value = mb_strtolower(trim((string) $part));

            if ($value !== '' && ! in_array($value, $tokens, true)) {
                $tokens[] = $value;
            }
        }

        return $tokens;
    }

    /**
     * @param  list<string>  $keywords
     * @param  list<string>  $haystack
     */
    private function keywordHits(array $keywords, array $haystack): int
    {
        $hits = 0;

        foreach ($keywords as $keyword) {
            foreach ($haystack as $token) {
                if ($this->tokenMatches($keyword, $token)) {
                    $hits++;

                    continue 2;
                }
            }
        }

        return $hits;
    }

    private function tokenMatches(string $keyword, string $token): bool
    {
        if ($keyword === $token) {
            return true;
        }

        if (mb_strlen($keyword) >= 3 && str_starts_with($token, $keyword)) {
            return true;
        }

        return mb_strlen($token) >= 4 && str_starts_with($keyword, $token);
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/')
            || preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1;
    }
}
