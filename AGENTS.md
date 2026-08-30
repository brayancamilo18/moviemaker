# Contexto del proyecto

App Laravel que genera vídeos de historias de terror para YouTube. El pipeline está construido de
punta a punta: del guion al MP4 con subtítulos.

Hay SQLite y cola `database`; la UI aún no. Los comandos artisan siguen pudiendo ejecutar cada
etapa a mano. El panel encola el mismo pipeline vía `PipelineDispatcher`. Los artefactos de
cada historia viven en `storage/app/stories/{slug}/`; el guion se escribe plano en
`storage/app/stories/{slug}.json`, donde el slug es `{YYYY-MM-DD}-{titulo-slugificado}`.

## Orden del pipeline (no alterar)
guion → narración → timings → planos → dirección visual y sonora → imágenes y sonidos → mezcla → render

La duración de la línea de tiempo la fija SIEMPRE `NarrationClock` sobre `narration.wav`.
Los timestamps de whisper son orientativos: nunca se usan para recortar audio.
Cada imagen y cada efecto pertenecen a un plano concreto, con su narración literal.
NUNCA introduzcas `atempo`, `setpts` ni `-itsoffset` para cuadrar duraciones: si la aritmética no
cuadra, se lanza una excepción que diga dónde se acumuló el desfase.

## Comandos artisan

15 comandos. Las rutas de artefactos son relativas a `storage/app/stories/{slug}/` salvo que se
indique otra cosa.

### Pipeline de historia

| Etapa | Comando | Consume | Escribe |
|---|---|---|---|
| guion | `story:generate` | `resources/lore/folklore.json`, API Gemini | `storage/app/stories/{slug}.json` |
| narración + timings | `story:narrate` | `{slug}.json`, caché `storage/app/tts-cache/`, sidecar Kokoro, modelo whisper | `narration.wav`, `narration.mp3`, `timings.json`; bloque `audio` en `{slug}.json` |
| planos + dirección visual + imágenes | `story:images` | `{slug}.json`, `timings.json`, `narration.wav`, `shots.json` previo | `shots.json`, `storage/app/image-cache/*.jpg`; `visualBible` en `{slug}.json` |
| revisión de planos | `story:contactsheet` | `{slug}.json`, `shots.json`, imágenes referenciadas | `contact-sheet-{N}.jpg` |
| dirección sonora + sonidos | `story:sounds` | `{slug}.json`, `timings.json`, `shots.json`, `sounds.json` previo, librería de audio | `sounds.json` |
| mezcla | `story:mix` | `{slug}.json`, `narration.wav`, `timings.json`, `sounds.json`, `shots.json`, WAVs de librería | `narration_mix.wav`, `narration_mix.mp3`, `credits.txt` |
| render | `story:render` | `{slug}.json`, `shots.json`, `narration_mix.wav\|mp3`, `timings.json`, imágenes de los planos | `video.mp4` (o `video-nograde.mp4`), `subtitles.srt`; bloque `video` en `{slug}.json` |

Transversales, no producen artefactos de pipeline:

| Comando | Consume | Escribe |
|---|---|---|
| `story:doctor` | binarios, modelo whisper, sidecar TTS, `resources/audio/manifest.json` y sus WAV | nada |
| `story:validate` | `narration.wav`, `narration_mix.wav\|mp3`, `shots.json`, `sounds.json` | nada |

### Librería de audio

| Comando | Consume | Escribe |
|---|---|---|
| `audio:core-kit` | `resources/audio/categories.json`, Freesound | `resources/audio/core/*.wav`, `resources/audio/manifest.json` |
| `audio:seed` | `config/stories.php` → `audio.seed`, Freesound | `resources/audio/{type}/*.wav`, `storage/app/audio/library.json` |
| `audio:fetch` | Freesound | `resources/audio/{type}/*.wav`, `storage/app/audio/library.json` |
| `audio:resolve` | `{slug}.json` (opcional) o `--type`/`--query`, librería | consola; la resolución puede añadir WAVs e indexarlos en `library.json` |
| `audio:list` | ambos índices y los WAV en disco | solo con `--prune`: reescribe `storage/app/audio/library.json` |
| `audio:credits` | clips con `attribution_required` de ambos índices | solo con `--write`: `ATTRIBUTION.md` en la raíz |

