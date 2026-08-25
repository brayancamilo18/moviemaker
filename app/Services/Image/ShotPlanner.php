<?php

declare(strict_types=1);

namespace App\Services\Image;

use App\DataObjects\Shot;
use App\DataObjects\Story;
use Illuminate\Contracts\Config\Repository;

final class ShotPlanner
{
    private const FRAMINGS = [
        'wide establishing',
        'medium shot',
        'close detail',
        'low angle',
        'over the shoulder',
        'extreme close up',
    ];

    private const MOTIONS = [
        'zoom_in',
        'zoom_out',
        'pan_left',
        'pan_right',
    ];

    private const ACTION_VERBS = [
        'burst', 'chase', 'crack', 'crash', 'dash', 'flee', 'grab', 'hit', 'jump',
        'kick', 'leap', 'lunge', 'pull', 'push', 'ran', 'run', 'rush', 'scream',
        'shove', 'slam', 'slammed', 'smash', 'snap', 'spin', 'strike', 'throw',
        'whip', 'yank',
    ];

    private readonly float $minDuration;

    private readonly float $maxDuration;

    private readonly float $targetDuration;

    private readonly float $tensionDuration;

    private readonly float $atmosphereDuration;

    public function __construct(Repository $config)
    {
        $this->minDuration = (float) $config->get('stories.shots.min_duration');
        $this->maxDuration = (float) $config->get('stories.shots.max_duration');
        $this->targetDuration = (float) $config->get('stories.shots.target_duration');
        $this->tensionDuration = (float) $config->get('stories.shots.tension_duration');
        $this->atmosphereDuration = (float) $config->get('stories.shots.atmosphere_duration');
    }

    /**
     * @param  array{sentences?: list<array<string, mixed>>, scenes?: list<array<string, mixed>>}|list<array<string, mixed>>  $timings
     * @return list<Shot>
     */
    public function plan(array $timings, Story $story): array
    {
        $sentences = $this->sentences($timings);
        $sceneEnds = $this->sceneEnds($timings, $sentences);
        $knownScenes = array_map(static fn ($scene): int => $scene->order, $story->scenes);

        $units = [];

        foreach ($this->groupByScene($sentences, $knownScenes) as $sceneOrder => $sceneSentences) {
            $windows = $this->sentenceWindows($sceneSentences, $sceneEnds[$sceneOrder] ?? null);
            $groups = $this->groupWindows($windows);
            $groups = $this->mergeShort($groups);

            foreach ($groups as $group) {
                $units[] = $group;
            }
        }

        return $this->toShots($units);
    }

    /**
     * @param  list<Shot>  $shots
     * @return array{count: int, meanDuration: float, minDuration: float, maxDuration: float, framing: array<string, int>}
     */
    public function stats(array $shots): array
    {
        $framing = array_fill_keys(self::FRAMINGS, 0);

        if ($shots === []) {
            return [
                'count' => 0,
                'meanDuration' => 0.0,
                'minDuration' => 0.0,
                'maxDuration' => 0.0,
                'framing' => $framing,
            ];
        }

        $durations = [];

        foreach ($shots as $shot) {
            $durations[] = $shot->end - $shot->start;
            $framing[$shot->framing] = ($framing[$shot->framing] ?? 0) + 1;
        }

        return [
            'count' => count($shots),
            'meanDuration' => $this->seconds(array_sum($durations) / count($durations)),
            'minDuration' => $this->seconds(min($durations)),
            'maxDuration' => $this->seconds(max($durations)),
            'framing' => $framing,
        ];
    }

