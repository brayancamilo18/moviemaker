<?php

declare(strict_types=1);

namespace App\Services\Image;

use App\Contracts\JsonLlm;
use App\DataObjects\Story;
use App\DataObjects\VisualBible;
use App\Services\Llm\LlmTask;
use Illuminate\Contracts\Config\Repository;

final class VisualBibleGenerator
{
    private readonly string $imageStyleSuffix;

    public function __construct(
        private JsonLlm $llm,
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

        $data = $this->llm->generateJson(
            $this->systemInstruction(),
            $this->userPrompt($script),
            $this->schema(),
            task: LlmTask::VisualBible,
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

THE CAMERA IS THE LISTENER. The narrator is never in frame: the camera stands where the narrator's eyes are and walks the route with them. Never describe the narrator's body, clothes, hands, arms or back. There is exactly one figure that can ever be seen in these stills, and it is the threat. Every other person in the script is shown only by what they leave behind: a door left open, an empty chair still warm, a parked car with the engine ticking, boots by the step. Traces, never bodies.

Descriptors (journey[].descriptor, light[].descriptor, recurringObjects[].descriptor, threat.nature, threat.stages[].descriptor and the setting line) will be copied verbatim into about a hundred different image prompts. They must be closed, self-contained phrases. No pronouns. No "the same", "aforementioned", "as before", "this", "that", "he", "she", "it", or any other reference that only makes sense in context. If a phrase cannot stand alone in a prompt, rewrite it until it can.

Rules:
- setting: 8 to 12 words. A compact anchor for the whole video: the region, the kind of place, nothing more. It rides along in every prompt next to the current leg of the route, so it must stay short and never vary.
- era: the time period plus visible markers (cars, clothes, tech that is present or conspicuously absent).
- journey: 5 to 7 legs, describing the route the listener physically covers over the video. Read the script and take the places it actually goes, in the order it goes to them. The last leg is where the narration ends, whatever that is. Do NOT invent a destination the script never reaches: if the story stays out on a road all night, every leg is outdoors, and a chapel interior nobody walks into would leave most of the video showing a room the voice never mentions. Each leg is a different place you can stand in, consecutive legs must connect on foot, and no leg repeats. Where the script does move from open to enclosed, keep that order. slug in kebab-case. descriptor is 10 to 15 words of what the camera sees from that spot.
- light: exactly 4 stages, in order, from the most open light to the least. The light only ever closes down: dusk to dark, wide grey sky to a single bulb, visibility shrinking. Never reopens. slug in kebab-case. descriptor is 8 to 12 words about the quality and direction of the light, not the time on a clock.
- timeOfDay and weather: the look at the start of the walk. Be specific (overcast dusk, not "evening"). weather stays locked for the whole video; the light is what changes, and it changes through the light stages.
- palette: three or four dominant colors in plain English (rust brown, bone white, deep teal). Not hex. Not "warm tones".
- recurringObjects: landmarks the walk passes more than once, so the route feels like one place and not a slideshow. Prefer things you can meet again from a different distance: a leaning gatepost, a dead radio mast on the ridge, a shrine with plastic flowers. slug plus a self-contained descriptor.
- threat: the visual nature of the menace, not a plot summary. nature is what it looks like. stages is exactly three objects, in this order: hint, presence, reveal. hint — something that might not be there: a shape among the trees, a blurred silhouette in the background, a mark, a door that used to be closed. presence — unmistakably there but incomplete: a figure at the end of the hall, something just outside the beam of light. reveal — the whole shape at last, as a complete silhouette against a light source, seen from low and dominating the frame by sheer size. Never a head near the camera: the reveal is scale and backlight, not proximity.
- Threat descriptors do not describe gore or violence. They describe presence and scale. Terror comes from size and light, not anatomical detail. The face is never resolved, at any stage.
- avoid: anachronisms and anything from another setting. Things that must never appear.

Image stills will be cinematic horror, no text: landscapes and interiors walked through at eye level, with one silhouette in them at most. Style suffix used later: {$style}
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
                    'description' => 'Compact anchor for the whole video in English, 8 to 12 words: region and kind of place. Rides in every prompt next to the current journey leg, so it stays short and never varies. Self-contained. No pronouns.',
                ],
                'era' => [
                    'type' => 'STRING',
                    'description' => 'Time period and its visual markers: cars, clothes, technology present or absent.',
                ],
                'timeOfDay' => [
                    'type' => 'STRING',
                    'description' => 'The look at the start of the walk, specific (overcast dusk, not evening). Only a starting point: the light closes down through the light stages.',
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
                'journey' => [
                    'type' => 'ARRAY',
                    'description' => 'The route the listener walks over the video: 5 to 7 legs, only places the script actually goes, in the order it goes to them. The last leg is where the narration ends. Never invent a destination the script does not reach. Consecutive legs must connect on foot. No leg repeats.',
                    'items' => [
                        'type' => 'OBJECT',
                        'properties' => [
                            'slug' => [
                                'type' => 'STRING',
                                'description' => 'Kebab-case id of the leg, stable across shots (roadside, fence-line, yard, threshold, hallway).',
                            ],
                            'descriptor' => [
                                'type' => 'STRING',
                                'description' => '10 to 15 words of what the camera sees standing on this leg. Closed phrase, no pronouns, no narrator body, copy-paste ready.',
                            ],
                        ],
                        'required' => ['slug', 'descriptor'],
                    ],
                ],
                'light' => [
                    'type' => 'ARRAY',
                    'description' => 'Exactly 4 stages, ordered from the most open light to the least. The light only closes down and never reopens.',
                    'items' => [
                        'type' => 'OBJECT',
                        'properties' => [
                            'slug' => [
                                'type' => 'STRING',
                                'description' => 'Kebab-case id of the stage, stable across shots (grey-overcast, failing-dusk, headlight-only, single-bulb).',
                            ],
                            'descriptor' => [
                                'type' => 'STRING',
                                'description' => '8 to 12 words on the quality and direction of the light, never a clock time. Closed phrase, no pronouns, copy-paste ready.',
                            ],
                        ],
                        'required' => ['slug', 'descriptor'],
                    ],
                ],
                'recurringObjects' => [
                    'type' => 'ARRAY',
                    'description' => 'Landmarks the walk meets more than once, so the route reads as one place. Prefer things you can see again from another distance. Empty if none.',
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
                            'description' => 'Exactly three objects, in order: hint, presence, reveal. No gore. Terror from size and light, not anatomy.',
                            'items' => [
                                'type' => 'OBJECT',
                                'properties' => [
                                    'stage' => [
                                        'type' => 'STRING',
                                        'description' => 'Exactly one of: hint, presence, reveal.',
                                    ],
                                    'descriptor' => [
                                        'type' => 'STRING',
                                        'description' => 'hint: might not be there. presence: clearly present but incomplete. reveal: the complete shape as a silhouette against a light source, seen from low, dominating the frame by size, never a head near the camera. Presence and scale only. No gore, no violence, no anatomical detail, no resolved face at any stage.',
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
            'required' => ['setting', 'era', 'timeOfDay', 'weather', 'palette', 'journey', 'light', 'recurringObjects', 'threat', 'avoid'],
        ];
    }
}
