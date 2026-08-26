<?php

declare(strict_types=1);

namespace App\Services\Story;

final class StorySchema
{
    /**
     * @return array<string, mixed>
     */
    public static function get(): array
    {
        return [
            'type' => 'OBJECT',
            'properties' => [
                'title' => [
                    'type' => 'STRING',
                    'description' => 'YouTube title in English, maximum 70 characters. Hint without spoiling. No all-caps clickbait.',
                ],
                'hook' => [
                    'type' => 'STRING',
                    'description' => 'Opening hook for the first 15 seconds, in English. Start on the most unsettling image. Never introductions or context.',
                ],
                'description' => [
                    'type' => 'STRING',
                    'description' => 'YouTube description in English. Atmosphere only. No ending spoilers.',
                ],
                'tags' => [
                    'type' => 'ARRAY',
                    'description' => 'YouTube tags in English, short, psychological horror. No brands. No real names.',
                    'items' => [
                        'type' => 'STRING',
                        'description' => 'One tag in English, lowercase except invented proper names.',
                    ],
                ],
                'thumbnailPrompt' => [
                    'type' => 'STRING',
                    'description' => 'Thumbnail prompt in English: static scene, no recognizable faces, no text. Must end with the style suffix from the system rules.',
                ],
                'pronunciations' => [
                    'type' => 'ARRAY',
                    'description' => 'Fill with every Spanish term that appears in the narration, including person names and place names. term is the exact spelling in the text. phonetic is a hyphenated approximation a US English speaker can read aloud, with stress in capitals. Example: Sacamantecas → sah-kah-mahn-TEH-kahs. Empty array if there are none. Do not add ordinary English words.',
                    'items' => [
                        'type' => 'OBJECT',
                        'description' => 'One Spanish term from the narration and how an English-speaking TTS reader should pronounce it.',
                        'properties' => [
                            'term' => [
                                'type' => 'STRING',
                                'description' => 'Exact Spanish term as it appears in the narration, including person and place names.',
                            ],
                            'phonetic' => [
                                'type' => 'STRING',
                                'description' => 'Phonetic spelling for an English-speaking reader. Example: sah-kah-mahn-TEH-kahs.',
                            ],
                        ],
                        'required' => ['term', 'phonetic'],
                    ],
                ],
                'scenes' => [
                    'type' => 'ARRAY',
                    'description' => 'Story scenes in narrative order. Prefer twelve to sixteen scenes. Each narration is 120 to 150 words of English. Combined narration about 1600 words.',
                    'items' => [
                        'type' => 'OBJECT',
                        'description' => 'One scene: spoken narration plus visual beats, ambient bed, and optional sound effects.',
                        'properties' => [
                            'order' => [
                                'type' => 'INTEGER',
                                'description' => 'Scene order number, starting at 1.',
                            ],
                            'narration' => [
                                'type' => 'STRING',
                                'description' => 'Spoken narration in English, first person, past tense. Short sentences. No digits, acronyms, or symbols: write them as words. 120 to 150 words.',
                            ],
                            'visualBeats' => [
                                'type' => 'ARRAY',
                                'description' => 'Short visual stills for this scene, in narrative order. Four to eight beats. Each beat is an object with description, subject, and threatStage.',
                                'items' => [
                                    'type' => 'OBJECT',
                                    'description' => 'One still image the camera would see, in English.',
                                    'properties' => [
                                        'description' => [
                                            'type' => 'STRING',
                                            'description' => 'Visual description of the still, in English. 8 to 14 words. Static image, no recognizable faces, no proper names, no gore. Self-contained; copied into image prompts. Prefer light, weather, and objects over violence. Write blood as dark stain, a corpse as still figure.',
                                        ],
                                        'subject' => [
                                            'type' => 'STRING',
                                            'description' => 'Exactly one of: protagonist, threat, both, environment, detail.',
                                        ],
                                        'threatStage' => [
                                            'type' => 'STRING',
                                            'nullable' => true,
                                            'description' => 'Required when subject is threat or both: hint, presence, or reveal. Null otherwise.',
                                        ],
                                    ],
                                    'required' => ['description', 'subject', 'threatStage'],
                                ],
                            ],
                            'ambience' => [
                                'type' => 'OBJECT',
                                'description' => 'Ambient bed for this scene. Query the sound library; do not invent exotic one-off sounds.',
                                'properties' => [
                                    'query' => [
                                        'type' => 'STRING',
                                        'description' => 'Two to four English words as a sound-library search, e.g. wind howling night, empty room tone.',
                                    ],
                                    'tags' => [
                                        'type' => 'ARRAY',
                                        'description' => 'Two to three normalized lowercase English tags for the ambient bed.',
                                        'items' => [
                                            'type' => 'STRING',
                                            'description' => 'One normalized lowercase English tag, e.g. wind, night, room.',
                                        ],
                                    ],
                                    'intensity' => [
                                        'type' => 'STRING',
                                        'description' => 'Exactly one of: subtle, moderate, heavy.',
                                    ],
                                ],
                                'required' => ['query', 'tags', 'intensity'],
                            ],
                            'soundEffects' => [
                                'type' => 'ARRAY',
                                'description' => 'Punctual sound effects for this scene. Zero to two. Prefer none. Overloading turns horror into comedy. Empty array if none.',
                                'items' => [
                                    'type' => 'OBJECT',
                                    'description' => 'One punctual hit timed to an exact fragment of this scene narration.',
                                    'properties' => [
                                        'query' => [
                                            'type' => 'STRING',
                                            'description' => 'Two to four English words as a sound-library search, e.g. door creak slow, glass crack.',
                                        ],
                                        'tags' => [
                                            'type' => 'ARRAY',
                                            'description' => 'Two to three normalized lowercase English tags for this hit.',
                                            'items' => [
                                                'type' => 'STRING',
                                                'description' => 'One normalized lowercase English tag, e.g. door, creak.',
                                            ],
                                        ],
                                        'anchorText' => [
                                            'type' => 'STRING',
                                            'description' => 'Exact fragment from this scene narration where the sound belongs.',
                                        ],
                                        'importance' => [
                                            'type' => 'STRING',
                                            'description' => 'Exactly one of: key (story-critical hit) or texture (atmosphere spice).',
                                        ],
                                    ],
                                    'required' => ['query', 'tags', 'anchorText', 'importance'],
                                ],
                            ],
                        ],
                        'required' => ['order', 'narration', 'visualBeats', 'ambience', 'soundEffects'],
                    ],
                ],
            ],
            'required' => ['title', 'hook', 'description', 'tags', 'thumbnailPrompt', 'pronunciations', 'scenes'],
        ];
    }
}
