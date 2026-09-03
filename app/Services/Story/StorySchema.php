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
                'coldOpen' => [
                    'type' => 'OBJECT',
                    'description' => 'The teaser spoken before anything else, in English. Newly written text, never a sentence copied from the scenes.',
                    'properties' => [
                        'narration' => [
                            'type' => 'STRING',
                            'description' => 'Two to four spoken sentences in English, 25 to 55 words, that put the listener inside the worst moment of the story without resolving it. First person, past tense, same voice as the narration. Never copy a sentence from any scene word for word.',
                        ],
                        'visualSummary' => [
                            'type' => 'STRING',
                            'description' => 'What the cold open looks like, in English, 10 to 15 words. Context for the shot director. Not an image prompt.',
                        ],
                    ],
                    'required' => ['narration', 'visualSummary'],
                ],
                'hookLine' => [
                    'type' => 'STRING',
                    'description' => 'One or two sentences in English, spoken to the listener in second person after the channel intro, that ask what they would have done in this story. Under 35 words. No spoilers, no answer, no title drop.',
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
                        'description' => 'One scene: spoken narration, a visual summary, and an ambient bed.',
                        'properties' => [
                            'order' => [
                                'type' => 'INTEGER',
                                'description' => 'Scene order number, starting at 1.',
                            ],
                            'narration' => [
                                'type' => 'STRING',
                                'description' => 'Spoken narration in English, first person, past tense. Short sentences. No digits, acronyms, or symbols: write them as words. 120 to 150 words.',
                            ],
                            'visualSummary' => [
                                'type' => 'STRING',
                                'description' => 'What this scene looks like overall, in English, 10 to 15 words. Context for the shot director. Not an image prompt.',
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
                        ],
                        'required' => ['order', 'narration', 'visualSummary', 'ambience'],
                    ],
                ],
            ],
            'required' => ['title', 'hook', 'coldOpen', 'hookLine', 'description', 'tags', 'thumbnailPrompt', 'pronunciations', 'scenes'],
        ];
    }
}
