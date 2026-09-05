<?php

declare(strict_types=1);

// El motor de voz decide los valores por defecto de voz y velocidad. Los IDs de Kokoro
// (af_heart) y los de Inworld (Blake) no son intercambiables, y NarrationStep lee
// stories.tts.voice sin saber qué driver hay detrás: si el defecto no dependiera del
// driver, Inworld recibiría una voz de Kokoro y respondería 400.
$ttsDriver = env('TTS_DRIVER', 'kokoro');

return [

    'llm' => [

        // Proveedor que atiende primero. El respaldo solo entra cuando este no responde.
        'provider' => env('LLM_PROVIDER', 'gemini'),

        // Respaldo cuando el principal se cae. AI_FEATURES_ENABLED lo apaga sin borrar la clave.
        'fallback' => (bool) env('AI_FEATURES_ENABLED', true) ? 'anthropic' : '',

        // Techo del reintento cuando la salida se corta por presupuesto, no por indisponibilidad.
        'truncation_retry_cap' => 64000,

        'gemini' => [
            'api_key' => env('GEMINI_API_KEY'),
            'base_url' => 'https://generativelanguage.googleapis.com/v1beta',
            'timeout' => 120,
            'max_retries' => 3,
            // 'default' vale para toda tarea que no aparezca aquí con nombre propio.
            'models' => [
                'default' => env('GEMINI_MODEL', 'gemini-3.6-flash'),
                'review' => env('GEMINI_REVIEW_MODEL', env('GEMINI_MODEL', 'gemini-3.6-flash')),
            ],
        ],

        'anthropic' => [
            'api_key' => env('ANTHROPIC_API_KEY'),
            'base_url' => 'https://api.anthropic.com/v1',
            'version' => '2023-06-01',
            // Cabecera anthropic-beta, por si output_config deja de ser estable. Vacío: no se manda.
            'beta' => env('ANTHROPIC_BETA', ''),
            'timeout' => 180,
            'max_retries' => 3,
            'models' => [
                'default' => env('AI_MODEL', 'claude-haiku-4-5'),
            ],
            // Anthropic exige un tope de salida en cada petición. El guion y la dirección de
            // planos son los que más crecen; review escala con la longitud del texto.
            'max_tokens' => [
                'default' => 8000,
                'script' => 32000,
                'review' => 16000,
                'visual_bible' => 8000,
                'shot_direction' => 16000,
                'sfx_direction' => 8000,
            ],
        ],

        // Conversión aproximada solo para mostrar. No es contabilidad.
        'usd_to_eur' => 0.92,

        // Dólares por millón de tokens. Gemini en free tier no factura; Anthropic sí.
        'pricing' => [
            'anthropic' => [
                'claude-haiku-4-5' => ['input' => 1.00, 'output' => 5.00],
                'default' => ['input' => 1.00, 'output' => 5.00],
            ],
            'gemini' => [
                // El free tier no factura. Si algún día pasas a de pago, pon aquí las tarifas.
                'default' => ['input' => 0.0, 'output' => 0.0],
            ],
        ],

    ],

    'story' => [
        'target_words' => 1600,

        // Margen admitido alrededor de target_words. Estaba a fuego dos veces, en StoryGenerator
        // y en StoryPromptBuilder; ahora los dos leen de aquí para que el validador y lo que se
        // le pide al modelo no puedan discrepar.
        //
        // A ±15% el techo son 1840 palabras, unos dieciséis minutos de vídeo. El ±40% de antes
        // dejaba pasar 2240, que son veinte, y por ahí se colaron las 2105 palabras que hicieron
        // el máster de 19:34. No se aprieta más porque el modelo tiene tres intentos y cada uno
        // cuesta una generación y una revisión: un margen demasiado estrecho se los come.
        'word_tolerance' => [
            'min_ratio' => 0.85,
            'max_ratio' => 1.15,
        ],
        'min_scenes' => 8,
        'max_scenes' => 20,
        'language' => env('STORY_LANGUAGE', 'en'),
        'default_mode' => 'folklore',
        'accent' => 'neutral_american',
        // Guiones que se generan por historia para quedarse con el de mejor puntuación. Cada
        // candidato es una generación y una revisión, así que el gasto de LLM va con este número.
        // Sin revisión no hay puntuación con la que elegir y se genera uno solo.
        'candidates' => 3,
        // Arranque del vídeo, en este orden: cold open, careta y ya la historia. El cold open lo
        // escribe el LLM (campo coldOpen del guion) y es texto nuevo, nunca una copia literal de
        // una frase de la historia: whisper alinea con un cursor que avanza y el texto repetido
        // deja la segunda aparición sin anclar, y una frase sin anclar se queda sin `words`.
        'cold_open' => [
            'enabled' => env('STORY_COLD_OPEN_ENABLED', true),
            // Negativo y por debajo de la careta: ShotPlanner y TranscriptTimer ordenan las
            // escenas con ksort, así que el orden numérico es el orden del vídeo.
            'scene_order' => -2000,
            // Silencio entre el cold open y la careta. Es el corte, así que pesa más que la
            // pausa normal entre escenas, pero por el mismo motivo que tts.pauses ya no hace
            // falta que pese el doble: los clips vienen sin silencio propio.
            'trail_pause' => 1.3,
        ],
        'intro' => [
            'enabled' => env('STORY_INTRO_ENABLED', true),
            'scene_order' => -1000,
            'trail_pause' => 1.1,
            'text' => 'You are listening to a story someone swore was true. Turn the lights down, put your headphones on, and stay with me all the way to the end.',
            'image_prompt' => 'narrow dirt road at night swallowed by thick fog, one distant light, nobody there',
        ],
        'outro' => [
            'enabled' => env('STORY_OUTRO_ENABLED', true),
            'scene_order' => 9000,
            'lead_pause' => 1.5,
            'text' => 'That was the story for tonight. If you stayed with me all the way to the end of it, thank you. Subscribe, and turn on the bell, and I will have another one for you soon. Sleep well, if you can.',
            'image_prompt' => 'empty dark room at night, one dim lamp still burning, thick fog, nobody there',
        ],
    ],

    'review' => [
        'enabled' => true,
    ],

    'pipeline' => [
        'progress_ttl' => 3600,
        'failed_message_max' => 2000,
        'stale_job_seconds' => 15,
        // El worker escribe su latido en cada vuelta del bucle, también ocioso. El TTL solo
        // tiene que cubrir el hueco entre dos vueltas con holgura: por encima de esto se da
        // por muerto. Un worker ocupado no late —está dentro del job—, y eso lo cubre
        // QueueHealth mirando los trabajos reservados.
        'worker_heartbeat_ttl' => 30,
        'stale_draft_seconds' => 30,
        // El render deja intermedios en storage/app. Por debajo de esto el paso ni se encola.
        'work_path' => storage_path('app'),
        'min_free_disk_bytes' => 5 * 1024 * 1024 * 1024,
    ],

    'doctor' => [
        'internet_timeout' => 10,
        'config_cache_path' => 'bootstrap/cache/config.php',
        'worker_command' => 'php artisan queue:work --tries=1',
        'gemini_probe' => 'https://generativelanguage.googleapis.com',
        'anthropic_probe' => 'https://api.anthropic.com',
    ],

    'tts' => [
        // Motor de voz: 'kokoro' (sidecar local) o 'inworld' (API de pago con tramo gratuito).
        'driver' => $ttsDriver,

        // Voz y velocidad efectivas del driver activo. Cada motor tiene su propia variable de
        // entorno para que un .env con las dos configuradas no mezcle IDs entre proveedores.
        'voice' => $ttsDriver === 'inworld'
            ? env('INWORLD_VOICE', 'Blake')
            : env('TTS_VOICE', 'af_heart'),
        'speed' => (float) ($ttsDriver === 'inworld'
            ? env('INWORLD_SPEED', 1.10)
            : env('TTS_SPEED', 1.0)),

        'base_url' => env('TTS_BASE_URL', 'http://127.0.0.1:8020'),
        'timeout' => 120,
        'cache_path' => 'tts-cache',

        'inworld' => [
            'api_key' => env('INWORLD_API_KEY'),
            'base_url' => 'https://api.inworld.ai',

            // inworld-tts-2 es el único que respeta instruction y delivery_mode; el Flash los
            // ignora en silencio, y son justamente el motivo de usar este proveedor.
            'model' => env('INWORLD_MODEL', 'inworld-tts-2'),

            // BCP-47. Inworld rechaza 'en' a secas, que es lo que vale stories.story.language.
            'language' => env('INWORLD_LANGUAGE', 'en-US'),

            // STABLE, BALANCED o CREATIVE. CREATIVE da más rango emocional a cambio de más
            // variación, y la variación desvía al modelo del texto: whisper deja de alinear y
            // los efectos con anchorWord no se colocan.
            'delivery_mode' => env('INWORLD_DELIVERY_MODE', 'BALANCED'),

            // Dirección de interpretación. No se factura y es lo que más cambia el resultado:
            // medido sobre una historia real, quitarle el cierre "Do not drag" alarga la
            // narración de 11,7 a 16,3 minutos.
            'instruction' => 'Narrate with quiet, restrained dread at a steady, measured pace. '
                .'Intimate and controlled, never theatrical. Do not drag.',

            // 48 kHz es el ritmo natural de las voces y el que ya usa ffmpeg.mp3_sample_rate,
            // así que el máster no se remuestrea en ningún punto.
            'sample_rate' => 48000,

            // Denoising del audio sintetizado.
            'enhance_generation' => true,

            'timeout' => 120,

            // Una historia son cientos de peticiones seguidas, así que un fallo transitorio a
            // mitad no puede tirar la narración entera: ya pasó en la frase 146 de 252.
            'retry' => [
                'times' => 4,
                'sleep_ms' => 500,
                // 429 es el límite por minuto, no la cuota agotada, y los 5xx son del proveedor.
                // Un 400 o un 401 no se reintentan: reintentar una petición mal formada o una
                // credencial mala solo retrasa el error y gasta cuota si acaba entrando.
                'statuses' => [429, 500, 502, 503, 504],
            ],

            // Caché aparte de la de Kokoro para poder vaciar una sin tocar la otra y para
            // llevar la cuenta de lo que se ha facturado.
            'cache_path' => 'inworld-cache',

            // Inworld entrega cada frase con silencio propio delante de la primera sílaba y
            // detrás de la última: 1,685 s de media, el 36% del clip. Ese silencio se suma al
            // pauseAfter que ya calcula SentenceSplitter, así que una historia de 252 frases se
            // alargaba 2:09 sin que nadie lo hubiera pedido.
            //
            // Se recortan solo los bordes. Las pausas interiores, las de las comas, también son
            // largas (casi 5 minutos en la misma historia), pero ésas son la interpretación de la
            // voz: acotarlas es una decisión de dirección, no un arreglo.
            'trim' => [
                'enabled' => true,

                // Umbral de silencio. Medido sobre los clips reales: el ruido de fondo de Inworld
                // se queda por debajo de -60 dB y la primera sílaba entra muy por encima de -30.
                'threshold_db' => -50.0,

                // Silencio que se deja en cada borde para no comerse el ataque de la primera
                // sílaba ni la caída de la última.
                'guard_seconds' => 0.04,

                // Los recortados cuelgan de su propia carpeta y la respuesta cruda de la API se
                // conserva con su clave de siempre. Así cambiar el umbral se resuelve con ffmpeg
                // en local y no cuesta ni una petición, que es lo que se factura.
                'cache_path' => 'inworld-cache/trimmed',
            ],

            // Techo de texto por petición que impone la API.
            'max_characters' => 2000,

            // Fuera de este rango la API responde 400. Se valida al construir el driver para
            // no descubrirlo en la primera frase de cien.
            'min_speed' => 0.5,
            'max_speed' => 1.5,
        ],

        // Pausas en segundos. Son el 80% del efecto de terror: ajústalas a menudo.
        //
        // Bajaron cuando se empezó a recortar el silencio de los clips de Inworld. Antes cada
        // frase traía 1,685 s de silencio propio y estos valores se sumaban a él, así que el
        // hueco real era casi el doble del pedido; con los clips limpios el hueco es exactamente
        // este número más las dos guardas de 0,04 s, y los 1,8 s entre escenas se oían como dos
        // segundos secos, quince veces por historia.
        'pauses' => [
            'sentence' => 0.32,
            'question_or_exclamation' => 0.5,
            'ellipsis' => 0.8,
            'between_scenes' => 0.95,
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
        // Umbrales de calidad de la alineación. Por debajo de min_text_ratio hay demasiadas frases
        // colocadas por posición, y max_uncovered_seconds es el silencio de cola que se admite al
        // final del máster: la pausa entre escenas (1.8 s) más holgura.
        'alignment' => [
            'min_text_ratio' => 0.6,
            'max_uncovered_seconds' => 5.0,
        ],
    ],

    // Duraciones en segundos. Los cortes salen de timings.json; max_duration es un techo, no un objetivo.
    'shots' => [
        'min_duration' => 2.5,
        'max_duration' => 9.0,
        'target_duration' => 5.5,
        'tension_duration' => 3.5,
        'atmosphere_duration' => 8.0,
        // Holgura sobre max_duration antes de trocear una ventana: por debajo de este margen el
        // trozo que sobra no daría para un plano decente.
        'max_hold_slack' => 3.0,
    ],

    // Estos 1024×576 no son una preferencia: son el techo real del proveedor. Pollinations recorta
    // la petición en silencio (flux, turbo, z-image-turbo y nanobanana-pro devuelven 1024×576 tanto
    // si pides 1280×720 como 1920×1080), y los modelos de más resolución exigen cuenta. Pedir más de
    // lo que entrega solo sirve para creerse una nitidez que no existe: de ahí sale la salida a 720p
    // de video.width. Si algún día hay credenciales y un modelo mayor, esto sube y video.width con él.
    'images' => [
        'provider' => env('IMAGE_PROVIDER', 'pollinations'),
        // A la resolución del vídeo, no por debajo: generar a 1024×576 para un montaje
        // de 1280×720 obliga a un reescalado del 25% que ablanda cada plano, y el
        // proveedor cobra lo mismo por el tamaño mayor.
        'width' => 1280,
        'height' => 720,
        'rate_limit_seconds' => 6,
        'max_retries' => 4,
        'concurrency' => 1,
        'timeout' => 120,
        'cache_path' => 'image-cache',
        // Tope de palabras de la parte descriptiva del prompt: description del plano, tramo del
        // recorrido, etapa de luz, amenaza, encuadre, setting, clima y paleta. El sufijo de estilo
        // y los negativos se añaden después y no entran en el reparto, porque no son negociables.
        'max_prompt_words' => 75,
        // La cámara es el oyente, así que la única figura que puede salir es el ente. Se raciona
        // por dos motivos: el proveedor gratuito resuelve mal una cara a 1024×576 (con la cabeza a
        // 140-190 px hay píxeles para intentarla y no los suficientes para acertarla), y un ente
        // que asoma en uno de cada tres planos deja de acechar. El hueco lo llenan paisaje y
        // objeto, que es lo que este proveedor sí hace bien.
        'direction' => [
            'threat_ratio_min' => 0.12,
            'threat_ratio_max' => 0.25,
            'detail_ratio_max' => 0.35,
        ],
        // Que el proveedor se caiga no es una imagen mala: es no tener imagen. Rellenar con
        // marcadores convierte una caída de media hora en una historia entera que hay que rehacer,
        // así que cuando deja de responder se espera y se vuelve a pedir. Solo se rinde tras
        // probe_seconds × max_probes sin una sola respuesta útil, que es media hora larga.
        'outage' => [
            'probe_seconds' => 60,
            'max_probes' => 40,
        ],
        // Calidad del JPEG al reescribir una imagen recortada. Alta a propósito: es la segunda
        // codificación de la misma imagen y lo que se pierda aquí ya no se recupera.
        'jpeg_quality' => 92,
        // De vez en cuando el modelo pinta la escena como una copia enmarcada y devuelve bandas
        // claras arriba y abajo. Son bandas planas, así que se detectan y se recortan.
        'letterbox' => [
            // Brillo medio a partir del cual una fila o columna del borde cuenta como marco.
            'brightness' => 200,
            // Desviación típica máxima dentro de esa fila: un marco es plano, el cielo no.
            'uniformity' => 12.0,
            // Fracción máxima de cada lado que se acepta recortar. Por encima de esto ya no es un
            // marco, es contenido claro (niebla, un foco), y recortarlo sería peor que dejarlo.
            'max_ratio' => 0.2,
        ],
        'pollinations' => [
            'base_url' => 'https://image.pollinations.ai/prompt',
            'model' => 'flux',
        ],
    ],

    // Solo estilo fotográfico. El contenido —dónde pasa y qué tiempo hace— lo pone la
    // biblia de cada historia, que sabe si es un caserío ibérico o un archipiélago chileno.
    // Cuando el sufijo traía "heavy fog" y "rural Iberian setting" los imponía a los cien
    // y pico planos de cualquier historia, peleando con su propia ambientación.
    'image_style_suffix' => 'cinematic horror still, desaturated earth tones, 35mm film grain, low key lighting',

    'output_path' => 'stories',

    // Lo que deja de hacer falta dentro de storage/app/stories/{slug}/ cuando el MP4 ya existe.
    // Se borra al terminar bien el render, salvo --keep-audio. Son ~178 MB por historia, casi todo
    // en los dos WAV. Los JSON, el SRT y los créditos no entran: pesan nada y son el diagnóstico.
    // Rehacerlos cuesta: los MP3 y la mezcla salen de narration.wav, pero narration.wav exige
    // volver a sintetizar con el sidecar.
    'purge' => [
        'enabled' => true,
        // Nombres sueltos, nunca rutas: el purgador rechaza cualquier cosa con separador o «..».
        'artifacts' => [
            'narration.wav',
            'narration.mp3',
            'narration_mix.wav',
            'narration_mix.mp3',
        ],
        'patterns' => [
            'contact-sheet-*.jpg',
        ],
    ],

    // Intermedios que un Ctrl-C, un OOM o un SIGKILL dejan huérfanos. Los buckets son una lista
    // explícita relativa a storage/app: nunca un glob sobre todo tmp, y nunca fuera de storage/app.
    'temp' => [
        'max_age_seconds' => 86400,
        'buckets' => [
            'tmp/ambience-beds/*',
            'tmp/music-beds/*',
            'tmp/mixer-*',
            'tmp/ambience-*',
            'tmp/audio-core-*',
            'render/assemble-*',
        ],
    ],

    // La narración manda. El resto se sienta por debajo; el ducking se engancha a su cadena lateral.
    'audio' => [
        'library_path' => 'audio',
        // Índice local, fuera de git: clips descargados o sintetizados en esta máquina.
        // resources/audio/manifest.json solo lleva el core kit versionado.
        'local_index_path' => 'audio/library.json',
        'cache_match_threshold' => 0.6,
        'category_threshold' => 0.3,
        'categories_path' => 'audio/categories.json',
        'core_search_candidates' => 12,
        'resolve_budget_seconds' => 20,
        'resolve_total_budget_seconds' => 600,
        // Ambiente que sigue sonando tras el WAV de narración. El máster dura NarrationClock::masterDuration.
        'tail_seconds' => 6.0,
        'freesound' => [
            'token' => env('FREESOUND_TOKEN'),
            'base_url' => 'https://freesound.org/apiv2',
            'timeout' => 60,
            'rate_limit_seconds' => 1.0,
            'max_retries' => 4,
        ],
        // Solo niveles que alguien lee. Los de la narración y del máster están en ffmpeg.loudnorm;
        // los de la cama, en ambience.intensity_lufs. Duplicarlos aquí era config que mentía.
        'mix' => [
            // Techo de cada efecto al entrar en la suma: gainDb = este valor − true peak del clip.
            'sfx_true_peak_dbtp' => -20.0,
            'music_lufs' => -30.0,
        ],
        'resolve' => [
            'search_candidates' => 8,
            'verify_attempts' => 3,
            // Único umbral de mudez: lo aplica SoundVerifier a camas y a golpes por igual.
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
            // Cabeza muerta del clip: el silencio que trae antes del primer golpe audible. Se resta
            // al colocar, porque si no el golpe llega tarde por mucho que el ancla sea exacta.
            // El tope evita que una cama mal etiquetada como efecto adelante el clip medio segundo.
            'onset_threshold_db' => -40.0,
            'onset_max_seconds' => 1.5,
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
    //
    // La salida es 720p porque la fuente son 1024×576 (ver el comentario de images): estirar eso a
    // 1080p es un upscale de 1,875x que se ve blando, y a 720p es 1,25x. Menos resolución nominal y
    // más nitidez real.
    //
    // source_upscale multiplica la resolución de SALIDA, no la de la imagen: es el tamaño al que se
    // escala el fotograma antes del zoompan, y tiene que cubrir el recorte del zoom, así que nunca
    // puede ser menor que zoom_max. 1280 × 1.25 = 1600 px sobre el mínimo de 1280 × 1.18 = 1510 px.
    // Subirlo más no añade nitidez, solo píxeles: el detalle lo pone la fuente.
    'video' => [
        'width' => 1280,
        'height' => 720,
        'fps' => 30,
        'source_upscale' => 1.25,
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
        'outro_seconds' => 8,
        // Único desfase admitido entre la duración de un artefacto de vídeo y la que se esperaba.
        // Vale tanto para verificar lo que se acaba de escribir como para aceptar lo cacheado.
        'sync_tolerance' => 0.1,
        'work_path' => 'render',
    ],

    // Reglas de legibilidad del SRT. Longitudes en caracteres, duraciones y hueco en segundos.
    // gap es la separación mínima entre dos cues: por debajo de ella el reproductor los superpone.
    'subtitles' => [
        'max_line_chars' => 42,
        'max_lines' => 2,
        'min_duration' => 1.2,
        'max_duration' => 6.0,
        'gap' => 0.08,
        // Relativo a resources/. Listas de corte de línea por idioma, no un selector de idioma.
        'lexicon_path' => 'subtitles/lexicon.json',
    ],

];