### Firmas reales

```
story:doctor
    {--warn-only : Informa y sale con éxito aunque haya fallos bloqueantes}

story:generate {--premise=} {--mode=} {--lore=} {--count=1} {--no-review} {--dry-run}

story:validate
    {file : JSON del guion}

story:narrate
    {file : Ruta al JSON del guion generado en la Fase 1}
    {--voice= : Voz de Kokoro}
    {--speed= : Velocidad de habla}
    {--no-cache : Ignora la caché de WAV}
    {--skip-timings : No genera timings.json}
    {--timings-only : Alinea un máster existente y escribe timings.json}

story:images
    {file : Ruta al JSON del guion generado en la Fase 1}
    {--only= : Planos a regenerar: 12, 40-45, 3,7,19}
    {--force : Ignora la caché y suma un offset al seed}
    {--dry-run : Planifica e imprime los prompts sin generar imágenes}

story:contactsheet
    {file : Ruta al JSON del guion generado en la Fase 1}

story:sounds
    {file : JSON del guion}
    {--refresh : Vuelve a resolver todas las señales}
    {--refresh-cue= : Fuerza la resolución de una señal concreta}
    {--audit : Solo audita lo ya resuelto, sin tocar nada}

story:mix
    {file : JSON del guion}
    {--no-music : Omite la música}
    {--no-sfx : Omite los efectos}
    {--no-ambience : Omite la cama de ambiente}
    {--dry-run : Imprime la tabla de pistas sin generar audio}

story:render
    {file : JSON del guion}
    {--from= : Reinicia desde clips, scenes, assemble o encode}
    {--keep-intermediates : Conserva clips, escenas y vídeo mudo}
    {--no-grade : Codifica sin corrección de color, para comparar}
    {--dry-run : Imprime el plan y lo compara con el audio, sin renderizar}

audio:core-kit
    {--verify : Comprueba que las 24 categorías tienen fichero y pasan el verificador}
    {--force : Vuelve a descargar aunque el fichero ya exista}
    {--only= : Slugs separados por coma para tocar categorías sueltas}

audio:seed {--dry-run : Lista las búsquedas y resultados sin descargar}

audio:fetch
    {--type= : ambience, sfx o music}
    {--query= : Texto de búsqueda en Freesound}
    {--limit=5 : Máximo de resultados a considerar}
    {--dry-run : Busca y muestra la tabla sin descargar}
    {--yes : Descarga sin pedir confirmación}

audio:resolve
    {file? : JSON del guion (opcional)}
    {--type= : ambience, sfx o music (si no pasas un guion)}
    {--query= : Consulta (si no pasas un guion)}

audio:list
    {--type= : ambience, sfx o music}
    {--tag= : Filtra por tag}
    {--prune : Quita del índice local los clips cuyo fichero ya no está en disco}

audio:credits
    {--write : Vuelca la atribución a ATTRIBUTION.md en la raíz del proyecto}
```

Notas de comportamiento que no se ven en la firma:

- `story:narrate --timings-only` alinea un `narration.wav` que ya existe; no vuelve a sintetizar.
- `story:sounds --audit` no escribe `sounds.json`: solo audita lo ya resuelto.
- `story:render` deja los intermedios en `storage/app/render/{slug}/` y los borra salvo
  `--keep-intermediates`. Los subtítulos son un `.srt` externo, no van quemados en el MP4.
- `story:mix` sincroniza `sounds.json` si falta antes de mezclar, y borra el `mix.wav` intermedio.
- `audio:list --prune` solo toca el índice local; nunca el manifiesto del core.
- Las firmas de arriba son literales. Tres de ellas (`story:narrate`, `story:images`,
  `story:contactsheet`) todavía describen su argumento como «el JSON del guion generado en la
  Fase 1»: es nomenclatura muerta de cuando el repo se organizaba por fases. Se refieren
  simplemente al JSON que escribe `story:generate`.

