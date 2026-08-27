<?php

declare(strict_types=1);

namespace App\Services\Video;

use Illuminate\Contracts\Config\Repository;

/**
 * Formato SRT: reparte el texto de un cue en líneas y lo escribe como bloques numerados.
 * También responde si un texto cabe en un cue, que es la única pregunta de formato que
 * necesitan la segmentación y el reparto de tiempo.
 */
final class SrtWriter
{
    private readonly int $maxLineChars;

    private readonly int $maxLines;

    public function __construct(
        private SubtitleLexicon $lexicon,
        Repository $config,
    ) {
        $this->maxLineChars = (int) $config->get('stories.subtitles.max_line_chars', 42);
        $this->maxLines = (int) $config->get('stories.subtitles.max_lines', 2);
    }

    /**
     * @param  list<array{text: string, start: float, end: float, sentence: int}>  $cues
     */
    public function render(array $cues): string
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

    public function fits(string $text): bool
    {
        $lines = $this->wrap($text);

        if ($lines === [] || count($lines) > $this->maxLines) {
            return false;
        }

        foreach ($lines as $line) {
            if (mb_strlen($line) > $this->maxLineChars) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<string>
     */
    public function wrap(string $text): array
    {
        $words = $this->lexicon->words($text);

        if ($words === []) {
            return [];
        }

        $lines = [];
        $current = '';

        foreach ($words as $word) {
            foreach ($this->fitWord($word) as $piece) {
                $candidate = $current === '' ? $piece : $current.' '.$piece;

                if (mb_strlen($candidate) <= $this->maxLineChars) {
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

    private function formatTimestamp(float $seconds): string
    {
        $millisTotal = (int) round(max(0.0, $seconds) * 1000);
        $hours = intdiv($millisTotal, 3_600_000);
        $minutes = intdiv($millisTotal % 3_600_000, 60_000);
        $secs = intdiv($millisTotal % 60_000, 1000);
        $millis = $millisTotal % 1000;

        return sprintf('%02d:%02d:%02d,%03d', $hours, $minutes, $secs, $millis);
    }

    /**
     * @param  list<string>  $lines
     * @return list<string>
     */
    private function keepArticlesWithNouns(array $lines): array
    {
        $count = count($lines);

        for ($index = 0; $index < $count - 1; $index++) {
            $words = $this->lexicon->words($lines[$index]);
            $last = $words[array_key_last($words)] ?? '';

            if (! $this->lexicon->isArticle($last)) {
                continue;
            }

            $nextWords = $this->lexicon->words($lines[$index + 1]);
            $moved = trim($last.' '.implode(' ', $nextWords));

            if (mb_strlen($moved) > $this->maxLineChars) {
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
        if (mb_strlen($word) <= $this->maxLineChars) {
            return [$word];
        }

        $chars = preg_split('//u', $word, -1, PREG_SPLIT_NO_EMPTY);

        if ($chars === false) {
            return [$word];
        }

        $pieces = [];
        $buffer = '';

        foreach ($chars as $char) {
            if (mb_strlen($buffer) >= $this->maxLineChars) {
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
}
