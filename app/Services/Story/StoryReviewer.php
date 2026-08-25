<?php

declare(strict_types=1);

namespace App\Services\Story;

use App\DataObjects\Story;
use App\DataObjects\StoryReview;
use App\Services\Llm\GeminiClient;
use Illuminate\Contracts\Config\Repository;

final class StoryReviewer
{
    private readonly string $model;

    public function __construct(
        private GeminiClient $gemini,
        Repository $config,
    ) {
        $this->model = (string) $config->get('stories.review.model');
    }

    public function review(Story $story): StoryReview
    {
        $script = json_encode(
            $story->toArray(),
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );

        $data = $this->gemini->generateJson(
            $this->systemInstruction(),
            $this->userPrompt($script),
            $this->schema(),
            temperature: 0.3,
            model: $this->model,
        );

        return StoryReview::fromArray($data);
    }

    private function systemInstruction(): string
    {
        return <<<'INSTRUCTION'
You are a demanding script editor for English-language horror narration aimed at a US YouTube audience. You are not a helpful assistant. You do not soothe the writer. You do not pad the verdict.

Your job is a cold critical pass:
- Flag phrases a native English speaker would not say that way.
- Flag horror clichés that have been worn thin.
- Flag scenes where tension sags.
- Flag words and constructions a TTS engine will mispronounce.

Rules:
- If the script is genuinely clean, say so: empty arrays, a high score, verdict publish. Do not invent problems to look busy.
- If the script is weak, do not soften it. Do not add praise to balance the criticism. Do not use hedging like "overall a nice effort".
- Never rewrite the story. Report only.
- score is an integer from 1 to 10. Ten is rare. Five is mediocre. Below four is not publishable.
- verdict must be exactly one of: publish, revise, discard.
  publish: ship it with at most trivial notes.
  revise: usable core, must be fixed before recording.
  discard: not worth another pass.
- Be specific. Quote the offending text. Name the scene order. No vague complaints.
INSTRUCTION;
    }

    private function userPrompt(string $script): string
    {
        return <<<PROMPT
Review this horror script. Return only the JSON analysis. Do not rewrite the story.

{$script}
PROMPT;
    }

    /**
     * @return array<string, mixed>
     */
    private function schema(): array
    {
        return [
            'type' => 'OBJECT',
            'properties' => [
                'nonNativePhrases' => [
                    'type' => 'ARRAY',
                    'description' => 'Phrases a native English speaker would not say this way. Empty if none.',
                    'items' => [
                        'type' => 'OBJECT',
                        'properties' => [
                            'text' => [
                                'type' => 'STRING',
                                'description' => 'Exact quoted phrase from the narration.',
                            ],
                            'issue' => [
                                'type' => 'STRING',
                                'description' => 'Why it sounds non-native or calqued.',
                            ],
                            'suggestion' => [
                                'type' => 'STRING',
                                'description' => 'A natural replacement. Do not rewrite the whole scene.',
                            ],
                        ],
                        'required' => ['text', 'issue', 'suggestion'],
                    ],
                ],
                'clichedElements' => [
                    'type' => 'ARRAY',
                    'description' => 'Worn horror devices in this script. Empty if none.',
                    'items' => [
                        'type' => 'STRING',
                        'description' => 'One cliché, named plainly.',
                    ],
                ],
                'tensionDips' => [
                    'type' => 'ARRAY',
                    'description' => 'Scenes where tension drops. Empty if none.',
                    'items' => [
                        'type' => 'OBJECT',
                        'properties' => [
                            'sceneOrder' => [
                                'type' => 'INTEGER',
                                'description' => 'Scene order number, starting at 1.',
                            ],
                            'reason' => [
                                'type' => 'STRING',
                                'description' => 'Why the tension sags at this point.',
                            ],
                        ],
                        'required' => ['sceneOrder', 'reason'],
                    ],
                ],
                'ttsRisks' => [
                    'type' => 'ARRAY',
                    'description' => 'Words or constructions a TTS engine will mispronounce. Empty if none.',
                    'items' => [
                        'type' => 'STRING',
                        'description' => 'The risky word or construction, with a brief note.',
                    ],
                ],
                'score' => [
                    'type' => 'INTEGER',
                    'description' => 'Integer from 1 to 10. Ten is exceptional and rare.',
                ],
                'verdict' => [
                    'type' => 'STRING',
                    'description' => 'Exactly one of: publish, revise, discard.',
                    'enum' => ['publish', 'revise', 'discard'],
                ],
            ],
            'required' => ['nonNativePhrases', 'clichedElements', 'tensionDips', 'ttsRisks', 'score', 'verdict'],
        ];
    }
}