## Cola

El pipeline del panel no se lanza con los comandos artisan: `PipelineDispatcher` encola
`RunPipelineStep` (script → narration → images → sound → render). `sound` resuelve
`sounds.json` y mezcla. Cada job, si termina bien, llama a `advance()` y encola el siguiente
hasta `renderizada`. `runFrom($story, $step)` reencola desde un paso concreto, p. ej. tras
`fallida`.

`QUEUE_CONNECTION=database`. Hace falta un worker en marcha:

```
php artisan queue:work
```

Sin él, los jobs se quedan en la tabla `jobs` y la historia no avanza. Un job no se reintenta
(`$tries = 1`, `$timeout = 3600`): si falla, el estado pasa a `fallida` y la persona decide.
El progreso vive en caché bajo `pipeline:{id}` (TTL una hora); el panel lo sondea.

## timings.json (entrada del generador de imágenes)

`story:images` **no transcribe ni estima duraciones**. Lee `storage/app/stories/{slug}/timings.json`, escrito por `App\Services\Audio\TranscriptTimer` tras alinear el máster (`narration.wav`) con las frases originales.

`TranscriptTimer::time($slug, $audioPath, $sentences)` es el método que produce este fichero. `start`/`end` van en **segundos** (3 decimales) y marcan solo el habla; el silencio posterior es `pauseAfter` y/o el `start` de la frase siguiente.

```json
{
  "version": 3,
  "sentences": [
    {
      "order": 1,
      "sceneOrder": 1,
      "text": "The door closed.",
      "ttsText": "The door closed.",
      "start": 0.0,
      "end": 1.42,
      "pauseAfter": 0.45,
      "alignment": "text",
      "words": [
        { "token": "the", "start": 0.0, "end": 0.24 },
        { "token": "door", "start": 0.24, "end": 0.71 },
        { "token": "closed", "start": 0.71, "end": 1.42 }
      ]
    }
  ],
  "scenes": [
    {
      "order": 1,
      "start": 0.0,
      "end": 14.2,
      "duration": 14.2,
      "sentenceCount": 8
    }
  ]
}
```

| Campo | Dónde | Qué es |
|---|---|---|
| `version` | raíz | Esquema. Hoy `3`. Si cambia, bump. |
| `sentences[].order` | frase | Orden global de narración, 1-based. |
| `sentences[].sceneOrder` | frase | Escena del guion (`StoryScene.order`). Agrupa el plano. |
| `sentences[].text` | frase | Texto original del guion, no la transcripción de Whisper. |
| `sentences[].ttsText` | frase | La misma frase con la fonética aplicada, que es lo que se sintetizó. |
| `sentences[].start` | frase | Inicio del habla de esa frase en el máster. |
| `sentences[].end` | frase | Fin del habla. **No incluye** la pausa. |
| `sentences[].pauseAfter` | frase | Silencio pedido al ensamblar (s). Entre escenas es el valor largo. |
| `sentences[].alignment` | frase | `text` = emparejado por texto normalizado; `sequential` = respaldo por posición. Diagnóstico; el generador de imágenes puede ignorarlo. |
| `sentences[].words` | frase | Palabras con su ventana real, tal como las oyó whisper: token normalizado, `start` y `end` dentro de la ventana de la frase. **Solo las traen las frases con `alignment: text`**; en las `sequential` va vacío, porque unos tiempos por palabra inventados serían peores que ninguno. |
| `scenes[].order` | escena | Igual que `sceneOrder`. Una entrada por escena, en orden. |
| `scenes[].start` | escena | `start` de la primera frase de la escena. |
| `scenes[].end` | escena | `start` de la siguiente escena; en la última, `end` de la última frase + su `pauseAfter`. |
| `scenes[].duration` | escena | `end - start`. Tiempo que el plano de esa escena permanece en pantalla. |
| `scenes[].sentenceCount` | escena | Frases cubiertas por ese plano. |

