<?php

declare(strict_types=1);

namespace App\Services\Audio;

use App\DataObjects\NarrationSentence;
use Illuminate\Contracts\Config\Repository;

final class SentenceSplitter
{
    private const ABBREVIATIONS = ['mr', 'mrs', 'dr', 'st', 'jr', 'vs'];

    private readonly float $pauseSentence;

    private readonly float $pauseQuestionOrExclamation;

    private readonly float $pauseEllipsis;

    private readonly float $pauseBetweenScenes;

    public function __construct(Repository $config)
    {
        $pauses = $config->get('stories.tts.pauses');

        $this->pauseSentence = (float) $pauses['sentence'];
        $this->pauseQuestionOrExclamation = (float) $pauses['question_or_exclamation'];
        $this->pauseEllipsis = (float) $pauses['ellipsis'];
        $this->pauseBetweenScenes = (float) $pauses['between_scenes'];
    }

    /**
     * @return list<NarrationSentence>
     */
    public function split(string $text): array
    {
        return $this->splitScenes([
            ['order' => 1, 'text' => $text],
        ]);
    }

    /**
     * Parte narración por escenas para aplicar la pausa entre_escenas al final de cada una.
     *
     * Se parte siempre el texto del guion. La fonética se aplica frase a frase con $ttsText, así
     * que cada frase lleva su propia versión hablada y no hay dos listas que emparejar.
     *
     * @param  list<array{order?: int, text?: string, narration?: string}>  $scenes
     * @param  (callable(string): string)|null  $ttsText
     * @return list<NarrationSentence>
     */
    public function splitScenes(array $scenes, ?callable $ttsText = null): array
    {
        $sentences = [];
        $order = 1;
        $lastIndex = count($scenes) - 1;

        foreach ($scenes as $index => $scene) {
            $sceneOrder = (int) ($scene['order'] ?? $index + 1);
            $parts = $this->rawSentences((string) ($scene['text'] ?? $scene['narration'] ?? ''));
            $lastPart = count($parts) - 1;
            $isLastScene = $index === $lastIndex;

            foreach ($parts as $partIndex => $part) {
                $betweenScenes = ! $isLastScene && $partIndex === $lastPart;

                $sentences[] = new NarrationSentence(
                    order: $order,
                    sceneOrder: $sceneOrder,
                    text: $part,
                    pauseAfter: $this->pauseAfter($part, $betweenScenes),
                    ttsText: $ttsText === null ? $part : $ttsText($part),
                );
                $order++;
            }
        }

        return $sentences;
    }

    /**
     * Corta en . ? ! seguidos de espacio y mayúscula, sin romper abreviaturas,
     * iniciales, puntos suspensivos ni diálogo entrecomillado.
     *
     * @return list<string>
     */
    private function rawSentences(string $text): array
    {
        $chars = preg_split('//u', trim($text), -1, PREG_SPLIT_NO_EMPTY);

        if ($chars === false || $chars === []) {
            return [];
        }

        $sentences = [];
        $buffer = '';
        $inQuotes = false;
        $length = count($chars);
        $i = 0;

        while ($i < $length) {
            if (! $inQuotes && $this->ellipsisLength($chars, $i) > 0) {
                $dots = $this->ellipsisLength($chars, $i);
                $buffer .= implode('', array_slice($chars, $i, $dots));
                $i += $dots;

                continue;
            }

            $char = $chars[$i];

            if ($this->isQuote($char)) {
                $wasInside = $inQuotes;
                $buffer .= $char;
                $inQuotes = ! $inQuotes;

                if ($wasInside && $this->lookaheadIsNewSentence($chars, $i)) {
                    $this->pushSentence($sentences, $buffer);
                    $buffer = '';
                    $i = $this->skipWhitespace($chars, $i + 1);

                    continue;
                }

                $i++;

                continue;
            }

            if (! $inQuotes && $this->isTerminator($char) && $this->isBoundary($chars, $i, $buffer)) {
                $buffer .= $char;
                $this->pushSentence($sentences, $buffer);
                $buffer = '';
                $i = $this->skipWhitespace($chars, $i + 1);

                continue;
            }

            $buffer .= $char;
            $i++;
        }

        $this->pushSentence($sentences, $buffer);

        return $sentences;
    }

