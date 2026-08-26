<?php

declare(strict_types=1);

namespace App\Services\Image;

use App\DataObjects\Shot;
use App\DataObjects\Story;
use App\DataObjects\StoryScene;
use App\DataObjects\VisualBible;
use App\Exceptions\LlmGenerationException;
use App\Services\Llm\GeminiClient;
use JsonException;

final class ShotDirector
{
    /**
     * @var list<string>
     */
    private const SUBJECTS = [
        'protagonist',
        'threat',
        'both',
        'environment',
        'detail',
    ];

    /**
     * @var list<string>
     */
    private const FRAMINGS = [
        'wide establishing',
        'medium shot',
        'close detail',
        'low angle',
        'over the shoulder',
        'extreme close up',
    ];

    /**
     * @var list<string>
     */
    private const THREAT_STAGES = [
        'hint',
        'presence',
        'reveal',
    ];

    public function __construct(
        private GeminiClient $gemini,
    ) {}

    /**
     * @param  list<Shot>  $shots
     * @return list<Shot> los mismos planos con description, subject, threatStage, framing y characterSlugs dirigidos
     */
    public function direct(array $shots, Story $story, VisualBible $bible): array
    {
        if ($shots === []) {
            return [];
        }

        $sceneCount = max(count($story->scenes), 1);
        $directedByOrder = [];

        foreach ($this->groupByScene($shots) as $sceneOrder => $sceneShots) {
            foreach ($this->directScene($sceneShots, $story, $bible, $sceneOrder, $sceneCount) as $shot) {
                $directedByOrder[$shot->order] = $shot;
            }
        }

        $directed = [];

        foreach ($shots as $shot) {
            if (! isset($directedByOrder[$shot->order])) {
                throw new LlmGenerationException(
                    "La dirección de la escena {$shot->sceneOrder} no devolvió el plano {$shot->order}.",
                );
            }

            $directed[] = $directedByOrder[$shot->order];
        }

        return $directed;
    }

    /**
     * @param  list<Shot>  $shots
     * @return array<int, list<Shot>>
     */
    private function groupByScene(array $shots): array
    {
        $groups = [];

        foreach ($shots as $shot) {
            $groups[$shot->sceneOrder][] = $shot;
        }

        ksort($groups);

        return $groups;
    }

    /**
     * @param  list<Shot>  $sceneShots
     * @return list<Shot>
     */
    private function directScene(
        array $sceneShots,
        Story $story,
        VisualBible $bible,
        int $sceneOrder,
        int $sceneCount,
    ): array {
        $expected = array_map(static fn (Shot $shot): int => $shot->order, $sceneShots);
        $userPrompt = $this->userPrompt($sceneShots, $story, $bible, $sceneOrder, $sceneCount);
        $lastError = $this->mismatchMessage($sceneOrder, $expected, []);

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $data = $this->gemini->generateJson(
                $this->systemInstruction(),
                $userPrompt,
                $this->schema(),
                temperature: 0.7,
            );

            try {
                return $this->hydrateScene($sceneShots, $data, $expected, $sceneOrder);
            } catch (LlmGenerationException $exception) {
                $lastError = $exception->getMessage();
            }
        }

