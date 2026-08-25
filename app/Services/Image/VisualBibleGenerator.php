<?php

declare(strict_types=1);

namespace App\Services\Image;

use App\DataObjects\Story;
use App\DataObjects\VisualBible;
use App\Services\Llm\GeminiClient;
use Illuminate\Contracts\Config\Repository;

final class VisualBibleGenerator
{
    private readonly string $imageStyleSuffix;

    public function __construct(
        private GeminiClient $gemini,
        Repository $config,
    ) {
        $this->imageStyleSuffix = (string) $config->get('stories.image_style_suffix');
    }

    public function generate(Story $story): VisualBible
    {
        $payload = $story->toArray();
        unset($payload['visualBible']);

        $script = json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );

        $data = $this->gemini->generateJson(
            $this->systemInstruction(),
            $this->userPrompt($script),
            $this->schema(),
            temperature: 0.4,
        );

        return VisualBible::fromArray($data);
    }

    private function systemInstruction(): string
    {
        $style = $this->imageStyleSuffix;

        return <<<INSTRUCTION
You are the production designer for a horror YouTube channel. You write a visual bible, not a story. You do not invent plot. You lock the look so ~100 stills of the same video can be generated later without drifting.

Every field is in English. Plain words. No purple prose.

Descriptors (character.descriptor, recurringObjects.descriptor, and the setting sentence) will be copied verbatim into about a hundred different image prompts. They must be closed, self-contained phrases. No pronouns. No "the same", "aforementioned", "as before", "this", "that", "he", "she", "it", or any other reference that only makes sense in context. If a phrase cannot stand alone in a prompt, rewrite it until it can.

Rules:
- setting: 15 to 25 words. The main place, always named the same way. Do not vary the wording later; this string is the only setting line the pipeline will reuse.
- era: the time period plus visible markers (cars, clothes, tech that is present or conspicuously absent).
- timeOfDay and weather: one locked look for the video unless the script itself changes them. Be specific (overcast dusk, not "evening").
- palette: three or four dominant colors in plain English (rust brown, bone white, deep teal). Not hex. Not "warm tones".
- characters: every recurring human figure the stills must recognize. slug in kebab-case. descriptor is 10 to 15 words: build, clothes, hair. Never facial features. Never a face. framingRule is how they are ALWAYS shown: from behind, in silhouette, partly out of frame, hands in close-up, or similar. No frontal portraits.
- recurringObjects: props or landmarks that appear more than once and must stay recognizable. slug plus a self-contained descriptor.
- avoid: anachronisms and anything from another setting. Things that must never appear.

Image stills will be cinematic horror, no text, no faces. Style suffix used later: {$style}
INSTRUCTION;
    }

    private function userPrompt(string $script): string
    {
        return <<<PROMPT
Build the visual bible for this horror script. Return only the JSON. Lock one look. Do not retell the plot.

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
                'setting' => [
                    'type' => 'STRING',
                    'description' => 'Main location in English, 15 to 25 words, fixed wording reused in every shot. Self-contained. No pronouns.',
                ],
                'era' => [
                    'type' => 'STRING',
                    'description' => 'Time period and its visual markers: cars, clothes, technology present or absent.',
                ],
                'timeOfDay' => [
                    'type' => 'STRING',
                    'description' => 'Locked time of day for the video, specific (overcast dusk, not evening).',
                ],
                'weather' => [
                    'type' => 'STRING',
                    'description' => 'Locked weather for the video, specific.',
                ],
                'palette' => [
                    'type' => 'ARRAY',
                    'description' => 'Three or four dominant colors in plain English (rust brown, bone white, deep teal).',
                    'items' => [
                        'type' => 'STRING',
                        'description' => 'One color in plain English. No hex.',
                    ],
                ],
                'characters' => [
                    'type' => 'ARRAY',
                    'description' => 'Recurring human figures. Empty if none.',
                    'items' => [
                        'type' => 'OBJECT',
                        'properties' => [
                            'slug' => [
                                'type' => 'STRING',
                                'description' => 'Kebab-case id, stable across shots.',
                            ],
                            'descriptor' => [
                                'type' => 'STRING',
                                'description' => '10 to 15 words: build, clothes, hair. No facial features. Closed phrase, no pronouns, copy-paste ready.',
                            ],
                            'framingRule' => [
                                'type' => 'STRING',
                                'description' => 'How this figure is always shown: from behind, silhouette, partly out of frame, hands in close-up. Never a face.',
                            ],
                        ],
                        'required' => ['slug', 'descriptor', 'framingRule'],
                    ],
                ],
                'recurringObjects' => [
                    'type' => 'ARRAY',
                    'description' => 'Props or landmarks that appear more than once and must stay recognizable. Empty if none.',
                    'items' => [
                        'type' => 'OBJECT',
                        'properties' => [
                            'slug' => [
                                'type' => 'STRING',
                                'description' => 'Kebab-case id, stable across shots.',
                            ],
                            'descriptor' => [
                                'type' => 'STRING',
                                'description' => 'Self-contained visual phrase, no pronouns, copy-paste ready.',
                            ],
                        ],
                        'required' => ['slug', 'descriptor'],
                    ],
                ],
                'avoid' => [
                    'type' => 'ARRAY',
                    'description' => 'Things that must never appear: anachronisms, other settings, forbidden tropes.',
                    'items' => [
                        'type' => 'STRING',
                        'description' => 'One thing to keep out of every still.',
                    ],
                ],
            ],
            'required' => ['setting', 'era', 'timeOfDay', 'weather', 'palette', 'characters', 'recurringObjects', 'avoid'],
        ];
    }
}
