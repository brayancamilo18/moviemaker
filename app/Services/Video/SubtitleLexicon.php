<?php

declare(strict_types=1);

namespace App\Services\Video;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use JsonException;
use RuntimeException;

/**
 * Autoridad de palabra: parte el texto en palabras y dice si una es artículo o conjunción en el
 * idioma del guion. Las listas son una heurística de corte de línea, no un selector de idioma.
 */
final class SubtitleLexicon
{
    private readonly string $path;

    private readonly string $language;

    /**
     * @var array{articles: list<string>, conjunctions: list<string>}|null
     */
    private ?array $lists = null;

    public function __construct(
        private Filesystem $files,
        Repository $config,
    ) {
        $configured = (string) $config->get('stories.subtitles.lexicon_path', 'subtitles/lexicon.json');
        $this->path = $this->isAbsolutePath($configured)
            ? $configured
            : resource_path($configured);
        $this->language = $this->normalizeLanguage((string) $config->get('stories.story.language', 'en'));
    }

    /**
     * @return list<string>
     */
    public function words(string $text): array
    {
        $words = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY);

        return $words === false ? [] : array_values($words);
    }

    public function isArticle(string $word): bool
    {
        return in_array($this->bareWord($word), $this->lists()['articles'], true);
    }

    public function isConjunction(string $word): bool
    {
        return in_array($this->bareWord($word), $this->lists()['conjunctions'], true);
    }

    /**
     * @return array{articles: list<string>, conjunctions: list<string>}
     */
    private function lists(): array
    {
        if ($this->lists !== null) {
            return $this->lists;
        }

        if (! $this->files->isFile($this->path)) {
            throw new RuntimeException('No existe el léxico de subtítulos en '.$this->path.'.');
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($this->files->get($this->path), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('El léxico de subtítulos no es un JSON válido.', previous: $exception);
        }

        if (! is_array($decoded)) {
            throw new RuntimeException('El léxico de subtítulos debe ser un objeto.');
        }

        $languages = $decoded['languages'] ?? null;
        $entry = is_array($languages) ? ($languages[$this->language] ?? null) : null;

        // Un idioma sin listas apaga la heurística y deja el corte en manos de la puntuación:
        // subtítulos con cortes peores, pero subtítulos. No es motivo para tumbar el render.
        return $this->lists = [
            'articles' => $this->normalizeList(is_array($entry) ? ($entry['articles'] ?? null) : null),
            'conjunctions' => $this->normalizeList(is_array($entry) ? ($entry['conjunctions'] ?? null) : null),
        ];
    }

    /**
     * @return list<string>
     */
    private function normalizeList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $words = [];

        foreach ($value as $word) {
            if (! is_string($word)) {
                continue;
            }

            $bare = mb_strtolower(trim($word));

            if ($bare !== '' && ! in_array($bare, $words, true)) {
                $words[] = $bare;
            }
        }

        return $words;
    }

    private function bareWord(string $word): string
    {
        $bare = preg_replace('/^\p{P}+|\p{P}+$/u', '', $word);

        return mb_strtolower($bare ?? $word);
    }

    private function normalizeLanguage(string $language): string
    {
        $parts = preg_split('/[-_]/', mb_strtolower(trim($language)), -1, PREG_SPLIT_NO_EMPTY);
        $base = $parts === false ? '' : ($parts[0] ?? '');

        return $base === '' ? 'en' : $base;
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/')
            || preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1;
    }
}
