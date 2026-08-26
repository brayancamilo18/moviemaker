<?php

declare(strict_types=1);

namespace App\Services\Video;

use Illuminate\Filesystem\Filesystem;
use InvalidArgumentException;

final class SubtitleGenerator
{
    private const MAX_LINE_CHARS = 42;

    private const MAX_LINES = 2;

    private const MIN_DURATION = 1.2;

    private const MAX_DURATION = 6.0;

    private const GAP = 0.08;

    /**
     * @var list<string>
     */
    private const ARTICLES = [
        'a', 'an', 'the',
        'el', 'la', 'los', 'las', 'un', 'una', 'unos', 'unas', 'lo',
    ];

    /**
     * @var list<string>
     */
    private const CONJUNCTIONS = [
        'and', 'but', 'or', 'nor', 'yet', 'so',
        'because', 'while', 'when', 'if', 'though', 'although',
        'until', 'before', 'after', 'unless', 'whereas', 'since',
        'y', 'e', 'o', 'u', 'pero', 'sino', 'porque', 'aunque', 'cuando', 'mientras',
    ];

    public function __construct(
        private Filesystem $files,
    ) {}

    /**
     * @param  array{sentences?: list<array<string, mixed>>}|list<array<string, mixed>>  $timings
     */
    public function generate(array $timings, string $outputPath): string
    {
        $sentences = $this->sentences($timings);

        if ($sentences === []) {
            throw new InvalidArgumentException('timings.json no tiene frases para subtitular.');
        }

        $cues = [];

        foreach ($sentences as $sentence) {
            foreach ($this->cuesForSentence($sentence) as $cue) {
                $cues[] = $cue;
            }
        }

        $cues = $this->applyTimingRules($cues);
        $srt = $this->render($cues);

        $this->files->ensureDirectoryExists(dirname($outputPath));
        $this->files->put($outputPath, $srt);

        if (! $this->files->isFile($outputPath) || $this->files->size($outputPath) < 1) {
            throw new InvalidArgumentException('No se pudo escribir el SRT.');
        }

        return $outputPath;
    }

    /**
     * @param  array{sentences?: list<array<string, mixed>>}|list<array<string, mixed>>  $timings
     * @return list<array{text: string, start: float, end: float, sentence: int}>
     */
    private function sentences(array $timings): array
    {
        $raw = $timings['sentences'] ?? $timings;

        if (! is_array($raw)) {
            return [];
        }

        $sentences = [];

        foreach ($raw as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            $text = $this->originalText($row);

            if ($text === '') {
                continue;
            }

            $start = round((float) ($row['start'] ?? 0), 3);
            $end = round((float) ($row['end'] ?? $start), 3);

            if ($end <= $start) {
                $end = round($start + self::MIN_DURATION, 3);
            }

            $sentences[] = [
                'text' => $text,
                'start' => $start,
                'end' => $end,
                'sentence' => (int) ($row['order'] ?? $index + 1),
            ];
        }

        return $sentences;
    }