        throw new LlmGenerationException($lastError);
    }

    /**
     * @param  list<Shot>  $sceneShots
     * @param  array<string, mixed>  $data
     * @param  list<int>  $expected
     * @return list<Shot>
     */
    private function hydrateScene(array $sceneShots, array $data, array $expected, int $sceneOrder): array
    {
        $rows = is_array($data['shots'] ?? null) ? $data['shots'] : [];
        $received = [];
        $byIndex = [];

        foreach ($rows as $row) {
            if (! is_array($row) || ! array_key_exists('shotIndex', $row)) {
                continue;
            }

            $shotIndex = (int) $row['shotIndex'];
            $received[] = $shotIndex;
            $byIndex[$shotIndex] = $row;
        }

        $mismatch = $this->indexMismatch($expected, $received);

        if ($mismatch !== null) {
            throw new LlmGenerationException($this->mismatchMessage($sceneOrder, $expected, $received, $mismatch));
        }

        $directed = [];

        foreach ($sceneShots as $shot) {
            $directed[] = $this->applyDirection(
                $shot,
                $byIndex[$shot->order],
                $sceneOrder,
                $expected,
                $received,
            );
        }

        return $directed;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<int>  $expected
     * @param  list<int>  $received
     */
    private function applyDirection(
        Shot $shot,
        array $row,
        int $sceneOrder,
        array $expected,
        array $received,
    ): Shot {
        $subject = trim((string) ($row['subject'] ?? ''));

        if (! in_array($subject, self::SUBJECTS, true)) {
            throw new LlmGenerationException(
                $this->mismatchMessage(
                    $sceneOrder,
                    $expected,
                    $received,
                    "Subject no válido en el plano {$shot->order}: '{$subject}'.",
                ),
            );
        }

        $framing = trim((string) ($row['framing'] ?? ''));

        if (! in_array($framing, self::FRAMINGS, true)) {
            throw new LlmGenerationException(
                $this->mismatchMessage(
                    $sceneOrder,
                    $expected,
                    $received,
                    "Framing no válido en el plano {$shot->order}: '{$framing}'.",
                ),
            );
        }

        $description = trim((string) ($row['description'] ?? ''));

        if ($description === '') {
            throw new LlmGenerationException(
                $this->mismatchMessage(
                    $sceneOrder,
                    $expected,
                    $received,
                    "El plano {$shot->order} llegó sin description.",
                ),
            );
        }

        $threat = trim((string) ($row['threatStage'] ?? ''));
        $threatStage = in_array($threat, self::THREAT_STAGES, true) ? $threat : null;

        if (! in_array($subject, ['threat', 'both'], true)) {
            $threatStage = null;
        }

        return new Shot(
            order: $shot->order,
            sceneOrder: $shot->sceneOrder,
            start: $shot->start,
            end: $shot->end,
            sourceText: $shot->sourceText,
            framing: $framing,
            motion: $shot->motion,
            subject: $subject,
            threatStage: $threatStage,
            description: $description,
            characterSlugs: $this->characterSlugs($row['characterSlugs'] ?? []),
            imagePath: $shot->imagePath,
        );
    }

    /**
     * @param  list<int>  $expected
     * @param  list<int>  $received
     */
    private function indexMismatch(array $expected, array $received): ?string
    {
        $missing = array_values(array_diff($expected, $received));
        $extra = array_values(array_diff($received, $expected));
        $counts = array_count_values($received);
        $duplicates = [];

        foreach ($counts as $index => $count) {
            if ($count > 1) {
                $duplicates[] = $index;
            }
        }

        if (count($received) === count($expected) && $missing === [] && $extra === [] && $duplicates === []) {
            return null;
        }

        $parts = [
            'Faltan índices: '.$this->indexList($missing).'.',
            'Sobran índices: '.$this->indexList($extra).'.',
        ];

        if ($duplicates !== []) {
            $parts[] = 'Repetidos: '.$this->indexList($duplicates).'.';
        }

        return implode(' ', $parts);
    }

    /**
     * @param  list<int>  $expected
     * @param  list<int>  $received
     */
    private function mismatchMessage(
        int $sceneOrder,
        array $expected,
        array $received,
        ?string $detail = null,
    ): string {
        $mismatch = $this->indexMismatch($expected, $received)
            ?? ('Faltan índices: ninguno. Sobran índices: ninguno.');
        $suffix = ($detail !== null && $detail !== $mismatch) ? ' '.$detail : '';

        return "La dirección de la escena {$sceneOrder} no es válida. {$mismatch}{$suffix}";
    }

    /**
     * @param  list<int>  $indices
     */
    private function indexList(array $indices): string
    {
        if ($indices === []) {
            return 'ninguno';
        }

        return implode(', ', array_map(static fn (int $index): string => (string) $index, $indices));
    }

    /**
     * @return list<string>
     */
    private function characterSlugs(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $slugs = [];

        foreach ($value as $slug) {
            $slug = trim((string) $slug);

            if ($slug !== '' && ! in_array($slug, $slugs, true)) {
                $slugs[] = $slug;
            }
        }

        return $slugs;
    }

    /**
     * @param  list<Shot>  $sceneShots
     */
    private function userPrompt(
        array $sceneShots,
        Story $story,
        VisualBible $bible,
        int $sceneOrder,
        int $sceneCount,
    ): string {
        $scene = $this->scene($story, $sceneOrder);
        $shots = [];

        foreach ($sceneShots as $shot) {
            $shots[] = [
                'shotIndex' => $shot->order,
                'durationSeconds' => round(max(0.0, $shot->end - $shot->start), 3),
                'narration' => $shot->sourceText,
            ];
        }

        try {
            return json_encode(
                [
                    'bible' => $bible->toArray(),
                    'sceneSummary' => $scene?->visualSummary ?? '',
                    'sceneNarration' => $scene?->narration ?? '',
                    'storyProgress' => $sceneOrder / $sceneCount,
                    'shots' => $shots,
                ],
                JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            throw new LlmGenerationException(
                "No se pudo serializar el prompt de dirección de la escena {$sceneOrder}.",
                previous: $exception,
            );
        }
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

    private function systemInstruction(): string
    {
        return <<<'INSTRUCTION'
You are the shot director for a horror YouTube channel. You do not write story. You decide what the camera sees in each individual shot of one scene.

THE CORE RULE: each shot has its own narration line. Your description must show what THAT line describes, at that moment. If the line says a hand rests on a latch, the image is the hand on the latch. If the line says she stopped at the treeline, the image is a figure stopped at a treeline. Never describe the scene in general. Never describe something that happens in a different shot.

CONTINUITY: consecutive shots share location, light, palette and weather unless the narration explicitly moves elsewhere. Use the bible verbatim for setting, era, palette and character descriptors. Never contradict the bible. Never include anything from bible.avoid.

SUBJECT DISTRIBUTION across the shots of this scene:
- At least 60 percent must have a figure: protagonist, threat, or both.
- At most 25 percent may be detail.
- Never two environment shots in a row.

THREAT ESCALATION, driven by storyProgress:
- Below 0.33: only hint. The threat may be present but ambiguous, distant, or possibly imagined.
- 0.33 to 0.70: presence. Unmistakably there but incomplete: partial, at a distance, just outside the light.
- Above 0.70: reveal is allowed. Close and central, but never with a resolved face.
- Never use a later stage than storyProgress permits.

HARD LIMITS: no proper names. No text or logos in the image. No resolved facial features. No gore: write blood as dark stain, a corpse as still figure. Descriptions are static stills, not actions in progress.

Return one entry per shot you received, with the same shotIndex values. Never merge, skip, or invent shots.
INSTRUCTION;
    }

    /**
     * @return array<string, mixed>
     */
    private function schema(): array
    {
        return [
            'type' => 'OBJECT',
            'properties' => [
                'shots' => [
                    'type' => 'ARRAY',
                    'description' => 'One entry per received shot, same order, same shotIndex values.',
                    'items' => [
                        'type' => 'OBJECT',
                        'properties' => [
                            'shotIndex' => ['type' => 'INTEGER', 'description' => 'Echo the shotIndex you were given.'],
                            'description' => ['type' => 'STRING', 'description' => 'The image for THIS shot narration, in English, 8 to 14 words. Static still. No proper names, no gore, no resolved faces.'],
                            'subject' => ['type' => 'STRING', 'description' => 'One of: protagonist, threat, both, environment, detail.'],
                            'threatStage' => ['type' => 'STRING', 'description' => 'One of: hint, presence, reveal. Empty string when subject is not threat or both.'],
                            'framing' => ['type' => 'STRING', 'description' => 'One of: wide establishing, medium shot, close detail, low angle, over the shoulder, extreme close up.'],
                            'characterSlugs' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING'], 'description' => 'Slugs from bible.characters visible in this shot. Empty array if none.'],
                        ],
                        'required' => ['shotIndex', 'description', 'subject', 'threatStage', 'framing', 'characterSlugs'],
                    ],
                ],
            ],
            'required' => ['shots'],
        ];
    }
}