Cómo usar esto al generar imágenes:

1. Una imagen por escena, la de `StoryScene.imagePrompt` con el mismo `order` que `scenes[].order`.
2. Duración del plano = `scenes[].duration` (no recalcular a partir de recuento de palabras).
3. Corte entre planos = `scenes[].end` de una escena = `scenes[].start` de la siguiente. Las pausas entre escenas quedan dentro del plano que termina.
4. No uses `sentences[].end` como corte de plano: deja fuera el silencio y desincroniza imagen y voz.

`words` no lo usa el generador de imágenes: es lo que hace que un efecto puntual suene a tiempo.
Cada efecto de `sounds.json` declara la palabra de la que cuelga (`anchorWord`) y `SfxPlacer` lo
coloca en el `start` de esa palabra menos `audio.sfx.lead_seconds`, menos el silencio que traiga el
propio clip antes de su primer golpe (`LibraryClipProcessor::onsetSeconds()`). Un efecto cuya palabra
no esté en `words` **no se coloca**: `story:mix` lo dice y `story:validate` lo cuenta. Los ambientes
no dependen de esto, se colocan por escena.

## Resolución: por qué el vídeo sale a 720p

**El proveedor de imágenes topa en 1024×576 y no hay forma de pedirle más sin cuenta.** Pollinations
devuelve ese tamaño tanto si le pides 1280×720 como 1920×1080, y lo mismo con `flux`, `turbo`,
`z-image-turbo` y `nanobanana-pro`: recorta la petición, responde 200 y no lo dice en ninguna parte.
Los modelos que sí dan más (`seedream`, `nanobanana`, `kontext`) exigen credenciales.

De ahí sale toda la cadena de números de `config/stories.php`:

| Clave | Valor | Por qué |
|---|---|---|
| `images.width` / `images.height` | 1024 × 576 | Lo que el proveedor entrega de verdad. Pedir más solo sirve para creerse una nitidez que no existe. |
| `video.width` / `video.height` | 1280 × 720 | Estirar 1024 px a 1080p es un upscale de 1,875x y se ve blando; a 720p es 1,25x. |
| `video.source_upscale` | 1.25 | Multiplica la resolución de **salida**, no la de la imagen: es el tamaño al que se escala el fotograma antes del `zoompan`. |
| `video.zoom_max` | 1.18 | El `zoompan` recorta `iw/zoom`, así que `source_upscale` nunca puede ser menor que esto. |

Dos guardas, porque este fallo es mudo por naturaleza:

- `ShotClipRenderer` lanza en el constructor si `source_upscale < zoom_max`. Escalar por debajo del
  zoom deja al recorte sin píxeles y todos los planos salen blandos sin avisar.
- `PollinationsGenerator` compara el tamaño devuelto con el pedido y deja un warning por ejecución
  cuando no coinciden. `story:doctor` imprime además la cuenta completa: fuente, píxeles necesarios
  (`video.width × zoom_max`) y el estiramiento resultante. Es información, no un fallo: mientras el
  techo sea del proveedor no hay nada que arreglar en la máquina.

El modelo también pinta de vez en cuando la escena **como una copia enmarcada**, con bandas claras y
planas arriba y abajo (una de cada treinta, más o menos). `PollinationsGenerator` las detecta por
brillo y uniformidad (`images.letterbox`), recorta lo que queda dentro, lo ajusta al aspecto pedido y
lo reescala al tamaño de `images`. Se pierde un poco de encuadre y se gana el plano entero. El
recorte es idempotente y se aplica también al servir de la caché, así que las imágenes descargadas
antes de esto se arreglan solas en la siguiente pasada. Si la banda clara ocupa más de
`letterbox.max_ratio` de un lado ya no es un marco, es contenido: se deja intacta y se avisa.

