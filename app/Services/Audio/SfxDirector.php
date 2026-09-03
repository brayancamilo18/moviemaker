<?php

declare(strict_types=1);

namespace App\Services\Audio;

use App\Contracts\JsonLlm;
use App\DataObjects\DirectedSfx;
use App\DataObjects\Shot;
use App\DataObjects\Story;
use App\DataObjects\StoryScene;
use App\Exceptions\LlmGenerationException;
use App\Services\Llm\LlmTask;
use Illuminate\Contracts\Config\Repository;
use JsonException;
use Psr\Log\LoggerInterface;

final class SfxDirector
{
    private readonly int $outroSceneOrder;

    private readonly int $introSceneOrder;

    private readonly int $coldOpenSceneOrder;

    public function __construct(
        private JsonLlm $llm,
        private SfxAnchor $anchor,
        private LoggerInterface $logger,
        Repository $config,
    ) {
        $this->outroSceneOrder = (int) $config->get('stories.story.outro.scene_order');
        $this->introSceneOrder = (int) $config->get('stories.story.intro.scene_order');
        $this->coldOpenSceneOrder = (int) $config->get('stories.story.cold_open.scene_order');
    }

    /**
     * @param  list<Shot>  $shots
     * @return list<DirectedSfx>
     */
    public function direct(array $shots, Story $story): array
    {
        if ($shots === []) {
            return [];
        }

        $effects = [];

        foreach ($this->groupByScene($shots) as $sceneOrder => $sceneShots) {
            // La careta y el cierre son texto fijo del canal: no hay nada diegético que sonar.
            if ($sceneOrder === $this->outroSceneOrder || $sceneOrder === $this->introSceneOrder) {
                continue;
            }

            foreach ($this->directScene($sceneShots, $story, $sceneOrder) as $effect) {
                $effects[] = $effect;
            }
        }

        return $effects;
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
     * @return list<DirectedSfx>
     */
    private function directScene(array $sceneShots, Story $story, int $sceneOrder): array
    {
        $allowed = [];

        foreach ($sceneShots as $shot) {
            $allowed[$shot->order] = $shot;
        }

        $data = $this->llm->generateJson(
            $this->systemInstruction(),
            $this->userPrompt($sceneShots, $story, $sceneOrder),
            $this->schema(),
            task: LlmTask::SfxDirection,
            temperature: 0.4,
        );

        $effects = [];

        foreach (is_array($data['effects'] ?? null) ? $data['effects'] : [] as $row) {
            if (! is_array($row)) {
                continue;
            }

            $effect = DirectedSfx::fromArray($row);

            if ($effect->query === '' || ! isset($allowed[$effect->shotIndex])) {
                continue;
            }

            // Un ancla que no está en la narración del plano no se podrá colocar en la mezcla, así
            // que el efecto se cae aquí: resolverle un sonido sería descargar un WAV para nada.
            if (! $this->anchor->mentions($allowed[$effect->shotIndex]->sourceText, $effect->anchorWord)) {
                $this->logger->info('Efecto sin ancla en la narración de su plano; se descarta.', [
                    'shot' => $effect->shotIndex,
                    'query' => $effect->query,
                    'anchorWord' => $effect->anchorWord,
                ]);

                continue;
            }

            $effects[] = $effect;
        }

        return $effects;
    }

    /**
     * @param  list<Shot>  $sceneShots
     */
    private function userPrompt(array $sceneShots, Story $story, int $sceneOrder): string
    {
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
                    'sceneNarration' => $this->scene($story, $sceneOrder)?->narration ?? '',
                    'shots' => $shots,
                ],
                JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            throw new LlmGenerationException(
                "No se pudo serializar el prompt de sonido de la escena {$sceneOrder}.",
                previous: $exception,
            );
        }
    }

    private function scene(Story $story, int $order): ?StoryScene
    {
        return $story->sceneByOrder($order, $this->coldOpenSceneOrder);
    }

    private function systemInstruction(): string
    {
        return <<<'INSTRUCTION'
You are the sound designer for a horror YouTube channel. You place diegetic sound effects on specific shots.

Each shot has its own narration line. Only place an effect when that line contains something that physically makes a sound, at the moment it happens.

anchorWord is the single word of that narration line that names the sound, copied exactly as written. The effect is played on that word, so the word must be the one the listener hears at the instant the sound happens: for "The door creaked open" the anchor is creaked, not door. If no single word in the line names the sound, there is no effect to place: leave that shot out.

Set offsetRatio to where in that shot the sound occurs anyway, from 0.0 at the start of the line to 1.0 at the end.

The query is how you would search a sound library: two to four plain English words, describing the physical sound. Never metaphor. Wind howling like a widow is wind howling night.

RULES:
- Zero to two effects per shot. Most shots have none, and that is correct.
- At most one key effect in the whole scene. Everything else is texture.
- Only sounds that exist in the scene. No musical stingers. No jump scares. No score.
- anchorWord must appear literally in that shot's narration line. Never invent it, never use a word from another line.
- Silence is a tool. An empty result for a quiet scene is a good answer.
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
                'effects' => [
                    'type' => 'ARRAY',
                    'description' => 'Diegetic effects for this scene. Empty if the scene is silent.',
                    'items' => [
                        'type' => 'OBJECT',
                        'properties' => [
                            'shotIndex' => [
                                'type' => 'INTEGER',
                                'description' => 'Echo a shotIndex you were given.',
                            ],
                            'anchorWord' => [
                                'type' => 'STRING',
                                'description' => 'The single word of that shot narration that names the sound, copied literally from it.',
                            ],
                            'offsetRatio' => [
                                'type' => 'NUMBER',
                                'description' => 'Where in that shot the sound occurs, from 0.0 at the start to 1.0 at the end.',
                            ],
                            'query' => [
                                'type' => 'STRING',
                                'description' => 'Two to four plain English words as a sound-library search of the physical sound.',
                            ],
                            'tags' => [
                                'type' => 'ARRAY',
                                'description' => 'Normalized lowercase English tags for the sound-library search.',
                                'items' => [
                                    'type' => 'STRING',
                                    'description' => 'One lowercase English tag.',
                                ],
                            ],
                            'importance' => [
                                'type' => 'STRING',
                                'description' => 'Exactly one of: key, texture.',
                            ],
                        ],
                        'required' => ['shotIndex', 'anchorWord', 'offsetRatio', 'query', 'tags', 'importance'],
                    ],
                ],
            ],
            'required' => ['effects'],
        ];
    }
}
