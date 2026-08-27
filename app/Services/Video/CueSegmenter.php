<?php

declare(strict_types=1);

namespace App\Services\Video;

use Illuminate\Contracts\Config\Repository;

/**
 * Segmentación: parte el texto de una frase en trozos que caben en un cue y elige por dónde
 * cortar. No sabe nada de tiempos ni de formato.
 */
final class CueSegmenter
{
    private readonly int $maxLineChars;

    private readonly int $maxLines;

    public function __construct(
        private SrtWriter $writer,
        private SubtitleLexicon $lexicon,
        Repository $config,
    ) {
        $this->maxLineChars = (int) $config->get('stories.subtitles.max_line_chars', 42);
        $this->maxLines = (int) $config->get('stories.subtitles.max_lines', 2);
    }

    /**
     * @return list<string>
     */
    public function segment(string $text): array
    {
        $parts = [];
        $remaining = trim($text);

        while ($remaining !== '') {
            if ($this->writer->fits($remaining)) {
                $parts[] = $remaining;

                break;
            }

            [$cue, $remaining] = $this->nextCue($remaining);

            if ($cue === '') {
                $parts[] = $remaining;

                break;
            }

            $parts[] = $cue;
            $remaining = trim($remaining);
        }

        return $parts === [] ? [$text] : $parts;
    }

    /**
     * Parte un trozo que ya cabe en dos mitades. Lo pide el reparto de tiempo cuando un cue dura
     * más de lo admitido: el texto cabe, pero se lee demasiado despacio.
     *
     * @return array{0: string, 1: string}
     */
    public function split(string $text): array
    {
        if (! $this->writer->fits($text)) {
            return $this->nextCue($text);
        }

        $words = $this->lexicon->words($text);

        if (count($words) < 2) {
            return [$text, ''];
        }

        $prefer = max(0, (int) floor((count($words) - 1) / 2));
        $split = $this->bestSplitAfter($words, count($words) - 2, $prefer);
        $left = implode(' ', array_slice($words, 0, $split + 1));
        $right = implode(' ', array_slice($words, $split + 1));

        return [$left, $right];
    }

    public function canSplit(string $text): bool
    {
        $words = $this->lexicon->words($text);

        if (count($words) < 2) {
            return false;
        }

        if (count($words) === 2 && $this->lexicon->isArticle($words[0])) {
            return false;
        }

        return true;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function nextCue(string $remaining): array
    {
        $words = $this->lexicon->words($remaining);

        if ($words === []) {
            return ['', ''];
        }

        $max = -1;
        $candidate = '';

        foreach ($words as $index => $word) {
            $try = $candidate === '' ? $word : $candidate.' '.$word;

            if ($this->writer->fits($try)) {
                $max = $index;
                $candidate = $try;

                continue;
            }

            break;
        }

        if ($max < 0) {
            $chunk = mb_substr($remaining, 0, $this->maxLineChars * $this->maxLines);

            return [trim($chunk), trim(mb_substr($remaining, mb_strlen($chunk)))];
        }

        if ($max >= count($words) - 1) {
            return [$candidate, ''];
        }

        $split = $this->bestSplitAfter($words, $max);
        $left = implode(' ', array_slice($words, 0, $split + 1));
        $right = implode(' ', array_slice($words, $split + 1));

        if ($right === '' && $split < count($words) - 1) {
            $left = implode(' ', array_slice($words, 0, $max + 1));
            $right = implode(' ', array_slice($words, $max + 1));
        }

        return [$left, $right];
    }

    /**
     * @param  list<string>  $words
     */
    private function bestSplitAfter(array $words, int $max, ?int $prefer = null): int
    {
        $max = max(0, min($max, count($words) - 1));

        if ($prefer !== null) {
            $prefer = max(0, min($prefer, $max));
            $found = $this->bestQualitySplit($words, max(0, $prefer - 3), min($max, $prefer + 3))
                ?? $this->bestQualitySplit($words, 0, $max);

            return $this->avoidArticleSplit($words, $found ?? $prefer);
        }

        $lateFrom = (int) ceil($max * 0.5);
        $found = $this->bestQualitySplit($words, $lateFrom, $max)
            ?? $this->bestQualitySplit($words, 0, $max);

        return $this->avoidArticleSplit($words, $found ?? $max);
    }

    /**
     * @param  list<string>  $words
     */
    private function bestQualitySplit(array $words, int $from, int $to): ?int
    {
        $best = null;
        $bestScore = 0;

        for ($index = $to; $index >= $from; $index--) {
            $score = $this->qualityScore($words, $index);

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $index;
            }
        }

        return $best;
    }

    /**
     * @param  list<string>  $words
     */
    private function qualityScore(array $words, int $index): int
    {
        $word = $words[$index] ?? '';
        $next = $words[$index + 1] ?? null;

        if ($word === '' || $this->lexicon->isArticle($word)) {
            return 0;
        }

        $score = 0;

        if ($this->endsWithBreakPunctuation($word)) {
            $score += 80;
        }

        if ($next !== null && $this->lexicon->isConjunction($next)) {
            $score += 60;
        }

        if ($this->lexicon->isConjunction($word) && $index > 0) {
            $score += 15;
        }

        return $score;
    }

    /**
     * @param  list<string>  $words
     */
    private function avoidArticleSplit(array $words, int $index): int
    {
        $index = max(0, min($index, count($words) - 1));

        if ($this->lexicon->isArticle($words[$index] ?? '')) {
            $index = max(0, $index - 1);
        }

        if ($this->lexicon->isConjunction($words[$index] ?? '') && ! $this->endsWithBreakPunctuation($words[$index])) {
            $index = max(0, $index - 1);
        }

        return $index;
    }

    private function endsWithBreakPunctuation(string $word): bool
    {
        return (bool) preg_match('/[,;:—–]$/u', $word);
    }
}