La resolución entra en el `sha1` de la caché de imágenes, así que cambiar `images.width` invalida lo
cacheado y hay que regenerar. Subir de 720p exige, en este orden: cuenta en el proveedor, un modelo
que devuelva de verdad más píxeles, `images.width` a lo que ese modelo entregue y `video.width`
detrás. Cambiar solo `video.width` empeora la imagen.

## Librería de audio

Hay **dos índices** con el mismo esquema y responsabilidades distintas. `AudioLibrary::read()` los
fusiona en lectura: el manifiesto manda en el orden y el índice local pisa las entradas que
repitan el mismo `file`.

| | `resources/audio/manifest.json` | `storage/app/audio/library.json` |
|---|---|---|
| Qué indexa | Solo el core kit (`core/*.wav`, 24 categorías) | Clips locales: descargas de Freesound y beds sintetizados |
| Método que escribe | `AudioLibrary::addCore()` | `AudioLibrary::add()` |
| Único escritor | `CoreKitInstaller` (desde `audio:core-kit`) | `SoundLibraryImporter` y `SoundResolver` |
| `is_core` | `true` | `false` |
| En git | **Sí**, versionado junto a `resources/audio/core/**/*.wav` | **No**, `/storage/app/audio/` está en `.gitignore` |
| `prune()` | no lo toca nunca | sí, quita las entradas sin fichero |

Rutas configurables en `config/stories.php`: `audio.library_path` (raíz, `resources/audio`) y
`audio.local_index_path` (`storage/app/audio/library.json`).

Esquema de ambos ficheros: `{"version": 1, "clips": [...]}`. Cada clip lleva `file` (relativo a la
raíz de la librería), `type`, `tags`, `duration`, `loopable`, `source_id`, `source_url`, `author`,
`license`, `attribution_required`, `lufs`, `sha1` e `is_core`. No se puede indexar un clip cuyo WAV
no esté en disco: `index()` lanza `RuntimeException`.

**`clips()` vs `allClips()`** — la distinción importa y es fácil de equivocar:

- `clips()` devuelve solo las entradas cuyo fichero sigue en disco. Las que faltan se descartan en
  silencio (un único warning de log por instancia). Es lo que consumen `filter()`, la resolución y
  la atribución.
- `allClips()` devuelve la unión cruda de los dos índices, **incluidas** las entradas huérfanas. Lo
  usan el diagnóstico (`missingCoreClips()`), `audio:list --prune` y `filter(includeMissing: true)`.

`AttributionWriter` es la única fuente del texto de atribución y no elige ruta: genera el
contenido y los comandos deciden dónde va. `audio:credits --write` escribe `ATTRIBUTION.md` en la
raíz del repo con `document()`; `story:mix` escribe `{slug}/credits.txt` con `storyDocument()`.

## Idioma y localización

**Decisión de producto: terror en inglés estadounidense con ambientación latinoamericana.** El
español aparece solo como nombres propios dentro de la narración inglesa; para eso existe el campo
`pronunciations` del schema, que mapea cada término español a su fonética para un lector TTS
angloparlante (`Story::textForTts()` hace la sustitución antes de enviar el texto al sidecar).

El idioma del guion es configurable con `STORY_LANGUAGE` (`config/stories.php` →
`story.language`, por defecto `en`). **Cambiar esa variable sola no cambia el idioma del producto**:
hay que tocar todos estos puntos, que hoy están desacoplados entre sí.

Configuración y entorno:

1. `STORY_LANGUAGE` → `stories.story.language`. Es también el respaldo de `TranscriptTimer` cuando
   `WHISPER_LANGUAGE` viene vacío.
2. `stories.story.accent` en `config/stories.php`: hardcodeado a `neutral_american`. El mapa de
   acentos vive en `StoryPromptBuilder`.
3. `WHISPER_LANGUAGE` en `.env`: debe coincidir con el idioma del guion.
4. `WHISPER_MODEL`: el respaldo es `storage/app/whisper/ggml-base.en.bin`, variante **English-only**.
   Cualquier otro idioma exige un modelo multilingüe (`ggml-base.bin`, `ggml-small.bin`…).