    /**
     * El SRT usa el texto del guion, no Whisper ni la fonética del TTS.
     *
     * @param  array<string, mixed>  $sentence
     */
    private function originalText(array $sentence): string
    {
        $text = trim((string) ($sentence['text'] ?? $sentence['original'] ?? ''));
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    /**
     * @param  array{text: string, start: float, end: float, sentence: int}  $sentence
     * @return list<array{text: string, start: float, end: float, sentence: int}>
     */
    private function cuesForSentence(array $sentence): array
    {
        $parts = $this->splitForLength($sentence['text']);
        $cues = $this->allocateTime($parts, $sentence['start'], $sentence['end'], $sentence['sentence']);

        $overflow = true;

        while ($overflow) {
            $overflow = false;
            $split = [];

            foreach ($cues as $cue) {
                $duration = $cue['end'] - $cue['start'];

                if ($duration <= self::MAX_DURATION + 0.0005 || ! $this->canSplit($cue['text'])) {
                    $split[] = $cue;

                    continue;
                }

                [$left, $right] = $this->splitOnce($cue['text']);

                if ($right === '') {
                    $split[] = $cue;

                    continue;
                }

                $weightLeft = max(1, mb_strlen($left));
                $weightRight = max(1, mb_strlen($right));
                $mid = round(
                    $cue['start'] + $duration * ($weightLeft / ($weightLeft + $weightRight)),
                    3,
                );

                $split[] = [
                    'text' => $left,
                    'start' => $cue['start'],
                    'end' => $mid,
                    'sentence' => $cue['sentence'],
                ];
                $split[] = [
                    'text' => $right,
                    'start' => $mid,
                    'end' => $cue['end'],
                    'sentence' => $cue['sentence'],
                ];
                $overflow = true;
            }

            $cues = $split;
        }

        return $cues;
    }

    /**
     * @return list<string>
     */
    private function splitForLength(string $text): array
    {
        $parts = [];
        $remaining = trim($text);

        while ($remaining !== '') {
            if ($this->fitsAsCue($remaining)) {
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
     * @return array{0: string, 1: string}
     */
    private function nextCue(string $remaining): array
    {
        $words = $this->words($remaining);

        if ($words === []) {
            return ['', ''];
        }

        $max = -1;
        $candidate = '';

        foreach ($words as $index => $word) {
            $try = $candidate === '' ? $word : $candidate.' '.$word;

            if ($this->fitsAsCue($try)) {
                $max = $index;
                $candidate = $try;

                continue;
            }

            break;
        }

        if ($max < 0) {
            $chunk = mb_substr($remaining, 0, self::MAX_LINE_CHARS * self::MAX_LINES);

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
     * @return array{0: string, 1: string}
     */
    private function splitOnce(string $text): array
    {
        if (! $this->fitsAsCue($text)) {
            return $this->nextCue($text);
        }

        $words = $this->words($text);

        if (count($words) < 2) {
            return [$text, ''];
        }

        $prefer = max(0, (int) floor((count($words) - 1) / 2));
        $split = $this->bestSplitAfter($words, count($words) - 2, $prefer);
        $left = implode(' ', array_slice($words, 0, $split + 1));
        $right = implode(' ', array_slice($words, $split + 1));

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

        if ($word === '' || $this->isArticle($word)) {
            return 0;
        }

        $score = 0;

        if ($this->endsWithBreakPunctuation($word)) {
            $score += 80;
        }

        if ($next !== null && $this->isConjunction($next)) {
            $score += 60;
        }

        if ($this->isConjunction($word) && $index > 0) {
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

        if ($this->isArticle($words[$index] ?? '')) {
            $index = max(0, $index - 1);
        }

        if ($this->isConjunction($words[$index] ?? '') && ! $this->endsWithBreakPunctuation($words[$index])) {
            $index = max(0, $index - 1);
        }

        return $index;
    }

    /**
     * @param  list<string>  $parts
     * @return list<array{text: string, start: float, end: float, sentence: int}>
     */
    private function allocateTime(array $parts, float $start, float $end, int $sentence): array
    {
        $weights = [];

        foreach ($parts as $part) {
            $weights[] = max(1, mb_strlen($part));
        }

        $total = array_sum($weights);
        $duration = max(0.001, $end - $start);
        $cursor = $start;
        $last = count($parts) - 1;
        $cues = [];

        foreach ($parts as $index => $part) {
            $slice = $duration * ($weights[$index] / $total);
            $cueEnd = $index === $last ? $end : round($cursor + $slice, 3);

            $cues[] = [
                'text' => $part,
                'start' => round($cursor, 3),
                'end' => $cueEnd,
                'sentence' => $sentence,
            ];
            $cursor = $cueEnd;
        }

        return $cues;
    }

    /**
     * @param  list<array{text: string, start: float, end: float, sentence: int}>  $cues
     * @return list<array{text: string, start: float, end: float, sentence: int}>
     */
    private function applyTimingRules(array $cues): array
    {
        $cues = $this->mergeShortCues($cues);
        $cues = $this->capMaxDuration($cues);
        $cues = $this->separateGaps($cues);
        $cues = $this->extendMinDuration($cues);

        return $cues;
    }

    /**
     * @param  list<array{text: string, start: float, end: float, sentence: int}>  $cues
     * @return list<array{text: string, start: float, end: float, sentence: int}>
     */
    private function mergeShortCues(array $cues): array
    {
        $changed = true;

        while ($changed) {
            $changed = false;
            $merged = [];
            $count = count($cues);

            for ($index = 0; $index < $count; $index++) {
                $cue = $cues[$index];
                $next = $cues[$index + 1] ?? null;
                $duration = $cue['end'] - $cue['start'];

                if (
                    $next !== null
                    && $duration < self::MIN_DURATION
                    && $cue['sentence'] === $next['sentence']
                ) {
                    $text = trim($cue['text'].' '.$next['text']);
                    $span = $next['end'] - $cue['start'];

                    if ($this->fitsAsCue($text) && $span <= self::MAX_DURATION + 0.0005) {
                        $merged[] = [
                            'text' => $text,
                            'start' => $cue['start'],
                            'end' => $next['end'],
                            'sentence' => $cue['sentence'],
                        ];
                        $index++;
                        $changed = true;

                        continue;
                    }
                }

                $merged[] = $cue;
            }

            $cues = $merged;
        }

        return $cues;
    }

    /**
     * @param  list<array{text: string, start: float, end: float, sentence: int}>  $cues
     * @return list<array{text: string, start: float, end: float, sentence: int}>
     */
    private function capMaxDuration(array $cues): array
    {
        foreach ($cues as $index => $cue) {
            $duration = $cue['end'] - $cue['start'];

            if ($duration > self::MAX_DURATION) {
                $cues[$index]['end'] = round($cue['start'] + self::MAX_DURATION, 3);
            }
        }

        return $cues;
    }

    /**
     * @param  list<array{text: string, start: float, end: float, sentence: int}>  $cues
     * @return list<array{text: string, start: float, end: float, sentence: int}>
     */
    private function separateGaps(array $cues): array
    {
        $last = count($cues) - 1;

        for ($index = 0; $index < $last; $index++) {
            $gap = $cues[$index + 1]['start'] - $cues[$index]['end'];

            if ($gap >= self::GAP - 0.0005) {
                continue;
            }

            $need = self::GAP - max(0.0, $gap);
            $prevRoom = max(0.0, ($cues[$index]['end'] - $cues[$index]['start']) - 0.05);
            $fromPrev = min($need / 2, $prevRoom);
            $fromNext = $need - $fromPrev;

            $cues[$index]['end'] = round($cues[$index]['end'] - $fromPrev, 3);
            $cues[$index + 1]['start'] = round($cues[$index + 1]['start'] + $fromNext, 3);

            if ($cues[$index]['end'] <= $cues[$index]['start']) {
                $cues[$index]['end'] = round($cues[$index]['start'] + 0.05, 3);
            }

            if ($cues[$index + 1]['end'] <= $cues[$index + 1]['start']) {
                $cues[$index + 1]['end'] = round($cues[$index + 1]['start'] + 0.05, 3);
            }
        }

        return $cues;
    }

    /**
     * @param  list<array{text: string, start: float, end: float, sentence: int}>  $cues
     * @return list<array{text: string, start: float, end: float, sentence: int}>
     */
    private function extendMinDuration(array $cues): array
    {
        $last = count($cues) - 1;

        foreach ($cues as $index => $cue) {
            $duration = $cue['end'] - $cue['start'];

            if ($duration >= self::MIN_DURATION - 0.0005) {
                continue;
            }

            $limit = $index < $last
                ? $cues[$index + 1]['start'] - self::GAP
                : $cue['start'] + self::MIN_DURATION;
            $cues[$index]['end'] = round(min($cue['start'] + self::MIN_DURATION, max($cue['end'], $limit)), 3);

            if ($cues[$index]['end'] <= $cues[$index]['start']) {
                $cues[$index]['end'] = round($cues[$index]['start'] + 0.05, 3);
            }
        }

        return $cues;
    }

    /**
     * @param  list<array{text: string, start: float, end: float, sentence: int}>  $cues
     */
    private function render(array $cues): string
    {
        $blocks = [];

        foreach ($cues as $index => $cue) {
            $lines = $this->wrap($cue['text']);
            $end = $cue['end'];

            if ($this->formatTimestamp($end) === $this->formatTimestamp($cue['start'])) {
                $end = $cue['start'] + 0.001;
            }

            $blocks[] = sprintf(
                "%d\n%s --> %s\n%s",
                $index + 1,
                $this->formatTimestamp($cue['start']),
                $this->formatTimestamp($end),
                implode("\n", $lines),
            );
        }

        return implode("\n\n", $blocks)."\n";
    }

    private function formatTimestamp(float $seconds): string
    {
        $millisTotal = (int) round(max(0.0, $seconds) * 1000);
        $hours = intdiv($millisTotal, 3_600_000);
        $minutes = intdiv($millisTotal % 3_600_000, 60_000);
        $secs = intdiv($millisTotal % 60_000, 1000);
        $millis = $millisTotal % 1000;

        return sprintf('%02d:%02d:%02d,%03d', $hours, $minutes, $secs, $millis);
    }

    private function fitsAsCue(string $text): bool
    {
        $lines = $this->wrap($text);

        if ($lines === [] || count($lines) > self::MAX_LINES) {
            return false;
        }

        foreach ($lines as $line) {
            if (mb_strlen($line) > self::MAX_LINE_CHARS) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<string>
     */
    private function wrap(string $text): array
    {
        $words = $this->words($text);

        if ($words === []) {
            return [];
        }

        $lines = [];
        $current = '';

        foreach ($words as $word) {
            foreach ($this->fitWord($word) as $piece) {
                $candidate = $current === '' ? $piece : $current.' '.$piece;

                if (mb_strlen($candidate) <= self::MAX_LINE_CHARS) {
                    $current = $candidate;

                    continue;
                }

                if ($current !== '') {
                    $lines[] = $current;
                }

                $current = $piece;
            }
        }

        if ($current !== '') {
            $lines[] = $current;
        }

        return $this->keepArticlesWithNouns($lines);
    }

    /**
     * @param  list<string>  $lines
     * @return list<string>
     */
    private function keepArticlesWithNouns(array $lines): array
    {
        $count = count($lines);

        for ($index = 0; $index < $count - 1; $index++) {
            $words = $this->words($lines[$index]);
            $last = $words[array_key_last($words)] ?? '';

            if (! $this->isArticle($last)) {
                continue;
            }

            $nextWords = $this->words($lines[$index + 1]);
            $moved = trim($last.' '.implode(' ', $nextWords));

            if (mb_strlen($moved) > self::MAX_LINE_CHARS) {
                continue;
            }

            array_pop($words);
            $lines[$index] = implode(' ', $words);
            $lines[$index + 1] = $moved;

            if ($lines[$index] === '') {
                array_splice($lines, $index, 1);
                $count = count($lines);
                $index--;
            }
        }

        return array_values(array_filter($lines, static fn (string $line): bool => $line !== ''));
    }

    /**
     * @return list<string>
     */
    private function fitWord(string $word): array
    {
        if (mb_strlen($word) <= self::MAX_LINE_CHARS) {
            return [$word];
        }

        $chars = preg_split('//u', $word, -1, PREG_SPLIT_NO_EMPTY);

        if ($chars === false) {
            return [$word];
        }

        $pieces = [];
        $buffer = '';

        foreach ($chars as $char) {
            if (mb_strlen($buffer) >= self::MAX_LINE_CHARS) {
                $pieces[] = $buffer;
                $buffer = '';
            }

            $buffer .= $char;
        }

        if ($buffer !== '') {
            $pieces[] = $buffer;
        }

        return $pieces === [] ? [$word] : $pieces;
    }

    private function canSplit(string $text): bool
    {
        $words = $this->words($text);

        if (count($words) < 2) {
            return false;
        }

        if (count($words) === 2 && $this->isArticle($words[0])) {
            return false;
        }

        return true;
    }

    /**
     * @return list<string>
     */
    private function words(string $text): array
    {
        $words = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY);

        return $words === false ? [] : array_values($words);
    }

    private function isArticle(string $word): bool
    {
        return in_array($this->bareWord($word), self::ARTICLES, true);
    }

    private function isConjunction(string $word): bool
    {
        return in_array($this->bareWord($word), self::CONJUNCTIONS, true);
    }

    private function endsWithBreakPunctuation(string $word): bool
    {
        return (bool) preg_match('/[,;:—–]$/u', $word);
    }

    private function bareWord(string $word): string
    {
        $bare = preg_replace('/^\p{P}+|\p{P}+$/u', '', $word);

        return mb_strtolower($bare ?? $word);
    }
}
