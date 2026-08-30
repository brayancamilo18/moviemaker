<?php

declare(strict_types=1);

namespace App\Services\Image;

use App\DataObjects\Shot;
use App\DataObjects\Story;
use App\DataObjects\StoryScene;
use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;
use RuntimeException;

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

    /** Versión del algoritmo de planificación persistida en shots.json. */
    public const VERSION = 4;

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

    private readonly float $maxHoldSlack;

    private readonly int $outroSceneOrder;

    public function __construct(Repository $config)
    {
        $this->minDuration = (float) $config->get('stories.shots.min_duration');
        $this->maxDuration = (float) $config->get('stories.shots.max_duration');
        $this->targetDuration = (float) $config->get('stories.shots.target_duration');
        $this->tensionDuration = (float) $config->get('stories.shots.tension_duration');
        $this->atmosphereDuration = (float) $config->get('stories.shots.atmosphere_duration');
        $this->maxHoldSlack = (float) $config->get('stories.shots.max_hold_slack');
        $this->outroSceneOrder = (int) $config->get('stories.story.outro.scene_order');
    }

    /**
     * @param  array{sentences?: list<array<string, mixed>>, scenes?: list<array<string, mixed>>}|list<array<string, mixed>>  $timings
     * @return list<Shot>
     */
    public function plan(array $timings, Story $story, float $audioDuration): array
    {
        $sentences = $this->sentences($timings);
        $sceneEnds = $this->sceneEnds($timings, $sentences);
        $knownScenes = array_map(static fn ($scene): int => $scene->order, $story->scenes);

        $units = [];

        foreach ($this->groupByScene($sentences, $knownScenes) as $sceneOrder => $sceneSentences) {
            $windows = $this->sentenceWindows($sceneSentences, $sceneEnds[$sceneOrder] ?? null);

            if ($sceneOrder === $this->outroSceneOrder) {
                if ($windows !== []) {
                    $units[] = $this->outroUnit($windows);
                }

                continue;
            }

            $windows = $this->attachBeats($windows, $story);
            $groups = $this->groupWindows($windows);
            $groups = $this->mergeShort($groups);

            foreach ($groups as $group) {
                $units[] = $group;
            }
        }

        return $this->tile($this->toShots($units, $story), $audioDuration);
    }

    /**
     * @param  list<Shot>  $shots
     * @return array{count: int, meanDuration: float, minDuration: float, maxDuration: float, framing: array<string, int>, subject: array<string, int>, threatStage: array<string, int>}
     */
    public function stats(array $shots): array
    {
        $framing = array_fill_keys(self::FRAMINGS, 0);
        $subject = array_fill_keys(['protagonist', 'threat', 'both', 'environment', 'detail'], 0);
        $threatStage = array_fill_keys(['hint', 'presence', 'reveal'], 0);

        if ($shots === []) {
            return [
                'count' => 0,
                'meanDuration' => 0.0,
                'minDuration' => 0.0,
                'maxDuration' => 0.0,
                'framing' => $framing,
                'subject' => $subject,
                'threatStage' => $threatStage,
            ];
        }

        $durations = [];

        foreach ($shots as $shot) {
            if ($shot->isOutro) {
                continue;
            }

            $durations[] = $shot->end - $shot->start;
            $framing[$shot->framing] = ($framing[$shot->framing] ?? 0) + 1;
            $subject[$shot->subject] = ($subject[$shot->subject] ?? 0) + 1;

            if (is_string($shot->threatStage) && $shot->threatStage !== '') {
                $threatStage[$shot->threatStage] = ($threatStage[$shot->threatStage] ?? 0) + 1;
            }
        }

        if ($durations === []) {
            return [
                'count' => 0,
                'meanDuration' => 0.0,
                'minDuration' => 0.0,
                'maxDuration' => 0.0,
                'framing' => $framing,
                'subject' => $subject,
                'threatStage' => $threatStage,
            ];
        }

        return [
            'count' => count($durations),
            'meanDuration' => $this->seconds(array_sum($durations) / count($durations)),
            'minDuration' => $this->seconds(min($durations)),
            'maxDuration' => $this->seconds(max($durations)),
            'framing' => $framing,
            'subject' => $subject,
            'threatStage' => $threatStage,
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
     * @param  list<array{sceneOrder: int, start: float, end: float, text: string, beatIndex: int, subject: string, threatStage: ?string}>  $windows
     * @return list<array{sceneOrder: int, start: float, end: float, text: string, beatIndex: int, subject: string, threatStage: ?string}>
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
     * @param  list<array{sceneOrder: int, start: float, end: float, text: string, beatIndex: int, subject: string, threatStage: ?string}>  $groups
     * @return list<array{sceneOrder: int, start: float, end: float, text: string, beatIndex: int, subject: string, threatStage: ?string}>
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
     * @param  array{sceneOrder: int, start: float, end: float, text: string, beatIndex: int, subject: string, threatStage: ?string}  $window
     * @return list<array{sceneOrder: int, start: float, end: float, text: string, beatIndex: int, subject: string, threatStage: ?string}>
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
     * @param  array{sceneOrder: int, start: float, end: float, text: string, beatIndex: int, subject: string, threatStage: ?string}  $window
     * @return list<array{sceneOrder: int, start: float, end: float, text: string, beatIndex: int, subject: string, threatStage: ?string}>
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

            $segments[] = $this->unit($window, $cursor, $end, $chunk);
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
     * @param  array{sceneOrder: int, start: float, end: float, text: string, beatIndex: int, subject: string, threatStage: ?string}  $window
     * @return list<array{sceneOrder: int, start: float, end: float, text: string, beatIndex: int, subject: string, threatStage: ?string}>
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

            $pieces[] = $this->unit(
                $window,
                $window['start'] + $index * $slice,
                $index === $parts - 1 ? $window['end'] : $window['start'] + ($index + 1) * $slice,
                implode(' ', $pieceWords) ?: $window['text'],
            );
        }

        return $pieces;
    }

    /**
     * @param  list<array{sceneOrder: int, start: float, end: float, text: string, beatIndex: int, subject: string, threatStage: ?string}>  $units
     * @return list<Shot>
     */
    private function toShots(array $units, Story $story): array
    {
        $shots = [];
        $framingIndex = 0;
        $motionIndex = 0;
        $previousFraming = null;
        $previousMotion = null;
        $previousScene = null;
        $runKey = null;
        $run = 0;

        foreach ($units as $index => $unit) {
            $key = $unit['sceneOrder'].':'.$unit['beatIndex'];

            if ($key === $runKey) {
                $run++;
            } else {
                $run = 0;
                $runKey = $key;
            }

            $isOutro = $unit['sceneOrder'] === $this->outroSceneOrder;
            $subject = $isOutro ? 'environment' : $this->subjectForRun($unit['subject'], $run);
            $threatStage = $isOutro || ! in_array($subject, ['threat', 'both'], true)
                ? null
                : $unit['threatStage'];

            $sceneChanged = $previousScene !== $unit['sceneOrder'];
            $framing = $isOutro
                ? 'wide establishing'
                : $this->nextFraming(
                    $framingIndex,
                    $previousFraming,
                    $sceneChanged,
                    $subject,
                    $threatStage,
                );
            $duration = $unit['end'] - $unit['start'];
            $motion = $isOutro
                ? 'static'
                : $this->nextMotion($motionIndex, $previousMotion, $unit['text'], $duration);

            $shots[] = new Shot(
                order: $index + 1,
                sceneOrder: $unit['sceneOrder'],
                start: $this->seconds($unit['start']),
                end: $this->seconds($unit['end']),
                sourceText: $unit['text'],
                framing: $framing,
                motion: $motion,
                subject: $subject,
                threatStage: $threatStage,
                description: trim($this->scene($story, $unit['sceneOrder'])?->visualSummary ?? ''),
                characterSlugs: [],
                imagePath: null,
                isOutro: $isOutro,
            );

            $previousFraming = $framing;
            $previousMotion = $motion;
            $previousScene = $unit['sceneOrder'];
        }

        return $shots;
    }

    /**
     * Cubre la línea de tiempo del máster sin huecos: el primer plano arranca en 0,
     * cada uno se sostiene hasta el siguiente y el último llega a $audioDuration.
     *
     * @param  list<Shot>  $shots
     * @return list<Shot>
     */
    private function tile(array $shots, float $audioDuration): array
    {
        if ($shots === []) {
            throw new RuntimeException('No hay planos para teselar sobre el audio.');
        }

        if ($audioDuration <= 0) {
            throw new InvalidArgumentException('La duración del audio debe ser mayor que 0.');
        }

        $audioDuration = $this->seconds($audioDuration);
        $windows = [];

        foreach ($shots as $index => $shot) {
            $start = $index === 0 ? 0.0 : $this->seconds($shot->start);
            $next = $shots[$index + 1] ?? null;
            $end = $next === null ? $audioDuration : $this->seconds($next->start);

            if ($end + 0.0005 < $start) {
                throw new RuntimeException(sprintf(
                    'El teselado produce un intervalo invertido en el plano %d (%.3f–%.3f).',
                    $shot->order,
                    $start,
                    $end,
                ));
            }

            $end = max($start, $end);

            if ($shot->isOutro) {
                $windows[] = [
                    'shot' => $shot,
                    'start' => $start,
                    'end' => $end,
                    'continuation' => false,
                ];

                continue;
            }

            if ($next === null) {
                $speechEnd = max($start, min($this->seconds($shot->end), $end));

                if ($speechEnd > $start) {
                    foreach ($this->splitOversizedHold($shot, $start, $speechEnd) as $piece) {
                        $windows[] = $piece;
                    }
                }

                if ($end > $speechEnd + 0.0005) {
                    foreach ($this->splitOversizedHold($shot, $speechEnd, $end) as $piece) {
                        $piece['continuation'] = false;
                        $piece['closing'] = true;
                        $windows[] = $piece;
                    }
                }
            } else {
                foreach ($this->splitOversizedHold($shot, $start, $end) as $piece) {
                    $windows[] = $piece;
                }
            }
        }

        $tiled = [];
        $framingIndex = 0;
        $motionIndex = 0;
        $previousFraming = null;
        $previousMotion = null;

        foreach ($windows as $index => $piece) {
            $source = $piece['shot'];
            $closing = $piece['closing'] ?? false;
            $framing = $source->framing;
            $motion = $source->motion;
            $subject = $source->subject;
            $threatStage = $source->threatStage;
            $duration = $piece['end'] - $piece['start'];

            if ($closing) {
                $subject = 'environment';
                $threatStage = null;
                $motion = 'static';
                $framing = $this->nextFraming(
                    $framingIndex,
                    $previousFraming,
                    false,
                    $subject,
                    null,
                );
            } else {
                if ($piece['continuation'] || ($previousFraming !== null && $framing === $previousFraming)) {
                    $framing = $this->nextFraming(
                        $framingIndex,
                        $previousFraming,
                        false,
                        $source->subject,
                        $source->threatStage,
                    );
                }

                if ($piece['continuation'] || ($previousMotion !== null && $motion === $previousMotion)) {
                    $motion = $this->nextMotion($motionIndex, $previousMotion, $source->sourceText, $duration);
                }
            }

            $tiled[] = new Shot(
                order: $index + 1,
                sceneOrder: $source->sceneOrder,
                start: $this->seconds($piece['start']),
                end: $this->seconds($piece['end']),
                sourceText: $source->sourceText,
                framing: $framing,
                motion: $motion,
                subject: $subject,
                threatStage: $threatStage,
                journeyLeg: $source->journeyLeg,
                lightStage: $source->lightStage,
                description: $source->description,
                characterSlugs: $source->characterSlugs,
                imagePath: $source->imagePath,
                isOutro: $source->isOutro,
            );

            $previousFraming = $framing;
            $previousMotion = $motion;
        }

        $sum = 0.0;

        foreach ($tiled as $shot) {
            $sum += $shot->end - $shot->start;
        }

        if (abs($sum - $audioDuration) > 0.01) {
            throw new RuntimeException(sprintf(
                'El teselado cubre %.3f s y el audio dura %.3f s.',
                $sum,
                $audioDuration,
            ));
        }

        return $tiled;
    }

    /**
     * Si absorber silencio deja un plano por encima de max_duration + max_hold_slack, parte el
     * exceso. Vale igual para la ventana de cierre: un congelado largo es un plano muerto.
     *
     * @return list<array{shot: Shot, start: float, end: float, continuation: bool, closing?: bool}>
     */
    private function splitOversizedHold(Shot $shot, float $start, float $end): array
    {
        $maxHold = $this->maxDuration + $this->maxHoldSlack;
        $pieces = [];
        $cursor = $start;
        $continuation = false;

        while (($end - $cursor) > $maxHold + 0.0005) {
            $cut = $this->seconds($cursor + $this->maxDuration);
            $pieces[] = [
                'shot' => $shot,
                'start' => $this->seconds($cursor),
                'end' => $cut,
                'continuation' => $continuation,
            ];
            $cursor = $cut;
            $continuation = true;
        }

        $pieces[] = [
            'shot' => $shot,
            'start' => $this->seconds($cursor),
            'end' => $this->seconds($end),
            'continuation' => $continuation,
        ];

        return $pieces;
    }

    private function subjectForRun(string $original, int $run): string
    {
        if ($run === 0) {
            return $original;
        }

        $alternates = ['detail', 'environment'];
        $start = $original === 'detail' ? 1 : 0;

        return $alternates[($run - 1 + $start) % 2];
    }

    private function nextFraming(
        int &$index,
        ?string $previous,
        bool $sceneStart,
        string $subject,
        ?string $stage,
    ): string {
        $pool = $this->framingPool($subject, $stage);

        if ($sceneStart) {
            $preferred = match (true) {
                $subject === 'threat' && $stage === 'hint' => ['wide establishing', 'medium shot'],
                $stage === 'reveal' => ['close detail', 'low angle'],
                default => ['wide establishing', 'medium shot'],
            };

            $preferred = array_values(array_intersect($preferred, $pool));

            if ($preferred === []) {
                $preferred = $pool;
            }

            foreach ($preferred as $framing) {
                if ($framing !== $previous) {
                    return $framing;
                }
            }
        }

        return $this->pickFromPool($pool, $previous, $index);
    }

    /**
     * @return list<string>
     */
    private function framingPool(string $subject, ?string $stage): array
    {
        if ($subject === 'threat' && $stage === 'hint') {
            return ['wide establishing', 'medium shot'];
        }

        if ($stage === 'reveal') {
            return ['close detail', 'low angle', 'extreme close up', 'over the shoulder'];
        }

        return self::FRAMINGS;
    }

    /**
     * @param  list<string>  $pool
     */
    private function pickFromPool(array $pool, ?string $previous, int &$index): string
    {
        $count = count($pool);

        for ($step = 0; $step < $count; $step++) {
            $framing = $pool[$index % $count];
            $index++;

            if ($framing !== $previous) {
                return $framing;
            }
        }

        return $pool[0];
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

            if (
                $knownScenes !== []
                && ! in_array($sceneOrder, $knownScenes, true)
                && $sceneOrder !== $this->outroSceneOrder
            ) {
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
     * Un único plano que cubre toda la escena de cierre, sin trocear ni asignar beats narrativos.
     *
     * @param  list<array{sceneOrder: int, start: float, end: float, text: string}>  $windows
     * @return array{sceneOrder: int, start: float, end: float, text: string, beatIndex: int, subject: string, threatStage: ?string}
     */
    private function outroUnit(array $windows): array
    {
        $first = $windows[0];
        $last = $windows[array_key_last($windows)];

        return [
            'sceneOrder' => $this->outroSceneOrder,
            'start' => $first['start'],
            'end' => $last['end'],
            'text' => implode(' ', array_map(
                static fn (array $window): string => $window['text'],
                $windows,
            )),
            'beatIndex' => 0,
            'subject' => 'environment',
            'threatStage' => null,
        ];
    }

    /**
     * @param  list<array{sceneOrder: int, start: float, end: float, text: string}>  $windows
     * @return list<array{sceneOrder: int, start: float, end: float, text: string, beatIndex: int, subject: string, threatStage: ?string}>
     */
    private function attachBeats(array $windows, Story $story): array
    {
        if ($windows === []) {
            return [];
        }

        $scene = $this->scene($story, $windows[0]['sceneOrder']);
        $attached = [];

        foreach ($windows as $index => $window) {
            $beat = $this->beatForWindow($window['text'], $index, count($windows), $scene);
            $attached[] = [
                'sceneOrder' => $window['sceneOrder'],
                'start' => $window['start'],
                'end' => $window['end'],
                'text' => $window['text'],
                'beatIndex' => $beat['index'],
                'subject' => $beat['subject'],
                'threatStage' => $beat['threatStage'],
            ];
        }

        return $attached;
    }

    /**
     * @return array{index: int, subject: string, threatStage: ?string}
     */
    private function beatForWindow(string $text, int $sentenceIndex, int $sentenceCount, ?StoryScene $scene): array
    {
        return [
            'index' => 0,
            'subject' => 'environment',
            'threatStage' => null,
        ];
    }

    /**
     * @param  array{sceneOrder: int, start: float, end: float, text: string, beatIndex: int, subject: string, threatStage: ?string}  $base
     * @return array{sceneOrder: int, start: float, end: float, text: string, beatIndex: int, subject: string, threatStage: ?string}
     */
    private function unit(array $base, float $start, float $end, string $text): array
    {
        return [
            'sceneOrder' => $base['sceneOrder'],
            'start' => $start,
            'end' => $end,
            'text' => $text,
            'beatIndex' => $base['beatIndex'],
            'subject' => $base['subject'],
            'threatStage' => $base['threatStage'],
        ];
    }

    private function scene(Story $story, int $order): ?StoryScene
    {
        foreach ($story->scenes as $scene) {
            if ($scene->order === $order) {
                return $scene;
            }
        }

        return null;
    }

    /**
     * @param  array{sceneOrder: int, start: float, end: float, text: string, beatIndex: int, subject: string, threatStage: ?string}  $left
     * @param  array{sceneOrder: int, start: float, end: float, text: string, beatIndex: int, subject: string, threatStage: ?string}  $right
     * @return array{sceneOrder: int, start: float, end: float, text: string, beatIndex: int, subject: string, threatStage: ?string}
     */
    private function mergeUnits(array $left, array $right): array
    {
        return [
            'sceneOrder' => $left['sceneOrder'],
            'start' => $left['start'],
            'end' => $right['end'],
            'text' => trim($left['text'].' '.$right['text']),
            'beatIndex' => $left['beatIndex'],
            'subject' => $left['subject'],
            'threatStage' => $left['threatStage'],
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