    /**
     * Objetivo de duración del grupo. Tensión vs atmósfera; el techo duro sigue siendo max_duration.
     */
    private function pacingTarget(string $text): float
    {
        $words = $this->words($text);
        $wordCount = count($words);
        $sentenceCount = max(count(preg_split('/[.!?]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: []), 1);
        $averageWords = $wordCount / $sentenceCount;

        $score = 0.5;

        if ($averageWords <= 6) {
            $score -= 0.35;
        } elseif ($averageWords <= 10) {
            $score -= 0.15;
        } elseif ($averageWords >= 22) {
            $score += 0.35;
        } elseif ($averageWords >= 16) {
            $score += 0.15;
        }

        if (str_contains($text, '!')) {
            $score -= 0.25;
        }

        $actionHits = 0;
        $lookup = array_flip($words);

        foreach (self::ACTION_VERBS as $verb) {
            if (isset($lookup[$verb])) {
                $actionHits++;
            }
        }

        $score -= min(0.4, $actionHits * 0.12);

        if (str_contains($text, '!') || $actionHits >= 2) {
            $score = min($score, 0.2);
        }

        if ($wordCount >= 40 && $actionHits === 0) {
            $score += 0.15;
        }

        $score = max(0.0, min(1.0, $score));

        return $this->seconds(
            $this->tensionDuration + $score * ($this->atmosphereDuration - $this->tensionDuration),
        );
    }

    /**
     * @param  list<array{sceneOrder: int, start: float, end: float, text: string}>  $windows
     * @return list<array{sceneOrder: int, start: float, end: float, text: string}>
     */
    private function groupWindows(array $windows): array
    {
        $groups = [];
        $current = [];

        foreach ($windows as $window) {
            $pieces = $this->splitOversized($window);

            foreach ($pieces as $piece) {
                if ($current === []) {
                    $current = $piece;

                    continue;
                }

                $proposedText = trim($current['text'].' '.$piece['text']);
                $proposedEnd = $piece['end'];
                $proposedDuration = $proposedEnd - $current['start'];
                $target = min($this->pacingTarget($proposedText), $this->maxDuration);

                if ($proposedDuration <= $target) {
                    $current['end'] = $proposedEnd;
                    $current['text'] = $proposedText;

                    continue;
                }

                $groups[] = $current;
                $current = $piece;
            }
        }

        if ($current !== []) {
            $groups[] = $current;
        }

        return $groups;
    }

    /**
     * @param  list<array{sceneOrder: int, start: float, end: float, text: string}>  $groups
     * @return list<array{sceneOrder: int, start: float, end: float, text: string}>
     */
    private function mergeShort(array $groups): array
    {
        if (count($groups) < 2) {
            return $groups;
        }

        $changed = true;

        while ($changed) {
            $changed = false;

            foreach ($groups as $index => $group) {
                if (($group['end'] - $group['start']) >= $this->minDuration) {
                    continue;
                }

                if (isset($groups[$index + 1])) {
                    $groups[$index] = $this->mergeUnits($group, $groups[$index + 1]);
                    array_splice($groups, $index + 1, 1);
                } elseif ($index > 0) {
                    $groups[$index - 1] = $this->mergeUnits($groups[$index - 1], $group);
                    array_splice($groups, $index, 1);
                } else {
                    continue;
                }

                $changed = true;
                $groups = array_values($groups);
                break;
            }
        }

        return $groups;
    }

    /**
     * @param  array{sceneOrder: int, start: float, end: float, text: string}  $window
     * @return list<array{sceneOrder: int, start: float, end: float, text: string}>
     */
    private function splitOversized(array $window): array
    {
        $duration = $window['end'] - $window['start'];

        if ($duration <= $this->maxDuration) {
            return [$window];
        }

        $packed = [];

        foreach ($this->packByInternalPauses($window) as $piece) {
            if (($piece['end'] - $piece['start']) > $this->maxDuration) {
                foreach ($this->splitEqual($piece) as $slice) {
                    $packed[] = $slice;
                }

                continue;
            }

            $packed[] = $piece;
        }

        return $packed;
    }

    /**
     * @param  array{sceneOrder: int, start: float, end: float, text: string}  $window
     * @return list<array{sceneOrder: int, start: float, end: float, text: string}>
     */
    private function packByInternalPauses(array $window): array
    {
        $chunks = preg_split('/(?<=,)|(?<=\.\.\.)|(?<=…)/u', $window['text']) ?: [$window['text']];
        $chunks = array_values(array_filter(array_map('trim', $chunks), static fn (string $chunk): bool => $chunk !== ''));

        if (count($chunks) < 2) {
            return [$window];
        }

        $weights = array_map(static fn (string $chunk): int => max(mb_strlen($chunk), 1), $chunks);
        $totalWeight = array_sum($weights);
        $cursor = $window['start'];
        $span = $window['end'] - $window['start'];
        $segments = [];

        foreach ($chunks as $index => $chunk) {
            $end = $index === count($chunks) - 1
                ? $window['end']
                : $cursor + $span * ($weights[$index] / $totalWeight);

            $segments[] = [
                'sceneOrder' => $window['sceneOrder'],
                'start' => $cursor,
                'end' => $end,
                'text' => $chunk,
            ];
            $cursor = $end;
        }

        $packed = [];
        $current = null;

        foreach ($segments as $segment) {
            if ($current === null) {
                $current = $segment;

                continue;
            }

            $combined = $segment['end'] - $current['start'];

            if ($combined <= $this->maxDuration) {
                $current['end'] = $segment['end'];
                $current['text'] = trim($current['text'].' '.$segment['text']);

                continue;
            }

            $packed[] = $current;
            $current = $segment;
        }

        if ($current !== null) {
            $packed[] = $current;
        }

        return $packed;
    }

    /**
     * @param  array{sceneOrder: int, start: float, end: float, text: string}  $window
     * @return list<array{sceneOrder: int, start: float, end: float, text: string}>
     */
    private function splitEqual(array $window): array
    {
        $duration = $window['end'] - $window['start'];
        $parts = max(2, (int) ceil($duration / $this->maxDuration));
        $slice = $duration / $parts;
        $words = $this->words($window['text']);
        $wordCount = max(count($words), 1);
        $pieces = [];

        for ($index = 0; $index < $parts; $index++) {
            $from = (int) floor($index * $wordCount / $parts);
            $to = (int) floor(($index + 1) * $wordCount / $parts);
            $pieceWords = array_slice($words, $from, max($to - $from, 1));

            if ($index === $parts - 1) {
                $pieceWords = array_slice($words, $from);
            }

            $pieces[] = [
                'sceneOrder' => $window['sceneOrder'],
                'start' => $window['start'] + $index * $slice,
                'end' => $index === $parts - 1 ? $window['end'] : $window['start'] + ($index + 1) * $slice,
                'text' => implode(' ', $pieceWords) ?: $window['text'],
            ];
        }

        return $pieces;
    }

    /**
     * @param  list<array{sceneOrder: int, start: float, end: float, text: string}>  $units
     * @return list<Shot>
     */
    private function toShots(array $units): array
    {
        $shots = [];
        $framingIndex = 0;
        $motionIndex = 0;
        $previousFraming = null;
        $previousMotion = null;
        $previousScene = null;

        foreach ($units as $index => $unit) {
            $sceneChanged = $previousScene !== $unit['sceneOrder'];
            $framing = $this->nextFraming($framingIndex, $previousFraming, $sceneChanged);
            $duration = $unit['end'] - $unit['start'];
            $motion = $this->nextMotion($motionIndex, $previousMotion, $unit['text'], $duration);

            $shots[] = new Shot(
                order: $index + 1,
                sceneOrder: $unit['sceneOrder'],
                start: $this->seconds($unit['start']),
                end: $this->seconds($unit['end']),
                sourceText: $unit['text'],
                framing: $framing,
                motion: $motion,
                imagePath: null,
            );

            $previousFraming = $framing;
            $previousMotion = $motion;
            $previousScene = $unit['sceneOrder'];
        }

        return $shots;
    }

    private function nextFraming(int &$index, ?string $previous, bool $sceneStart): string
    {
        $count = count(self::FRAMINGS);

        if ($sceneStart) {
            $index = 1;

            if ($previous !== self::FRAMINGS[0]) {
                return self::FRAMINGS[0];
            }

            $index = 2;

            return self::FRAMINGS[1];
        }

        for ($step = 0; $step < $count; $step++) {
            $framing = self::FRAMINGS[$index % $count];
            $index++;

            if ($framing !== $previous) {
                return $framing;
            }
        }

        return self::FRAMINGS[0];
    }

    private function nextMotion(int &$index, ?string $previous, string $text, float $duration): string
    {
        $shortTension = $duration <= $this->tensionDuration
            && $this->pacingTarget($text) <= $this->tensionDuration + 0.35;

        if ($shortTension && $previous !== 'static') {
            return 'static';
        }

        $count = count(self::MOTIONS);

        for ($step = 0; $step < $count; $step++) {
            $motion = self::MOTIONS[$index % $count];
            $index++;

            if ($motion !== $previous) {
                return $motion;
            }
        }

        return self::MOTIONS[0];
    }

    /**
     * @param  list<array{sceneOrder: int, start: float, end: float, text: string}>  $sentences
     * @return array<int, list<array{sceneOrder: int, start: float, end: float, text: string, pauseAfter: float}>>
     */
    private function groupByScene(array $sentences, array $knownScenes): array
    {
        $scenes = [];

        foreach ($sentences as $sentence) {
            $sceneOrder = $sentence['sceneOrder'];

            if ($knownScenes !== [] && ! in_array($sceneOrder, $knownScenes, true)) {
                continue;
            }

            $scenes[$sceneOrder][] = $sentence;
        }

        ksort($scenes);

        return $scenes;
    }

    /**
     * @param  list<array{sceneOrder: int, start: float, end: float, text: string, pauseAfter: float}>  $sentences
     * @return list<array{sceneOrder: int, start: float, end: float, text: string}>
     */
    private function sentenceWindows(array $sentences, ?float $sceneEnd): array
    {
        $windows = [];
        $lastIndex = count($sentences) - 1;

        foreach ($sentences as $index => $sentence) {
            $end = $index === $lastIndex
                ? ($sceneEnd ?? $sentence['end'] + $sentence['pauseAfter'])
                : $sentences[$index + 1]['start'];

            if ($end < $sentence['start']) {
                $end = $sentence['end'] + $sentence['pauseAfter'];
            }

            $windows[] = [
                'sceneOrder' => $sentence['sceneOrder'],
                'start' => $sentence['start'],
                'end' => $end,
                'text' => $sentence['text'],
            ];
        }

        return $windows;
    }

    /**
     * @param  array{sentences?: list<array<string, mixed>>, scenes?: list<array<string, mixed>>}|list<array<string, mixed>>  $timings
     * @return list<array{sceneOrder: int, start: float, end: float, text: string, pauseAfter: float}>
     */
    private function sentences(array $timings): array
    {
        $rows = $timings['sentences'] ?? $timings;
        $sentences = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $text = trim((string) ($row['text'] ?? ''));

            if ($text === '') {
                continue;
            }

            $sentences[] = [
                'sceneOrder' => (int) ($row['sceneOrder'] ?? 1),
                'start' => (float) ($row['start'] ?? 0),
                'end' => (float) ($row['end'] ?? 0),
                'text' => $text,
                'pauseAfter' => (float) ($row['pauseAfter'] ?? 0),
            ];
        }

        return $sentences;
    }

    /**
     * @param  array{scenes?: list<array<string, mixed>>}  $timings
     * @param  list<array{sceneOrder: int, start: float, end: float, text: string, pauseAfter: float}>  $sentences
     * @return array<int, float>
     */
    private function sceneEnds(array $timings, array $sentences): array
    {
        $ends = [];

        foreach ($timings['scenes'] ?? [] as $scene) {
            if (is_array($scene) && isset($scene['order'], $scene['end'])) {
                $ends[(int) $scene['order']] = (float) $scene['end'];
            }
        }

        foreach ($sentences as $sentence) {
            $sceneOrder = $sentence['sceneOrder'];
            $fallback = $sentence['end'] + $sentence['pauseAfter'];
            $ends[$sceneOrder] = max($ends[$sceneOrder] ?? $fallback, $fallback);
        }

        return $ends;
    }

    /**
     * @param  array{sceneOrder: int, start: float, end: float, text: string}  $left
     * @param  array{sceneOrder: int, start: float, end: float, text: string}  $right
     * @return array{sceneOrder: int, start: float, end: float, text: string}
     */
    private function mergeUnits(array $left, array $right): array
    {
        return [
            'sceneOrder' => $left['sceneOrder'],
            'start' => $left['start'],
            'end' => $right['end'],
            'text' => trim($left['text'].' '.$right['text']),
        ];
    }

    /**
     * @return list<string>
     */
    private function words(string $text): array
    {
        $normalized = mb_strtolower($text);
        $normalized = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $normalized) ?? $normalized;
        $words = preg_split('/\s+/u', trim($normalized), -1, PREG_SPLIT_NO_EMPTY);

        return $words === false ? [] : $words;
    }

    private function seconds(float $value): float
    {
        return round($value, 3);
    }
}
