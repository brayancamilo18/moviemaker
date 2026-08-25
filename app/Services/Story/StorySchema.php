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
                'scenes' => [
                    'type' => 'ARRAY',
                    'description' => 'Story scenes in narrative order. Prefer twelve to sixteen scenes. Each narration is 120 to 150 words of English. Combined narration about 1600 words.',
                    'items' => [
                        'type' => 'OBJECT',
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
                                'description' => 'Short visual stills for this scene, in narrative order. Four to eight beats. Each beat is 8 to 14 words of English. Static image, no faces, no proper names, no gore. These strings are copied into image prompts; they must stand alone. Prefer light, weather, and objects over violence. Write blood as dark stain, a corpse as still figure.',
                                'items' => [
                                    'type' => 'STRING',
                                    'description' => 'One self-contained visual beat in English, 8 to 14 words. No faces. No names. No gore.',
                                ],
                            ],
                            'imagePrompt' => [
                                'type' => 'STRING',
                                'description' => 'English prompt for a static scene, no recognizable faces. Same place, palette, and time of day unless the story changes them. End with the system style suffix.',
                            ],
                            'soundEffect' => [
                                'type' => 'STRING',
                                'description' => 'Optional brief sound effect in English. Omit if it adds nothing.',
                            ],
                        ],
                        'required' => ['order', 'narration', 'imagePrompt', 'visualBeats'],
                    ],
                ],
                'pronunciations' => [
                    'type' => 'ARRAY',
                    'description' => 'Every Spanish name, place, or term that appears in the otherwise English narration. term is the exact spelling in the text. phonetic is a hyphenated approximation a US English speaker can read aloud, with stress in capitals. Example: Sacamantecas → sah-kah-mahn-TEH-kahs. Empty array if there are none.',
                    'items' => [
                        'type' => 'OBJECT',
                        'properties' => [
                            'term' => [
                                'type' => 'STRING',
                                'description' => 'Exact Spanish term as it appears in the narration, including person and place names.',
                            ],
                            'phonetic' => [
                                'type' => 'STRING',
                                'description' => 'Phonetic spelling for an English-speaking TTS reader. Example: sah-kah-mahn-TEH-kahs.',
                            ],
                        ],
                        'required' => ['term', 'phonetic'],
                    ],
                ],
            ],
            'required' => ['title', 'hook', 'description', 'tags', 'thumbnailPrompt', 'scenes', 'pronunciations'],
        ];
    }
}