    /**
     * @param  list<string>  $sentences
     */
    private function pushSentence(array &$sentences, string $buffer): void
    {
        $sentence = trim($buffer);

        if (mb_strlen($sentence) >= 3) {
            $sentences[] = $sentence;
        }
    }

    /**
     * @param  list<string>  $chars
     */
    private function isBoundary(array $chars, int $index, string $buffer): bool
    {
        if (! $this->lookaheadIsNewSentence($chars, $index)) {
            return false;
        }

        if ($chars[$index] !== '.') {
            return true;
        }

        $word = $this->wordBeforePeriod($buffer);

        if ($word === '') {
            return true;
        }

        if (in_array(mb_strtolower($word), self::ABBREVIATIONS, true)) {
            return false;
        }

        return mb_strlen($word) !== 1 || ! (bool) preg_match('/^\p{Lu}$/u', $word);
    }

    /**
     * @param  list<string>  $chars
     */
    private function lookaheadIsNewSentence(array $chars, int $index): bool
    {
        $i = $index + 1;
        $length = count($chars);

        while ($i < $length && $this->isQuote($chars[$i])) {
            $i++;
        }

        if ($i >= $length) {
            return true;
        }

        $hadSpace = false;

        while ($i < $length && $this->isSpace($chars[$i])) {
            $hadSpace = true;
            $i++;
        }

        if ($i >= $length) {
            return true;
        }

        while ($i < $length && $this->isQuote($chars[$i])) {
            $i++;
        }

        if ($i >= $length) {
            return true;
        }

        return $hadSpace && $this->isUppercase($chars[$i]);
    }

    /**
     * @param  list<string>  $chars
     */
    private function skipWhitespace(array $chars, int $index): int
    {
        $length = count($chars);

        while ($index < $length && $this->isSpace($chars[$index])) {
            $index++;
        }

        return $index;
    }

    /**
     * @param  list<string>  $chars
     */
    private function ellipsisLength(array $chars, int $index): int
    {
        if (($chars[$index] ?? '') === '…') {
            return 1;
        }

        if (
            ($chars[$index] ?? '') === '.'
            && ($chars[$index + 1] ?? '') === '.'
            && ($chars[$index + 2] ?? '') === '.'
        ) {
            return 3;
        }

        return 0;
    }

    private function wordBeforePeriod(string $buffer): string
    {
        if (! preg_match('/([^\s.]+)$/u', rtrim($buffer), $matches)) {
            return '';
        }

        return $matches[1];
    }

    private function pauseAfter(string $sentence, bool $betweenScenes): float
    {
        // El corte entre escenas manda sobre ? ! y puntos suspensivos.
        if ($betweenScenes) {
            return $this->pauseBetweenScenes;
        }

        if (str_contains($sentence, '...') || str_contains($sentence, '…')) {
            return $this->pauseEllipsis;
        }

        $ending = rtrim($sentence, " \t\"'”’");
        $last = mb_substr($ending, -1);

        if ($last === '?' || $last === '!') {
            return $this->pauseQuestionOrExclamation;
        }

        return $this->pauseSentence;
    }

    private function isTerminator(string $char): bool
    {
        return $char === '.' || $char === '?' || $char === '!';
    }

    private function isQuote(string $char): bool
    {
        return $char === '"' || $char === '“' || $char === '”';
    }

    private function isSpace(string $char): bool
    {
        return (bool) preg_match('/^\s$/u', $char);
    }

    private function isUppercase(string $char): bool
    {
        return (bool) preg_match('/^\p{Lu}$/u', $char);
    }
}
