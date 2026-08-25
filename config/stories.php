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
        'timeout' => 900,
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

    'image_style_suffix' => 'cinematic horror still, desaturated earth tones, heavy fog, 35mm film grain, low key lighting, rural Iberian or Latin American setting, no text, no faces',

    'output_path' => 'stories',

];
