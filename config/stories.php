<?php

declare(strict_types=1);

return [

    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),
        'model' => env('GEMINI_MODEL', 'gemini-3.6-flash'),
        'base_url' => 'https://generativelanguage.googleapis.com/v1beta',
        'timeout' => 120,
        'max_retries' => 3,
    ],

    'story' => [
        'target_words' => 1600,
        'min_scenes' => 8,
        'max_scenes' => 20,
        'language' => 'en',
        'default_mode' => 'folklore',
        'accent' => 'neutral_american',
    ],

    'review' => [
        'enabled' => true,
        'model' => env('GEMINI_REVIEW_MODEL', env('GEMINI_MODEL', 'gemini-3.6-flash')),
    ],

    'tts' => [
        'base_url' => env('TTS_BASE_URL', 'http://127.0.0.1:8020'),
        'voice' => env('TTS_VOICE', 'af_heart'),
        'speed' => (float) env('TTS_SPEED', 1.0),
        'timeout' => 120,
        'cache_path' => 'tts-cache',
        // Pausas en segundos. Son el 80% del efecto de terror: ajústalas a menudo.
        'pauses' => [
            'sentence' => 0.45,
            'question_or_exclamation' => 0.7,
            'ellipsis' => 1.1,
            'between_scenes' => 1.8,
        ],
    ],

    'ffmpeg' => [
        'binary' => 'ffmpeg',
        'ffprobe' => 'ffprobe',
        'nice' => 10,
        'timeout' => 3600,
        'filters' => [
            'highpass' => 80,
            'lowpass' => 12000,
            'aecho' => '0.8:0.85:35:0.12',
        ],
        'loudnorm' => [
            'I' => -14.0,
            'TP' => -1.5,
            'LRA' => 11.0,
        ],
        'alimiter_limit' => 0.95,
        'mp3_bitrate' => '320k',
        'mp3_sample_rate' => 48000,
    ],

    'whisper' => [
        'binary' => env('WHISPER_BINARY', 'whisper-cli'),
        'model' => env('WHISPER_MODEL') ?: storage_path('app/whisper/ggml-base.en.bin'),
        'language' => env('WHISPER_LANGUAGE'),
        'timeout' => 900,
        'nice' => 10,
        'threads' => 4,
        'max_len' => 1,
        'dtw' => env('WHISPER_DTW', ''),
    ],

    // Duraciones en segundos. Los cortes salen de timings.json; max_duration es un techo, no un objetivo.
    'shots' => [
        'min_duration' => 2.5,
        'max_duration' => 9.0,
        'target_duration' => 5.5,
        'tension_duration' => 3.5,
        'atmosphere_duration' => 8.0,
    ],

    // 1280×720 × source_upscale 2.0 = 2560 px, cubre zoom_max 1.18 en 1080p (mínimo ~2266 px).
    // Si zoom_max pasa de 1.3, sube también images.width.
    'images' => [
        'provider' => env('IMAGE_PROVIDER', 'pollinations'),
        'width' => 1280,
        'height' => 720,
        'rate_limit_seconds' => 6,
        'max_retries' => 4,
        'concurrency' => 1,
        'timeout' => 120,
        'cache_path' => 'image-cache',
        'pollinations' => [
            'base_url' => 'https://image.pollinations.ai/prompt',
            'model' => 'flux',
        ],
    ],

    'image_style_suffix' => 'cinematic horror still, desaturated earth tones, heavy fog, 35mm film grain, low key lighting, rural Iberian or Latin American setting',

    'output_path' => 'stories',

    // La narración manda. El resto se sienta por debajo; el ducking se engancha a su cadena lateral.
    'audio' => [
        'library_path' => 'audio',
        'cache_match_threshold' => 0.6,
        'category_threshold' => 0.3,
        'categories_path' => 'audio/categories.json',
        'core_search_candidates' => 12,
        'resolve_budget_seconds' => 20,
        'resolve_total_budget_seconds' => 600,
        // Ambiente que sigue sonando tras el WAV de narración. El máster dura NarrationClock::masterDuration.
        'tail_seconds' => 10.0,
        'freesound' => [
            'token' => env('FREESOUND_TOKEN'),
            'base_url' => 'https://freesound.org/apiv2',
            'timeout' => 60,
            'rate_limit_seconds' => 1.0,
            'max_retries' => 4,
        ],
        'mix' => [
            'narration_lufs' => -14.0,
            'ambience_lufs_min' => -32.0,
            'ambience_lufs_max' => -28.0,
            'sfx_true_peak_dbtp' => -20.0,
            'music_lufs' => -30.0,
            'duck_db_min' => 6.0,
            'duck_db_max' => 9.0,
        ],
        'resolve' => [
            'search_candidates' => 8,
            'verify_attempts' => 3,
            'silent_rms_db' => -50.0,
            'synth_path' => 'tmp/audio-synth',
        ],
        'ambience' => [
            'acrossfade_seconds' => 2.0,
            'intensity_lufs' => [
                'subtle' => -34.0,
                'moderate' => -30.0,
                'heavy' => -27.0,
            ],
        ],
        'sfx' => [
            'lead_seconds' => 0.15,
            'min_gap_seconds' => 4.0,
        ],
        // En terror el silencio trabaja más que cualquier score. Prueba el vídeo entero sin música.
        'music_enabled' => false,
        'music' => [
            'hook_fade_out' => 4.0,
            'climax_start_ratio' => 0.75,
            'climax_tail_seconds' => 8.0,
            'climax_fade_in' => 6.0,
            'climax_fade_out' => 5.0,
        ],
        'seed' => [
            'ambience' => [
                'wind howling night',
                'rain on window',
                'empty room tone',
                'forest night crickets',
                'low drone ominous',
                'distant thunder',
                'old house creaking ambience',
                'wind through trees',
            ],
            'sfx' => [
                'door creak slow',
                'footsteps gravel',
                'footsteps wooden floor',
                'wood crack single',
                'metal gate',
                'dog barking distant',
                'radio static',
                'glass crack',
                'breathing close',
                'chair scrape',
                'keys jingle',
                'water drip',
            ],
        ],
    ],

    // El render no es un solo filter_complex: planos → escenas → vídeo mudo → máster.
    'video' => [
        'width' => 1920,
        'height' => 1080,
        'fps' => 30,
        'source_upscale' => 2.0,
        'zoom_max' => 1.18,
        'transition_duration' => 0.5,
        'scene_fade_duration' => 0.8,
        'intermediate_crf' => 12,
        'final_crf' => 19,
        'preset' => 'medium',
        'grade' => [
            'grain' => 8,
            'vignette' => 0.4,
            'saturation' => 0.85,
            'contrast' => 1.06,
        ],
        'outro_seconds' => 20,
        'work_path' => 'render',
    ],

];
