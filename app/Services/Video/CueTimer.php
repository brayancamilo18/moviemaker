<?php

declare(strict_types=1);

namespace App\Services\Video;

use Illuminate\Contracts\Config\Repository;

/**
 * Reparto de tiempo: convierte los trozos de una frase en cues con entrada y salida, y aplica las
 * reglas de legibilidad. El contrato de salida de applyRules() es una sola invariante: para todo i,
 * cues[i].end + gap <= cues[i+1].start.
 */
final class CueTimer
{
    /**
     * Duración mínima de emergencia cuando corregir un solape deja un cue sin sitio.
     */
    private const SAFETY_FLOOR = 0.05;

    /**
     * Margen de comparación: los tiempos se redondean a milésimas y no admiten igualdad exacta.
     */
    private const EPSILON = 0.0005;

    private readonly float $minDuration;

    private readonly float $maxDuration;

    private readonly float $gap;

    public function __construct(
        private CueSegmenter $segmenter,
        private SrtWriter $writer,
        Repository $config,
    ) {
        $this->minDuration = (float) $config->get('stories.subtitles.min_duration', 1.2);
        $this->maxDuration = (float) $config->get('stories.subtitles.max_duration', 6.0);
        $this->gap = (float) $config->get('stories.subtitles.gap', 0.08);
    }

    /**
     * @param  list<string>  $parts
     * @return list<array{text: string, start: float, end: float, sentence: int}>
     */
    public function allocate(array $parts, float $start, float $end, int $sentence): array
    {
        $cues = $this->spread($parts, $start, $end, $sentence);
        $overflow = true;

        while ($overflow) {
            $overflow = false;
            $split = [];

            foreach ($cues as $cue) {
                $duration = $cue['end'] - $cue['start'];

                if ($duration <= $this->maxDuration + self::EPSILON || ! $this->segmenter->canSplit($cue['text'])) {
                    $split[] = $cue;

                    continue;
                }

                [$left, $right] = $this->segmenter->split($cue['text']);

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
     * @param  list<array{text: string, start: float, end: float, sentence: int}>  $cues
     * @return list<array{text: string, start: float, end: float, sentence: int}>
     */
    public function applyRules(array $cues): array
    {
        $cues = $this->sortByStart($cues);
        $cues = $this->mergeShortCues($cues);
        $cues = $this->capMaxDuration($cues);
        $cues = $this->separateGaps($cues);
        $cues = $this->extendMinDuration($cues);

        return $cues;
    }

    /**
     * @param  list<string>  $parts
     * @return list<array{text: string, start: float, end: float, sentence: int}>
     */
    private function spread(array $parts, float $start, float $end, int $sentence): array
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
     * Las reglas de tiempo recorren la lista hacia delante y asumen que start es monótono. El
     * orden de entrada no lo garantiza: timings.json puede llegar con las frases desordenadas.
     *
     * @param  list<array{text: string, start: float, end: float, sentence: int}>  $cues
     * @return list<array{text: string, start: float, end: float, sentence: int}>
     */
    private function sortByStart(array $cues): array
    {
        usort(
            $cues,
            static fn (array $left, array $right): int => [$left['start'], $left['sentence']]
                <=> [$right['start'], $right['sentence']],
        );

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
                    && $duration < $this->minDuration
                    && $cue['sentence'] === $next['sentence']
                ) {
                    $text = trim($cue['text'].' '.$next['text']);
                    $span = $next['end'] - $cue['start'];

                    if ($this->writer->fits($text) && $span <= $this->maxDuration + self::EPSILON) {
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

            if ($duration > $this->maxDuration) {
                $cues[$index]['end'] = round($cue['start'] + $this->maxDuration, 3);
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

            if ($gap >= $this->gap - self::EPSILON) {
                continue;
            }

            // Un hueco negativo es un solape: hay que recuperar el solape entero además del hueco.
            // Aplanarlo a cero dejaba los cues pisándose y el reproductor los superponía.
            $need = $this->gap - $gap;
            $prevRoom = max(0.0, ($cues[$index]['end'] - $cues[$index]['start']) - self::SAFETY_FLOOR);
            $fromPrev = min($need / 2, $prevRoom);
            $fromNext = $need - $fromPrev;

            $cues[$index]['end'] = round($cues[$index]['end'] - $fromPrev, 3);
            $cues[$index + 1]['start'] = round($cues[$index + 1]['start'] + $fromNext, 3);

            if ($cues[$index]['end'] <= $cues[$index]['start']) {
                $cues[$index]['end'] = round($cues[$index]['start'] + self::SAFETY_FLOOR, 3);
            }

            if ($cues[$index + 1]['end'] <= $cues[$index + 1]['start']) {
                $cues[$index + 1]['end'] = round($cues[$index + 1]['start'] + self::SAFETY_FLOOR, 3);
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

            if ($duration >= $this->minDuration - self::EPSILON) {
                continue;
            }

            $limit = $index < $last
                ? $cues[$index + 1]['start'] - $this->gap
                : $cue['start'] + $this->minDuration;
            $cues[$index]['end'] = round(min($cue['start'] + $this->minDuration, max($cue['end'], $limit)), 3);

            if ($cues[$index]['end'] <= $cues[$index]['start']) {
                $cues[$index]['end'] = round($cues[$index]['start'] + self::SAFETY_FLOOR, 3);
            }
        }

        return $cues;
    }
}