5. `TTS_VOICE` → `stories.tts.voice`, por defecto `af_heart` (voz inglesa de Kokoro).
6. `KOKORO_LANG` en el sidecar (`tts-service/main.py`, `VOICES_BY_LANG`): `a` = inglés americano,
   `e` = español. **No se envía desde Laravel**: se fija al arrancar el proceso Python y la voz que
   mande Laravel tiene que pertenecer a ese idioma. No está en `.env.example` porque es del sidecar.
7. `phpunit.xml` fija `TTS_VOICE=af_heart`.

Prompts y schema (todos hardcodean inglés en el texto que se le pide al LLM):

8. `StoryPromptBuilder`: `OUTPUT LANGUAGE: English`, las reglas de ortografía por acento, los
   `visualSummary`, las `query`/`tags` de sonido y el bloque `Pronunciations`.
9. `StorySchema`: las `description` de `title`, `hook`, `description`, `tags`, `thumbnailPrompt`,
   `narration`, `pronunciations` y las queries de sonido exigen inglés.
10. `StoryReviewer`: revisa como editor de narración en inglés para audiencia de YouTube en EEUU.
11. `VisualBibleGenerator` y `ShotDirector`: prompts de imagen en inglés.
12. `SfxDirector`: pide la query de sonido en inglés.

Datos y assets:

13. `stories.image_style_suffix`: hardcodeado, en inglés y con `rural Iberian or Latin American setting`.
14. `stories.audio.seed`: consultas de Freesound en inglés.
15. `resources/audio/categories.json`: `keywords` y `curatedQuery` en inglés.
16. `resources/lore/folklore.json`: nombres en español, resúmenes en inglés.

Subtítulos:

17. No hay selector de idioma. `SubtitleGenerator` reutiliza el texto del guion de `timings.json`
    (nunca la transcripción de Whisper ni la fonética del TTS). Sus listas `ARTICLES` y
    `CONJUNCTIONS` son bilingües EN+ES, pero son una heurística de corte de línea, no un selector.

`APP_LOCALE`, `APP_FALLBACK_LOCALE` y `APP_FAKER_LOCALE` son del framework y no afectan al
contenido generado.

## Reglas de código (no negociables)

- PHP 8.3+, `declare(strict_types=1)` en todos los archivos nuevos y editados.
- Clases `final` y propiedades `readonly` por defecto.
- Nombres de clases, métodos y variables en inglés. Comentarios y mensajes de consola en español.
- **Prohibido usar facades** (`Illuminate\Support\Facades\*`) dentro de `app/`. Inyección por
  constructor: `Repository $config`, `Filesystem $files`, `Http\Client\Factory $http`,
  `LoggerInterface $logger` (PSR, nunca la facade `Log`).
- Un archivo, una responsabilidad. No metas lógica de negocio en los comandos artisan: el
  `handle()` parsea opciones, delega en un service y presenta el resultado.
- Para HTTP, el cliente `Http` de Laravel inyectado como `Factory`.
- Para procesos externos, `Symfony\Component\Process\Process` **siempre con array de argumentos**.
  Nunca `fromShellCommandline`, `shell_exec`, `exec` ni `proc_open`.
- Tipado completo: todo parámetro con tipo, todo método con tipo de retorno, docblocks con array
  shapes (`list<Shot>`, `array{start: float, end: float}`) y nunca `array` pelado.
- Nada de números mágicos nuevos: si es un parámetro, va a `config/stories.php`.
- No instales paquetes sin preguntar antes.
- No crees archivos que no te haya pedido explícitamente.
- **No toques `.env` ni pidas ver su contenido.**

## Tests

PHPUnit (no Pest). `tests/Feature` y `tests/Unit`. La red se simula siempre con `Http::fake()` y
`Http::preventStrayRequests()`. Los ficheros de prueba van a
`storage/app/testing/<algo>-<random_bytes>` y se limpian en `tearDown`.

Un test que hay que modificar para que pase es una señal de alarma, no un paso del trabajo: si te
pasa, para y dilo.
