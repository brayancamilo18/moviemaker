<?php

declare(strict_types=1);

namespace App\Services\Image;

use App\DataObjects\Story;
use App\DataObjects\VisualBible;
use App\Services\Llm\GeminiClient;
use Illuminate\Contracts\Config\Repository;

final class VisualBibleGenerator
{
    /**
     * @var list<string>
     */
    private const FRAMING_VOCABULARY = [
        'seen from behind',
        'over the shoulder',
        'silhouette against light',
        'hands only in foreground',
        'partially cropped at frame edge',
        'face turned away',
        'reflection',
        'blurred',
        'distant figure',
        'small in frame',
        'backlit',
        'features lost in shadow',
        'POV',
        'own hands visible',
    ];

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
        $framingList = implode(', ', self::FRAMING_VOCABULARY);

        return <<<INSTRUCTION
You are the production designer for a horror YouTube channel. You write a visual bible, not a story. You do not invent plot. You lock the look so ~100 stills of the same video can be generated later without drifting.

Every field is in English. Plain words. No purple prose.

The face is never seen. The person always is. People appear as bodies, silhouettes, backs, hands, reflections, or figures at the edge of the frame. Do not describe facial features. Do not ban the human figure.

Descriptors (character.bodyDescriptor, recurringObjects.descriptor, threat.nature, threat.stages[].descriptor, and the setting sentence) will be copied verbatim into about a hundred different image prompts. They must be closed, self-contained phrases. No pronouns. No "the same", "aforementioned", "as before", "this", "that", "he", "she", "it", or any other reference that only makes sense in context. If a phrase cannot stand alone in a prompt, rewrite it until it can.

Rules:
- setting: 15 to 25 words. The main place, always named the same way. Do not vary the wording later; this string is the only setting line the pipeline will reuse.
- era: the time period plus visible markers (cars, clothes, tech that is present or conspicuously absent).
- timeOfDay and weather: one locked look for the video unless the script itself changes them. Be specific (overcast dusk, not "evening").
- palette: three or four dominant colors in plain English (rust brown, bone white, deep teal). Not hex. Not "warm tones".
- characters: every recurring human figure the stills must recognize. slug in kebab-case. bodyDescriptor is 10 to 15 words: build, clothes, hair, and posture, recognizable from behind or in silhouette (the way the figure is always seen). Example of detail: tall thin man, worn olive raincoat, hunched shoulders, short dark hair, muddy boots. framingOptions is an array of 4 to 6 encodings for that character, chosen only from this vocabulary: {$framingList}.
- recurringObjects: props or landmarks that appear more than once and must stay recognizable. slug plus a self-contained descriptor.
- threat: the visual nature of the menace, not a plot summary. nature is what it looks like. stages is exactly three objects, in this order: hint, presence, reveal. hint — something that might not be there: a shape among the trees, a blurred silhouette in the background, a mark, a door that used to be closed. presence — unmistakably there but incomplete: a figure at the end of the hall, a hand on the doorframe, something just outside the beam of light. reveal — close and central, but the face is never resolved: backlit, covered, turned away, cropped by the frame, or warped by fog.
- Threat descriptors do not describe gore or violence. They describe presence and proximity. Terror comes from scale and light, not anatomical detail.
- avoid: anachronisms and anything from another setting. Things that must never appear.

Image stills will be cinematic horror, no text. Figures occupy the frame; faces stay unresolved. Style suffix used later: {$style}
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
        $framingList = implode(', ', self::FRAMING_VOCABULARY);

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
                    'description' => 'Recurring human figures. Empty if none. The face is never seen; the person always is.',
                    'items' => [
                        'type' => 'OBJECT',
                        'properties' => [
                            'slug' => [
                                'type' => 'STRING',
                                'description' => 'Kebab-case id, stable across shots.',
                            ],
                            'bodyDescriptor' => [
                                'type' => 'STRING',
                                'description' => '10 to 15 words: build, clothes, hair, posture. Recognizable from behind or in silhouette. Closed phrase, no pronouns, copy-paste ready. Example: tall thin man, worn olive raincoat, hunched shoulders, short dark hair, muddy boots.',
                            ],
                            'framingOptions' => [
                                'type' => 'ARRAY',
                                'description' => '4 to 6 valid framings for this character, chosen only from: '.$framingList.'.',
                                'items' => [
                                    'type' => 'STRING',
                                    'description' => 'One framing from the vocabulary: '.$framingList.'.',
                                ],
                            ],
                        ],
                        'required' => ['slug', 'bodyDescriptor', 'framingOptions'],
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
                'threat' => [
                    'type' => 'OBJECT',
                    'description' => 'Visual nature of the menace. Descriptors describe presence and proximity, never gore or violence.',
                    'properties' => [
                        'nature' => [
                            'type' => 'STRING',
                            'description' => 'What the threat looks like, in visual terms, not narrative. Closed phrase, no pronouns.',
                        ],
                        'stages' => [
                            'type' => 'ARRAY',
                            'description' => 'Exactly three objects, in order: hint, presence, reveal. No gore. Terror from scale and light, not anatomy.',
                            'items' => [
                                'type' => 'OBJECT',
                                'properties' => [
                                    'stage' => [
                                        'type' => 'STRING',
                                        'description' => 'Exactly one of: hint, presence, reveal.',
                                    ],
                                    'descriptor' => [
                                        'type' => 'STRING',
                                        'description' => 'hint: might not be there. presence: clearly present but incomplete. reveal: close and central, face never resolved (backlit, covered, turned away, cropped, warped by fog). Presence and proximity only. No gore, no violence, no anatomical detail.',
                                    ],
                                ],
                                'required' => ['stage', 'descriptor'],
                            ],
                        ],
                    ],
                    'required' => ['nature', 'stages'],
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
            'required' => ['setting', 'era', 'timeOfDay', 'weather', 'palette', 'characters', 'recurringObjects', 'threat', 'avoid'],
        ];
    }
}
