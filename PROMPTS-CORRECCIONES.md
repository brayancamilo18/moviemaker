# Batería de prompts para Cursor — correcciones de la auditoría

## Cómo ejecutar esto sin que Cursor pierda el hilo

**Regla de oro: un prompt = un chat nuevo.** Nada de encadenarlos. El límite real no es el
tamaño del prompt (40 líneas no son nada), es el contexto acumulado: a partir del tercer o cuarto
encargo en la misma conversación, Cursor empieza a mezclar tareas, a "arreglar" cosas que ya
habías cerrado y a dar por hecho ficheros que no ha leído.

Las barandillas ya están puestas en `.cursor/rules/moviemaker.mdc`, que se aplica a todos los
chats automáticamente: incluye las reglas del proyecto y un protocolo de trabajo que le prohíbe
salirse de la tarea, tocar ficheros no mencionados y darse por terminado sin ejecutar la
verificación. **No hace falta que copies nada de eso en cada prompt.**

### El ciclo, prompt a prompt

1. **Chat nuevo** en modo Agent. Si el anterior sigue abierto, ciérralo: no lo reutilices.
2. **Pega el bloque de código del prompt tal cual.** Sin añadir nada delante ni detrás. Si el
   prompt lleva un `<DECISIÓN>` o un aviso previo (los prompts 5, 15, 19 y 21), resuélvelo
   antes de pegar.
3. **Deja que ejecute la verificación.** Necesitas que Cursor pueda lanzar comandos en el
   terminal; si te pide permiso cada vez, permítele al menos `php artisan test`,
   `./vendor/bin/pint`, `./vendor/bin/phpstan` y `git diff`.
4. **Lee su resumen final** antes de aceptar nada. El protocolo le obliga a listar ficheros
   tocados, verificación ejecutada y hallazgos laterales. Si el resumen no está, no ha seguido
   las reglas: hazlo repetir.
5. **Commit con el número del prompt**, por ejemplo `fix(P07): el tope de palabras ya no amputa
   los negativos del prompt`. Así `git log --oneline` es tu barra de progreso y puedes volver
   atrás un solo paso.
6. **Marca la casilla** de la columna «Estado» en la tabla de "Resumen de ejecución" del final de
   este fichero.

> **Antes del primer prompt de cada sesión, lee la sección «Estado actual del repositorio»** (justo
> antes de la TANDA 1). Los prompts 1, 2 y 3 ya están aplicados y hay una tanda de saneado que no
> figuraba en esta lista; ahí está lo que ya existe y no hay que rehacer.

### Cuando se desvíe (va a pasar)

- **Empieza a tocar ficheros de más:** párale y escribe *"Vuelve atrás. Trabaja solo en los
  ficheros que menciona la tarea. Enumérame qué ficheros vas a tocar y espera mi OK antes de
  editar."*
- **Da la tarea por terminada sin verificar:** *"No has ejecutado los comandos de la sección
  «Verifica al terminar». Ejecútalos y pégame la salida."*
- **Modifica un test para que pase:** eso es cambio de comportamiento disfrazado. *"Restaura ese
  test. Si la tarea no se puede completar sin modificarlo, explícame el conflicto y para."*
- **Se enreda o alucina rutas:** no negocies. Restaura el checkpoint anterior, abre un chat nuevo
  y vuelve a pegar el mismo prompt. Un reintento limpio sale más barato que diez mensajes de
  corrección, y el prompt es determinista: puedes relanzarlo tantas veces como quieras.

### Ritmo realista

Cada prompt son entre 15 y 45 minutos de ida y vuelta, más el tiempo de revisar el diff. Los 24
no caben en un día. Un reparto que funciona:

- **Sesión 1 (1 h):** prompts 4 y 5. Cierra la tanda 1; 1, 2 y 3 ya están hechos.
- **Sesión 2 (3-4 h):** prompts 6 a 12. Aquí están los bugs que cuestan dinero.
- **Sesión 3 (2-3 h):** prompts 13, 14, 15 y 24. La red de seguridad. A partir de aquí trabajas
  tranquilo.
- **Después, sin prisa:** 16 a 23, de uno en uno, cuando te apetezca.

Si solo tienes una tarde: **6 → 7**. Desbloquea la ejecución y elimina los 20 minutos y
las ~200 imágenes que hoy se tiran en cada pasada.

---

Un prompt por corrección. Cada uno es **autocontenido**: Cursor arranca sin memoria de la
conversación anterior, así que cada prompt lleva su propio contexto, sus criterios de
aceptación y su comando de verificación.

**Convención dentro de los prompts:** no doy números de línea porque cambian en cuanto se
edita el fichero. Cada prompt le dice a Cursor qué código buscar textualmente.

---

## Paso 0 — Reglas permanentes del proyecto

Crea `.cursor/rules/moviemaker.mdc` con este contenido. Son las reglas de `AGENTS.md`
convertidas en reglas de Cursor, para que se apliquen a todos los prompts siguientes.

```markdown
---
description: Convenciones obligatorias del proyecto moviemaker
alwaysApply: true
---

# Proyecto

App Laravel 13 / PHP 8.3 que genera vídeos de terror para YouTube. Sin base de datos, sin
colas, sin UI. Todo se ejecuta con comandos artisan y escribe en `storage/app/stories/`.

Pipeline (orden congelado, no alterar):
guion → narración → timings → planos → dirección visual y sonora → imágenes y sonidos → mezcla → render

La duración de la línea de tiempo la fija SIEMPRE `NarrationClock` sobre `narration.wav`.
Los timestamps de whisper son orientativos: nunca se usan para recortar audio.

# Reglas de código (no negociables)

- PHP 8.3+, `declare(strict_types=1)` en todos los archivos nuevos y editados.
- Clases `final` y propiedades `readonly` por defecto.
- Nombres de clases, métodos y variables en inglés. Comentarios y mensajes de consola en español.
- **Prohibido usar facades** (`Illuminate\Support\Facades\*`) dentro de `app/`. Inyección por
  constructor: `Repository $config`, `Filesystem $files`, `Http\Client\Factory $http`,
  `LoggerInterface $logger` (PSR, nunca la facade `Log`).
- Un archivo, una responsabilidad. No metas lógica de negocio en los comandos artisan: el
  `handle()` parsea opciones, delega en un service y presenta el resultado.
- Para HTTP, el cliente `Http` de Laravel inyectado como `Factory`.
- Para procesos externos, `Symfony\Component\Process\Process` **siempre con array de
  argumentos**. Nunca `fromShellCommandline`, `shell_exec`, `exec` ni `proc_open`.
- Tipado completo: todo parámetro con tipo, todo método con tipo de retorno, docblocks con
  array shapes (`list<Shot>`, `array{start: float, end: float}`) y nunca `array` pelado.
- No instales paquetes sin preguntar antes.
- No crees archivos que no se te hayan pedido explícitamente.
- **No toques `.env` ni pidas ver su contenido.**

# Tests

PHPUnit (no Pest). `tests/Feature` y `tests/Unit`. La red se simula siempre con `Http::fake()`
y `Http::preventStrayRequests()`. Los ficheros de prueba van a
`storage/app/testing/<algo>-<random_bytes>` y se limpian en `tearDown`.
```

---

# Estado actual del repositorio

**Léelo antes de lanzar cualquier prompt.** Los prompts 1, 2 y 3 ya están aplicados, y se hizo
además una tanda de saneado que no estaba en esta lista. Varios prompts posteriores describían el
código *anterior* a esos cambios; los he corregido, pero si algún prompt te suena a que describe
algo que ya no existe, esta sección manda.

Punto de partida hoy: **`php artisan test` pasa entero, 175 tests en 31 ficheros**, `pint --test`
limpio y `composer validate` conforme. Antes de esta tanda había 6 tests en rojo.

### Lo que ya está hecho y NO hay que repetir

- **`story:doctor`** existe (`App\Console\Commands\DoctorCommand` + `App\Services\Diagnostics\EnvironmentDoctor`),
  con la opción `--warn-only` que informa pero sale con código 0. El script `setup` de Composer lo
  usa con ese flag. `TranscriptTimer` expone `MODEL_HINT` y `modelProblem(): ?string`, y
  `story:narrate` ya imprime ese mensaje bueno en sus dos ramas.
- **`.env.example`** restaurado y documentado. `composer.json` con `name` propio, sin `migrate` ni
  npm en `setup`, y `post-create-project-cmd` reducido a `key:generate` (ya no toca sqlite).
- **El índice de audio está partido en dos**, y este es su contrato:
  - `resources/audio/manifest.json` — versionado, **solo** clips del core kit. Hoy 24 clips, todos
    en disco: 15 CC0 y 9 Attribution.
  - `storage/app/audio/library.json` — ignorado por git, clips locales (ambience, sfx, music y
    cualquier `synth-*`). Hoy **vacío**: los 11 clips fantasma que había se purgaron.
  - `AudioLibrary::add()` escribe siempre en el índice local y fuerza `is_core => false`.
    `AudioLibrary::addCore()` es el **único** que escribe el manifiesto versionado y fuerza
    `is_core => true`; solo lo llama `CoreKitInstaller`. El flag ya no es un dato libre: se deriva
    de en qué índice vive el clip.
  - `clips()` descarta en silencio las entradas cuyo fichero falta, con un warning agregado.
    `allClips()` las incluye. `prune()` limpia el índice local, `missingCoreClips()` lista los
    core que faltan, y `audio:list` tiene columna `En disco` y opción `--prune`.
- **`SoundResolver::indexSynth()`** ya calcula el sha1 antes de copiar, así que no deja WAV
  huérfanos, y la síntesis nunca toca el manifiesto versionado.
- **`tests/TestCase.php`** ya no está vacío: da a cada test su propio índice local de audio bajo
  `storage/app/testing/audio-index/` y lo borra en `tearDown`.
- **ffmpeg 8 eliminó `-filter_complex_script`.** Ya no aparece en ninguna parte del código: lo
  sustituye `App\Services\Ffmpeg\FfmpegFilterScript`, un singleton que detecta la versión del
  binario una sola vez y devuelve `-/filter_complex` (ffmpeg 7+) o `-filter_complex_script` (6 y
  anteriores). `Mixer` y `AmbienceBuilder` lo reciben por constructor. **No vuelvas a escribir
  `-filter_complex_script` a mano en ningún sitio.**

### Deuda conocida que sí sigue abierta

- `TranscriptTimer::isConfigured()` se quedó sin ningún uso en `app/` al pasar los comandos a
  `modelProblem()`. Es código muerto; se limpia en el prompt 21.
- `app/Models/User.php` y `app/Http/Controllers/Controller.php` son los dos únicos ficheros de
  `app/` sin `declare(strict_types=1)`. Importa para el prompt 14: el candado de CI falla mientras
  existan. `tests/TestCase.php` y los dos `ExampleTest.php` tampoco lo llevan.
- `SyntheticSound` genera ruido sin semilla, así que su salida no es reproducible y hay un test
  flaky por ello. Es el prompt 24.

---

# TANDA 1 — Volver a poder ejecutarlo

Cinco correcciones de configuración e higiene. Ninguna toca lógica de negocio.
**Los prompts 1, 2 y 3 ya están aplicados**: se quedan aquí como registro de lo que se hizo, y los
que hay que lanzar de esta tanda son el 4 y el 5.

---

## Prompt 1 — Validar la ruta del modelo de whisper

> ✅ **HECHO.** El `.env` ya apunta a un modelo que existe (141 MiB) y `story:doctor` sale en verde.
> Se añadió después la opción `--warn-only`, que no estaba en el prompt original. Cubierto por
> `tests/Feature/DoctorCommandTest.php` (8 tests). Se conserva aquí solo como registro.

```
Contexto: proyecto Laravel "moviemaker". La clase App\Services\Audio\TranscriptTimer usa
whisper.cpp para escribir storage/app/stories/{slug}/timings.json, que es la entrada obligatoria
de las fases de planos, imágenes, sonido y subtítulos. La ruta del modelo viene de
config('stories.whisper.model'), que resuelve env('WHISPER_MODEL') ?: storage_path('app/whisper/ggml-base.en.bin').

Problema: cuando WHISPER_MODEL tiene un valor inválido (una ruta que no existe), el operador
descubre el fallo tarde y con un mensaje pobre. El fallback de config no se activa porque la
variable sí tiene valor.

Tarea:
1. En TranscriptTimer, refuerza el mensaje de error de isConfigured()/timestamps() para que
   diga: qué ruta se ha intentado usar, si el fichero existe o no, y las dos formas de
   arreglarlo (definir WHISPER_MODEL con la ruta a un ggml-*.bin, o dejarla vacía y colocar el
   modelo en storage/app/whisper/).
2. Añade un comando artisan nuevo `story:doctor` en App\Console\Commands\DoctorCommand que
   compruebe y muestre en una tabla, con estado OK/FALLO y detalle:
   - binarios ffmpeg, ffprobe y el binario de whisper de config (usa `Symfony\Component\Process\ExecutableFinder`)
   - existencia y tamaño del modelo de whisper
   - salud del sidecar de Kokoro (reutiliza TextToSpeech::isAvailable(), inyectando el contrato)
   - presencia no vacía de GEMINI_API_KEY y FREESOUND_TOKEN — muestra solo "definida"/"ausente",
     NUNCA el valor
   - existencia de resources/audio/manifest.json y cuántos de sus clips faltan en disco
   Devuelve FAILURE si algo bloqueante falla.
3. Toda la lógica de comprobación va en un service nuevo App\Services\Diagnostics\EnvironmentDoctor
   que devuelva una lista de checks tipada. El comando solo pinta la tabla.

Restricciones: no toques .env. No instales paquetes. No uses facades.

Criterios de aceptación:
- `php artisan story:doctor` funciona con el modelo ausente y explica exactamente qué falta.
- EnvironmentDoctor no imprime nada: devuelve datos.
- Test en tests/Feature/DoctorCommandTest.php que cubra al menos: modelo ausente → FALLO
  bloqueante, y sidecar caído (Http::fake con respuesta 500) → FALLO.

Verifica al terminar: `php artisan story:doctor` y `php artisan test --filter=Doctor`.
```

---

## Prompt 2 — Restaurar `.env.example` y arreglar `composer setup`

> ✅ **HECHO.** `.env.example` restaurado con sus 54 claves y un comentario por variable propia.
> `setup` quedó en 4 pasos y termina en `story:doctor --warn-only`. `composer.lock` se regeneró
> solo para el `content-hash`. Se conserva aquí solo como registro.

```
Contexto: proyecto Laravel "moviemaker", sin base de datos, sin UI, todo por comandos artisan.

Problemas:
1. `.env.example` está borrado en el worktree (git status muestra "D .env.example") pero sigue
   existiendo en HEAD con 54 claves correctas. Su ausencia rompe el script `setup` de
   composer.json y el hook post-root-package-install, que hacen
   `file_exists('.env') || copy('.env.example', '.env')`.
2. El script `setup` de composer.json ejecuta `php artisan migrate --force` en un proyecto que
   no usa base de datos: .env no tiene ninguna clave DB_*, database/database.sqlite no existe y
   el script no lo crea.
3. El mismo script ejecuta `npm install --ignore-scripts` y `npm run build` para un proyecto sin
   interfaz web (resources/js/app.js tiene 3 bytes).

Tarea:
1. Restaura .env.example desde HEAD (`git checkout -- .env.example`).
2. Revisa .env.example y asegúrate de que documenta con un comentario de una línea cada variable
   propia del proyecto: GEMINI_API_KEY, GEMINI_MODEL, GEMINI_REVIEW_MODEL, IMAGE_PROVIDER,
   WHISPER_BINARY, WHISPER_MODEL, WHISPER_LANGUAGE, WHISPER_DTW, FREESOUND_TOKEN, TTS_BASE_URL,
   TTS_VOICE, TTS_SPEED. Todos los valores de ejemplo deben quedar vacíos o ser placeholders
   obvios, jamás credenciales reales.
3. En composer.json, quita del script `setup` el paso `migrate --force` y los dos pasos de npm.
   Añade en su lugar `@php artisan story:doctor` como último paso, para que el setup termine
   diciendo qué falta.
4. Actualiza también el campo "name" (a algo como "brayan/moviemaker"), "description" y
   "keywords" de composer.json: siguen siendo los del skeleton de Laravel. Quita
   "pestphp/pest-plugin" de allow-plugins: Pest no está instalado ni se usa.

Restricciones: no toques .env. No borres migraciones ni ficheros de la app en este prompt (eso
va en otro). No instales paquetes.

Criterios de aceptación:
- `git status` ya no muestra .env.example borrado.
- Todas las claves que usa el código vía env() aparecen en .env.example.
- El script `setup` no menciona migrate ni npm.

Verifica al terminar: `composer validate` y `git diff --stat`.
```

---

## Prompt 3 — Dejar de versionar el índice de audio

> ✅ **HECHO**, con dos añadidos posteriores que el prompt no pedía: `addCore()` como único
> escritor del manifiesto versionado, y `prune()` + `audio:list --prune`, con el que se purgaron
> los 11 clips fantasma. El contrato final está en «Estado actual del repositorio», arriba.
> Se conserva aquí solo como registro.

```
Contexto: proyecto Laravel "moviemaker". resources/audio/manifest.json es el índice de la
librería de sonidos: lo escribe App\Services\Audio\AudioLibrary::add() y lo leen SoundResolver,
AudioLibrary y el comando audio:list. Los WAV viven en resources/audio/{core,ambience,sfx,music}/.

Problema: .gitignore ignora resources/audio/**/*.wav salvo la carpeta core/, pero manifest.json
sí está versionado. Resultado: se ha comiteado estado mutable de una máquina cuyo contenido está
ignorado. Ahora mismo el manifiesto indexa 35 clips y 11 no existen en disco (7 de ambience/,
3 de sfx/, 1 de music/). Tres de esas entradas son ficheros "synth-*.wav" generados localmente
por SyntheticSound, que nunca debieron entrar al índice compartido.

Efecto del bug: SoundResolver puntúa esas entradas fantasma como candidatos de caché legítimos
y solo las descarta cuando el verificador falla al abrirlas, gastando un ffprobe inútil por clip.

Tarea:
1. Separa el índice en dos ficheros:
   - resources/audio/manifest.json → SOLO los clips del core kit (is_core: true). Versionado.
   - storage/app/audio/library.json → los clips locales (ambience, sfx, music no-core, y
     cualquier synth-*). Ignorado por git.
   AudioLibrary debe leer la unión de ambos y escribir siempre en el segundo.
2. AudioLibrary::add() debe rechazar (o marcar) clips cuyo fichero no exista en disco, y al leer
   debe descartar silenciosamente las entradas cuyo fichero haya desaparecido, registrando un
   único warning agregado con el recuento.
3. SyntheticSound nunca debe indexar sus salidas en el manifiesto versionado. Revisa
   SoundResolver::indexSynth() y asegúrate de que escribe solo en el índice local. Arregla
   también la fuga que hay ahí: si tras copiar el WAV a la librería encuentra un clip existente
   con el mismo sha1, devuelve el existente y deja el fichero recién copiado huérfano.
4. Actualiza .gitignore y ejecuta `git rm --cached` sobre lo que deje de versionarse.
5. Añade al comando audio:list una columna o aviso que marque los clips cuyo fichero falta.

Restricciones: no borres ningún WAV del disco. No toques resources/audio/core/*.wav en este
prompt. No uses facades. AudioLibrary sigue recibiendo Filesystem por constructor.

Criterios de aceptación:
- `php artisan audio:list` no revienta con el manifiesto actual y señala los clips ausentes.
- Un clip nuevo resuelto por SoundResolver aparece en el índice local, no en el versionado.
- Test unitario que cubra: lectura con una entrada cuyo fichero no existe, y escritura de un
  clip nuevo (comprobando en qué fichero acaba).

Verifica al terminar: `php artisan audio:list` y `php artisan test --filter=AudioLibrary`.
```

---

## Prompt 4 — Atribución de licencias CC-BY

```
Contexto: proyecto Laravel "moviemaker", repositorio en GitHub. resources/audio/core/ tiene 24
WAV versionados descargados de Freesound. resources/audio/manifest.json indexa exactamente esos
24 clips (es el índice del core kit, ya separado del índice local de storage/app/audio/library.json):
15 son CC0 y 9 son licencia Attribution con "attribution_required": true. El comando audio:credits
(App\Console\Commands\CreditsAudioCommand) ya genera el bloque de atribución correcto, pero su
salida solo va a stdout: no se guarda en ninguna parte.

Dato que necesitas: AudioLibrary::attributionClips() se alimenta de clips(), que descarta las
entradas cuyo fichero no está en disco. Hoy los 24 están todos, así que no cambia nada, pero si
alguna vez falta un WAV del core ese clip desaparece de la atribución sin avisar.

Problemas:
1. No existe ningún fichero LICENSE, ATTRIBUTION, CREDITS ni NOTICE en el repo. La obligación de
   atribución de esos 9 clips está hoy incumplida.
2. composer.json declara "license": "MIT", heredado del skeleton de Laravel, lo que cubriría
   incorrectamente clips que son CC-BY.
3. App\Services\Audio\SoundResolver::fromCoreKit() fabrica el clip con
   'author' => 'horror-studio', 'license' => 'internal', 'attribution_required' => false, y solo
   lo sustituye si encuentra una entrada con ese mismo 'file' en el manifiesto. Un WAV presente
   en disco pero ausente del índice se declara "internal" aunque sea CC-BY.
   App\Services\Audio\CoreKitInstaller::indexExisting() tiene el MISMO fallo y es peor: escribe
   'license' => 'internal' directamente en el manifiesto VERSIONADO (vía addCore()), así que la
   licencia falsa se comitea.
4. App\Services\Audio\SfxPlacer::place() vuelve a llamar a SoundResolver en tiempo de mezcla
   cuando un cue no tiene override. Ese clip no está en sounds.json, así que
   StoryMixer::cueByFile() no lo encuentra, no entra en usedCues y MixCommand::renderCredits()
   no lo acredita. Un clip Attribution puede acabar en el vídeo sin crédito.

Tarea:
1. Añade al comando audio:credits una opción `--write` que vuelque la atribución a
   ATTRIBUTION.md en la raíz del proyecto, con cabecera explicativa, agrupado por licencia, y
   una línea por clip con autor, URL de origen y licencia. Ese fichero SÍ se versiona.
2. Que App\Console\Commands\MixCommand escriba también
   storage/app/stories/{slug}/credits.txt junto a narration_mix.wav, con la atribución de los
   clips realmente usados en ese vídeo. La generación del texto va en un service reutilizado por
   los dos comandos (crea App\Services\Audio\AttributionWriter), no duplicado.
3. Corrige fromCoreKit() Y CoreKitInstaller::indexExisting(): si el WAV existe en disco pero no
   hay entrada en el índice, NO lo declares "internal". Márcalo como licencia desconocida con
   attribution_required = true y registra un warning. Ante la duda, se acredita.
4. Corrige la pérdida de crédito de SfxPlacer: cualquier clip que acabe en la mezcla debe entrar
   en usedCues. Y cambia cueByFile() para que empareje por ruta completa, no por basename()
   (hoy dos clips con el mismo nombre base en directorios distintos se confunden).
5. Cambia el campo "license" de composer.json: pon "proprietary" o quítalo, y explica en
   ATTRIBUTION.md que el código y los assets tienen licencias distintas.

Restricciones: no descargues nada. No uses facades. No cambies la lógica de resolución más allá
de lo indicado.

Criterios de aceptación:
- `php artisan audio:credits --write` genera ATTRIBUTION.md con los 9 clips Attribution.
- Tras `story:mix` existe credits.txt con los clips de ese vídeo.
- Test que cubra: un clip Attribution resuelto en tiempo de mezcla acaba acreditado.
- Test que cubra: un WAV del core en disco pero ausente del índice se acredita como licencia
  desconocida, no como "internal".

Verifica al terminar: `php artisan audio:credits --write && cat ATTRIBUTION.md` y
`php artisan test --filter=Mix`.
```

---

## Prompt 5 — Reescribir `AGENTS.md` y hacer configurable el idioma

> Antes de lanzarlo, decide tú una cosa: **¿el producto es terror en inglés US con ambientación
> latinoamericana, o terror en español?** Todo el código dice lo primero (`language => 'en'`,
> voz `af_heart`, modelo `ggml-base.en.bin`, `KOKORO_LANG=a`, y un campo `pronunciations` en el
> schema precisamente para términos españoles dentro de narración inglesa). `AGENTS.md` dice lo
> segundo. Sustituye `<DECISIÓN>` en el prompt por la respuesta.

```
Contexto: proyecto Laravel "moviemaker". AGENTS.md es el fichero que leen los agentes de IA que
trabajan en este repo: es su contrato.

Problemas:
1. AGENTS.md dice literalmente: "Estamos en la FASE 1: solo el generador de guiones. Sin base de
   datos, sin colas, sin UI." Es falso: el repo tiene 15 comandos artisan, ~18.500 líneas en app/
   y el pipeline completo hasta el MP4 con subtítulos. El propio documento se contradice tres
   líneas más abajo describiendo el pipeline entero y hablando de "la Fase 3" como algo existente.
2. AGENTS.md dice que el proyecto genera vídeos "en español", pero config/stories.php tiene
   'language' => 'en' (hardcodeado, sin env()), 'accent' => 'neutral_american' y voz af_heart;
   .env tiene WHISPER_LANGUAGE=en y un modelo ggml-base.en.bin (variante English-only);
   tts-service/README.md documenta KOKORO_LANG=a como inglés americano; y
   config/stories.image_style_suffix dice "rural Iberian or Latin American setting".
3. tts-service/README.md conserva rutas de un proyecto anterior: /home/USER/horror-studio/...
4. .gitattributes declara "/.github export-ignore" para un directorio que no existe.

La decisión de producto ya está tomada: <DECISIÓN>

Tarea:
1. Reescribe AGENTS.md:
   - Estado real del proyecto: pipeline completo construido, sin base de datos, sin colas, sin UI.
   - Tabla que mapee cada etapa del pipeline a su comando artisan real, con el artefacto que
     consume y el que escribe. Los 15 comandos son: story:doctor, story:generate, story:validate,
     story:narrate, story:images, story:contactsheet, story:sounds, story:mix, story:render,
     audio:core-kit, audio:seed, audio:fetch, audio:resolve, audio:list, audio:credits. Lee cada
     clase de app/Console/Commands para sacar su firma y descripción reales: varias tienen
     opciones que no están en ninguna documentación (por ejemplo story:doctor --warn-only y
     audio:list --prune).
   - Conserva íntegra la sección del contrato de timings.json: está bien y es útil.
   - Conserva la sección de reglas de código.
   - Añade una sección "Librería de audio" con el contrato de los dos índices: qué va en
     resources/audio/manifest.json (solo core kit, versionado, escrito únicamente por
     AudioLibrary::addCore() desde CoreKitInstaller) y qué va en storage/app/audio/library.json
     (clips locales, ignorado por git, escrito por AudioLibrary::add()). Menciona que clips()
     descarta las entradas sin fichero y que allClips() no.
   - Añade una sección "Idioma y localización" que refleje la decisión de producto y enumere
     TODOS los puntos que hay que cambiar para variarla.
2. Haz configurable el idioma: config/stories.php debe leer
   'language' => env('STORY_LANGUAGE', 'en') y añade también la clave a .env.example (con
   comentario que avise de que cambiarla exige cambiar TTS_VOICE, KOKORO_LANG y el modelo de
   whisper a la variante multilingüe).
3. Corrige las rutas horror-studio de tts-service/README.md y actualiza el requisito de Python
   si procede.
4. Quita la línea muerta de .gitattributes.

Restricciones: no toques .env. No cambies el comportamiento por defecto del pipeline: 'en' sigue
siendo el valor por defecto. No inventes comandos: lee las clases.

Criterios de aceptación:
- AGENTS.md no contiene la palabra "FASE 1" ni ninguna afirmación contradicha por el código.
- Los 15 comandos están documentados con su firma real.
- `php artisan config:show stories.story.language` devuelve 'en' sin STORY_LANGUAGE definida.

Verifica al terminar: `php artisan list | grep -E "story:|audio:"` y contrasta con la tabla.
```

---

# TANDA 2 — Dejar de pagar dos veces

Siete bugs. El primero es el de mayor impacto económico del proyecto.

---

## Prompt 6 — La caché de imágenes nunca acierta

```
Contexto: proyecto Laravel "moviemaker", fase de imágenes. El comando story:images
(App\Console\Commands\GenerateImagesCommand) hace, en este orden:
  1. ShotPlanner::plan() → list<Shot> con order, sceneOrder, start, end, sourceText, framing, motion
  2. ensureVisualBible() → genera la biblia visual con Gemini si falta y la persiste en el JSON del guion
  3. ShotDirector::direct($shots, $story, $bible) → una llamada a Gemini POR ESCENA, temperature 0.7,
     que reemplaza description, subject, threatStage, framing y characterSlugs de cada plano
  4. ShotPromptBuilder::build() → el prompt de cada plano
  5. ImageGenerator::generate($prompt, $seed) → PollinationsGenerator, que cachea en
     storage/app/image-cache/sha1($prompt.$seed).jpg
  6. escribe storage/app/stories/{slug}/shots.json con plannerVersion y una fila por plano
     (order, sceneOrder, start, end, subject, threatStage, framing, motion, description, prompt,
     seed, imagePath, placeholder)

BUG: el paso 3 se ejecuta SIEMPRE, en todas las ejecuciones, con temperatura 0.7, y nunca
rehidrata la dirección ya guardada en shots.json. La description cambia entre ejecuciones → el
prompt cambia → la clave sha1($prompt.$seed) cambia → se regeneran las ~200 imágenes. Con
stories.images.rate_limit_seconds = 6 eso son unos 20 minutos por ejecución, más una llamada a
Gemini por escena, y se pierden las imágenes ya revisadas a mano.

El test tests/Feature/ImageGenerationTest.php no lo detecta porque el doble de Gemini devuelve
siempre la misma description ('Directed hallway fog at dusk').

Tarea:
1. Extrae la lectura y escritura de shots.json a un service nuevo
   App\Services\Image\ShotPlanRepository, con métodos tipados read(string $slug) y
   write(string $slug, ShotPlan $plan). Crea un readonly class App\DataObjects\ShotPlan que
   modele el fichero completo (version, plannerVersion, list<PlannedShot>), y un
   App\DataObjects\PlannedShot que modele la FILA persistida (el Shot actual + prompt, seed,
   imagePath, placeholder). Hoy ese esquema solo existe implícito en un método privado del comando.
2. En story:images, antes de llamar al director: si existe shots.json, su plannerVersion coincide
   con ShotPlanner::VERSION y el plan recién calculado es equivalente al persistido (mismo número
   de planos y mismos order/sceneOrder/start/end dentro de 1 ms), REUTILIZA la dirección guardada
   (description, subject, threatStage, framing, characterSlugs) en lugar de volver a llamar a
   ShotDirector.
3. Añade la opción `--redirect` a story:images para forzar el pase del director. Sin ese flag y
   con un plan equivalente, el comando NO debe hacer ninguna llamada a Gemini para dirigir.
4. Escribe shots.json de forma ATÓMICA (fichero temporal + rename) e INCREMENTALMENTE: tras
   generar cada imagen, persiste el progreso. Hoy si el bucle de imágenes lanza, no se escribe
   nada y las imágenes ya descargadas quedan inalcanzables.
5. Arregla `--only`: hoy las filas NO seleccionadas conservan imagePath/seed antiguos pero se
   escriben con la description y el prompt recién generados, de modo que la mayoría de las filas
   describen una imagen distinta de la que apuntan. Una fila no seleccionada debe conservar TODOS
   sus campos intactos.
6. Elimina el método privado forgetCachedImage() del comando: es código muerto (se llama solo con
   --force, y --force ya usa una semilla nueva para la que no existe fichero) y además duplica la
   fórmula privada de caché de PollinationsGenerator.

Restricciones: no cambies el algoritmo de ShotPlanner. No cambies los prompts de sistema de
ShotDirector ni de VisualBibleGenerator. No uses facades. Mantén las descripciones de consola en
español.

Criterios de aceptación:
- Test en tests/Feature/ImageGenerationTest.php: segunda ejecución con el mismo timings.json y
  shots.json → CERO peticiones a Gemini para dirigir y CERO peticiones a Pollinations. Usa
  Http::fake con un doble de Gemini que devuelva una description DISTINTA en cada llamada, para
  que el test falle de verdad si se vuelve a dirigir.
- Test: `--only=3` conserva intactas las filas 1, 2, 4… (prompt, description, seed, imagePath).
- Test: si el bucle de imágenes lanza a mitad, shots.json contiene las filas ya generadas.
- Test: plannerVersion desfasada en shots.json → se ignora la dirección persistida y se redirige.

Verifica al terminar: `php artisan test --filter=ImageGeneration` y `./vendor/bin/pint --test`.
```

---

## Prompt 7 — El tope de palabras amputa los negativos del prompt

```
Contexto: proyecto Laravel "moviemaker". App\Services\Image\ShotPromptBuilder::build() construye
el prompt de cada imagen concatenando partes con ', ' en este orden:
  descriptores de personaje (si subject es protagonist/threat/both) → descriptor de la etapa de
  amenaza → description del plano → framing → bible.setting → bible.timeOfDay → bible.weather →
  bible.palette → $this->styleSuffix → $this->negatives($bible)
y termina con: return $this->limitWords($this->soften(implode(', ', $parts)));

limitWords() trunca a MAX_WORDS = 75 quedándose con las PRIMERAS 75 palabras.

BUG: las dos últimas partes son las que se truncan primero, y son las críticas.
- styleSuffix (config stories.image_style_suffix) son 20 palabras.
- negatives() aporta 14 palabras de base: "no text, no watermark, no logos, no clear facial
  features, no direct eye contact", más una cláusula por cada bible.avoid.
Solo esos dos bloques son 34 de las 75 palabras. Un plano con figura (2 partes de personaje +
descriptor de amenaza + description + framing + setting + timeOfDay + weather + palette) supera
holgadamente las 100 palabras, y la propia VisualBibleGenerator pide un setting "de 15 a 25
palabras", así que el desbordamiento es el caso normal, no el borde.

Consecuencia: los planos con figura se generan SIN sufijo de estilo completo y SIN ninguna
cláusula negativa. Justamente los planos con más riesgo de sacar caras resueltas se generan sin
"no clear facial features" ni "no direct eye contact".

El test actual no lo detecta porque el fixture usa un setting de 9 palabras y characterSlugs
vacío: 68 palabras, justo por debajo del tope.

Tarea:
1. Reestructura build() para que el sufijo de estilo y los negativos queden EXENTOS del recorte.
   Aplica limitWords solo a la parte descriptiva, con un presupuesto = MAX_WORDS menos el coste
   real (en palabras) del sufijo y de los negativos de esta biblia. Si ese presupuesto quedara
   por debajo de un mínimo razonable, prioriza en este orden: negativos > sufijo de estilo >
   description del plano > descriptores de personaje > setting > resto.
2. Deja MAX_WORDS en config (nueva clave stories.images.max_prompt_words, valor 75) en lugar de
   una constante de clase.
3. Corrige de paso que soften() se aplica dos veces: una dentro de sanitize() por cada parte y
   otra sobre el string completo al final. Aplícalo una sola vez.

Restricciones: no cambies el orden semántico del prompt más allá de lo necesario. No toques
VisualBibleGenerator ni ShotDirector. No uses facades. Nada de números mágicos nuevos.

Criterios de aceptación:
- Test unitario nuevo en tests/Unit/ShotPromptBuilderTest.php con una biblia REALISTA (setting de
  20 palabras, 2 personajes con bodyDescriptor largo, 6 entradas en avoid) y un plano con
  subject='both' y 2 characterSlugs, que afirme que el prompt resultante contiene:
  "no clear facial features", "no direct eye contact", y el final del image_style_suffix.
- Test: la rotación de framingOptions por ($shot->order % count($options)) sigue funcionando.
- Test: un characterSlug que no existe en la biblia se ignora sin lanzar.
- Todos los tests existentes siguen pasando.

Verifica al terminar: `php artisan test --filter=ShotPrompt` y `php artisan test --filter=ImageGeneration`.
```

---

## Prompt 8 — Un fallo de subtítulos tira el render, y tolerancias incoherentes

```
Contexto: proyecto Laravel "moviemaker", fase de render. App\Console\Commands\RenderVideoCommand
ejecuta cuatro pasos (clips → escenas → mudo → máster) y después escribe los subtítulos.

BUG 1 — pérdida de trabajo. En handle(), la llamada a writeSubtitles() está DENTRO del mismo
try/catch que renderClips(), composeScenes(), assembleVideo() y encodeVideo().
SubtitleGenerator::generate() lanza InvalidArgumentException si timings.json no tiene frases.
Consecuencia tras 30 minutos de render correcto: se devuelve FAILURE, updateStoryPayload() nunca
se ejecuta (el video.mp4 existe pero el JSON del guion no lo referencia) y los intermedios no se
borran, porque la limpieza está después del try.

BUG 2 — tolerancias incoherentes. Hay tres tolerancias de duración conviviendo:
  - RenderVideoCommand, en isValidVideo(): acepta un vídeo cacheado con hasta 0.15 s de desvío
  - RenderVideoCommand::SYNC_TOLERANCE y VideoAssembler::SYNC_TOLERANCE: 0.1
  - FinalEncoder: rechaza el máster con más de 0.1 s de desvío
  - SceneComposer: 1/fps = 0.0333
Si silent.mp4 sale con 0.12 s de desvío, assembleVideo() imprime "Vídeo mudo ya válido, se omite"
y FinalEncoder lanza. Cada reejecución repite el mismo bucle: el único paso que podría corregirlo
se salta siempre. Solo se sale con --from=assemble, y nada lo sugiere en el mensaje de error.

BUG 3 — --no-grade sobrescribe el puntero del guion. Con --no-grade la salida es
video-nograde.mp4, pero updateStoryPayload() escribe ese path en payload['video']['mp4']. Un paso
de subida posterior publicaría la versión sin gradar.

Tarea:
1. Saca writeSubtitles() del try crítico. Si falla, avisa con warn y sigue: el render es válido,
   los subtítulos son un sidecar. La limpieza y updateStoryPayload() deben ejecutarse igualmente.
2. Unifica las tolerancias. Define una sola fuente en config (stories.video.sync_tolerance, valor
   0.1) y consúmela desde RenderVideoCommand, VideoAssembler y FinalEncoder. La tolerancia de
   ACEPTACIÓN de un artefacto cacheado debe ser igual o MÁS ESTRICTA que la de verificación,
   nunca más laxa: cambia el 0.15 de isValidVideo() para que derive de la misma constante.
3. Cuando FinalEncoder o VideoAssembler lancen por desfase, el mensaje debe sugerir el comando
   concreto de recuperación: `php artisan story:render {file} --from=assemble`.
4. Con --no-grade, escribe la ruta en una clave distinta (payload['video']['mp4_nograde']) y no
   toques payload['video']['mp4'].
5. Elimina la duplicación de infraestructura de la capa de vídeo: probeDuration(), formatNumber()
   y run() están copiados literalmente en RenderVideoCommand, SceneComposer, VideoAssembler y
   FinalEncoder. Extrae un service App\Services\Ffmpeg\FfmpegRunner (ejecuta Process con array de
   argumentos, prefijo nice, timeout de config, log por LoggerInterface, lanza FfmpegException) y
   un App\Services\Ffmpeg\MediaProbe (duración por ffprobe), e inyéctalos en las cuatro clases.
   El namespace App\Services\Ffmpeg YA EXISTE: contiene FfmpegFilterScript, que es el precedente
   de este tipo de service compartido. Regístralos como singleton en AppServiceProvider igual que
   él. Limita este prompt a las cuatro clases de vídeo: hay 13 clases en total con un run() privado
   copiado y 6 con probeDuration(), pero migrar la capa de audio va en el prompt 21.

Restricciones: NO introduzcas atempo, setpts ni -itsoffset en ninguna parte. La política del
proyecto es que el vídeo no se estira: si la duración no cuadra, se lanza. Process siempre con
array de argumentos. No uses facades. No cambies el grafo de filtros.

Criterios de aceptación:
- Test: timings.json con "sentences": [] → el render termina en SUCCESS, video.mp4 existe,
  payload['video'] está escrito y los intermedios están borrados.
- Test: --no-grade no modifica payload['video']['mp4'].
- Test unitario de MediaProbe y FfmpegRunner con un fichero de fixture.
- tests/Feature/RenderTest.php sigue pasando entero.

Verifica al terminar: `php artisan test --filter=Render` y `./vendor/bin/pint --test`.
```

---

## Prompt 9 — Los efectos entran sin normalizar sobre una suma en 16 bits

```
Contexto: proyecto Laravel "moviemaker", subsistema de audio. La mezcla la construye
App\Services\Audio\StoryMixer con tres tipos de pista: narración (nunca comprimida),
cama de ambiente (App\Services\Audio\AmbienceBuilder) y efectos (App\Services\Audio\SfxPlacer).
App\Services\Audio\Mixer las suma con un filter_complex cargado desde fichero (la opción concreta
la resuelve App\Services\Ffmpeg\FfmpegFilterScript, no la escribas a mano) y sidechaincompress
para el ducking, y App\Services\Audio\MasterProcessor aplica loudnorm en dos pasadas y alimiter.

BUG 1 — los efectos se mezclan sin normalizar. En SfxPlacer::place() el gainDb de cada efecto se
fija a 0.0, y en StorySoundManifest::fromResolved() hay un caso explícito que hace lo mismo para
los cues de tipo sfx. El único techo es SoundVerifier, que acepta cualquier pico entre -35 y
0 dBFS: hasta 30 dB de diferencia entre dos efectos igualmente "válidos", sobre una cama a
-30 LUFS y una narración a -14 LUFS.

BUG 2 — el recorte es irreversible. Mixer escribe la mezcla intermedia en pcm_s16le, y el amix
usa normalize=0 (suma pura). El clipping ocurre ANTES de que loudnorm y alimiter vean nada, y
MasterProcessor::measure() no puede detectarlo porque mide el máster ya limitado a 0.95.
MasterProcessor::fitToDuration() también es s16.

BUG 3 — config muerta. config/stories.php declara siete objetivos que NINGUNA línea del código
lee: audio.mix.narration_lufs, audio.mix.ambience_lufs_min, audio.mix.ambience_lufs_max,
audio.mix.sfx_true_peak_dbtp (-20.0), audio.mix.duck_db_min (6.0), audio.mix.duck_db_max (9.0),
audio.resolve.silent_rms_db. Además Mixer tiene la cadena de sidechain hardcodeada en una
constante, y MixCommand hardcodea -14.0 y -1.0 en su comprobación de calidad en lugar de leer
stories.ffmpeg.loudnorm.I y .TP.

Tarea:
1. Normaliza los efectos: mide el true peak real del clip (ffmpeg ebur128 o volumedetect, ya hay
   código para medir LUFS en LibraryClipProcessor) y aplica
   gainDb = config('stories.audio.mix.sfx_true_peak_dbtp') − truePeak, igual que ya se hace con
   ambiente y música. Hazlo en un único sitio y que tanto StorySoundManifest como SfxPlacer lo
   consuman, para que el gainDb persistido en sounds.json y el usado al mezclar coincidan.
2. Cambia la mezcla intermedia de Mixer y el fitToDuration de MasterProcessor a pcm_f32le, para
   que el limitador del máster pueda hacer su trabajo. El máster final sigue siendo el mismo
   formato de salida que hoy.
3. Haz configurable la cadena de sidechain de Mixer, derivando ratio/threshold de
   audio.mix.duck_db_min/max, o —si eso no es traducible de forma limpia— ELIMINA esas dos claves
   de config y documenta en un comentario los valores reales de la constante. Config que miente
   es peor que config que no existe. Aplica el mismo criterio a las otras claves muertas: o se
   consumen o se borran.
4. MixCommand debe leer los objetivos de config, no hardcodearlos.
5. Unifica los dos umbrales de silencio: SoundVerifier usa -42/-45 dB y
   audio.resolve.silent_rms_db declara -50.0 sin que nadie lo lea. Deja uno solo, en config.

Restricciones: no cambies los objetivos LUFS de la narración ni del máster. No toques el
algoritmo de AmbienceBuilder ni la garantía de duración (mix = NarrationClock + tail). Process
siempre con array de argumentos. No uses facades.

Criterios de aceptación:
- Test de nivel real: mezcla narración + cama + un SFX cuyo pico esté a -0.5 dBFS, y afirma que
  el true peak de la mezcla INTERMEDIA (antes del máster) no supera 0 dBFS. Hoy ese test falla.
- Test: dos efectos con picos muy distintos (-2 dBFS y -30 dBFS) acaban con gainDb tales que sus
  picos posteriores queden dentro de 1 dB entre sí.
- `grep -rn "sfx_true_peak_dbtp\|duck_db_min\|silent_rms_db" app/` devuelve al menos una lectura
  por clave que siga existiendo en config.
- tests/Feature/MixTest.php, MixerTest.php y MasterProcessorTest.php siguen pasando.

Verifica al terminar: `php artisan test --filter=Mix` y `php artisan test --filter=Master`.
```

---

## Prompt 10 — Dos fugas de ficheros temporales de tamaño GB

```
Contexto: proyecto Laravel "moviemaker". Los ficheros temporales viven bajo storage/app/tmp/ y
storage/app/render/.

FUGA 1 — la cama de ambiente. App\Services\Audio\AmbienceBuilder::build() escribe su salida en
storage_path('app/tmp/ambience-beds/<hex>.wav'). Su bloque finally solo borra el directorio de
trabajo de los segmentos, no la salida. Esa salida es el path del AudioTrack devuelto, y
App\Services\Audio\StoryMixer NUNCA la borra tras mezclar — sí borra mix.wav, así que la omisión
no es deliberada. A 48 kHz / 16 bit / estéreo son ~11,5 MB por minuto de máster: unos 230 MB por
ejecución de 20 minutos, acumulándose para siempre. App\Services\Audio\MusicPlacer::fitToWindow()
tiene exactamente el mismo problema con storage/app/tmp/music-beds/ (hoy latente porque
audio.music_enabled es false).
Detalle importante: los tests LIMPIAN esos directorios en tearDown, lo que enmascara el bug en
lugar de detectarlo.

FUGA 2 — el directorio de ensamblado. App\Services\Video\VideoAssembler crea
storage/app/render/assemble-<hex>/ FUERA del árbol del slug, así que la limpieza de
RenderVideoCommand (que borra storage/app/render/{slug}/) nunca lo alcanza. El finally cubre las
excepciones pero no un Ctrl-C, un OOM ni un apagón. Cada huérfano son unos 12 GB en un vídeo de
18 minutos. Y como se borra también cuando se pasa --keep-intermediates, ese flag no cumple lo
que promete: nunca se pueden inspeccionar las escenas con fundido ni el body.mp4.

Tarea:
1. StoryMixer debe borrar la cama de ambiente y la de música después de mezclar, con la misma
   disciplina con la que ya borra mix.wav (finally, no en el camino feliz).
2. Mueve el directorio de ensamblado de VideoAssembler a storage/app/render/{slug}/assemble/ y
   respeta --keep-intermediates: con ese flag, no se borra.
3. Añade un barrido de huérfanos al arrancar story:render y story:mix: borra los directorios de
   storage/app/tmp/{ambience-beds,music-beds,mixer-*,ambience-*,audio-core-*} y
   storage/app/render/assemble-* cuyo mtime tenga más de 24 horas, informando de cuántos MB se han
   liberado. Ponlo en un service App\Services\Storage\TempSweeper, no en los comandos. Los buckets
   que barre deben ser una lista explícita en config, no un glob sobre todo storage/app/tmp.
4. SceneComposer::fitToDuration() crea {path}.fit.mp4 y hace delete+move sin try/finally: si el
   proceso falla, queda un .fit.mp4 parcial. Envuélvelo.
5. Mixer::mix() escribe su script de filtro en storage/app/tmp/mixer-<hex>/ y lo borra en un
   finally, pero un SIGKILL deja el directorio. Inclúyelo en el barrido del punto 3.

Nota: la fuga de WAV huérfanos de SoundResolver::indexSynth() YA ESTÁ ARREGLADA (calcula el sha1
antes de copiar). No la busques.

Restricciones: nada de borrados recursivos sobre rutas construidas a partir de datos externos sin
validar que el path resultante está dentro de storage_path('app'). El barrido nunca debe tocar
storage/app/stories/ ni resources/audio/. No uses facades.

Criterios de aceptación:
- Test que cuente los ficheros de storage/app/tmp/ambience-beds ANTES y DESPUÉS de
  StoryMixer::mix() y afirme que no ha crecido. Quita de ese test la limpieza en tearDown que hoy
  enmascara el bug (o hazla después de la aserción).
- Test: --keep-intermediates conserva el directorio de ensamblado; sin el flag, se borra.
- Test unitario de TempSweeper: no borra nada con mtime reciente, sí con mtime antiguo, y se
  niega a actuar sobre una ruta fuera de storage/app.

Verifica al terminar: `php artisan test --filter=Mix` y `php artisan test --filter=Render`.
```

---

## Prompt 11 — El circuit breaker cuenta los fallos de descarga como éxitos

```
Contexto: proyecto Laravel "moviemaker", resolución de sonidos.
App\Services\Audio\SoundResolver tiene una escalera de fallback: caché local → Freesound (con
QueryLadder de 4 niveles) → core kit → respaldo por tag → síntesis FFmpeg. Tiene presupuestos de
tiempo (resolve_budget_seconds por señal, resolve_total_budget_seconds por historia) y un circuit
breaker en memoria que se abre tras CIRCUIT_FAILURE_THRESHOLD fallos consecutivos del proveedor.

BUG 1 — el breaker no puede abrirse por fallos de descarga.
App\Services\Audio\SoundLibraryImporter::ingest() NUNCA lanza: captura Throwable y devuelve
['status' => 'failed', 'reason' => ...]. Pero SoundResolver::fromDownload() lo llama esperando
excepciones:

    try {
        $result = $this->importer->ingest($sound, $type, $extraTags);
        $this->recordProviderSuccess();          // se ejecuta SIEMPRE
    } catch (Throwable $exception) {
        $this->recordProviderFailure($exception); // código inalcanzable
        ...
        continue;
    }
    $clip = match ($result['status']) {
        'added' => ..., 'skipped' => ...,
        default => null,                          // 'failed' cae aquí, en silencio
    };

Consecuencias: (a) un timeout al bajar el preview RESETEA el contador de fallos consecutivos a
cero, así que el circuito solo puede abrirse por fallos de búsqueda; (b) $result['reason'] no se
registra en ningún sitio, así que un ffprobe que rechaza el clip, un sha1 duplicado y un corte de
red son indistinguibles en los logs; (c) el catch es código muerto.

BUG 2 — los errores deterministas no abren el circuito.
SoundResolver::isProviderFailure() solo considera fallo del proveedor los status >= 500 y el 408.
Un 401 o 403 (token inválido o caducado) o un FREESOUND_TOKEN vacío nunca abren el circuito: cada
señal recorre los 4 niveles de la escalera lanzando peticiones condenadas, con un warning por
cada una, y toda la producción degrada silenciosamente a core kit y síntesis.

BUG 3 — el rate limit se relaja justo cuando el proveedor está mal.
En App\Services\Audio\FreesoundClient::request(), la asignación de $this->lastRequestAt está
dentro del try y después de la petición. Como ->throw() está activo, un 4xx/5xx lanza antes de
llegar ahí, la siguiente petición ve un lastRequestAt viejo y throttle() no espera.

Tarea:
1. En fromDownload(), inspecciona $result['status']: si es 'failed', llama a
   recordProviderFailure() y registra un warning con el 'reason', el id del clip y la query.
   Decide si el catch de Throwable sigue teniendo sentido y, si no, quítalo. Alternativa
   igualmente válida y más limpia: que ingest() propague los fallos de RED y devuelva 'failed'
   solo para los rechazos de validación (clip corrupto, sha1 duplicado, duración fuera de rango).
   Elige una de las dos e implémentala de forma consistente.
2. Trata 401, 403 y token ausente como apertura INMEDIATA y permanente del circuito, con un
   mensaje claro de una sola vez ("Freesound rechaza la autenticación: revisa FREESOUND_TOKEN.
   El resto de la historia se resolverá con el kit local y síntesis."). Son fallos deterministas,
   no transitorios: reintentarlos es puro desperdicio.
3. Mueve la asignación de lastRequestAt a un finally en FreesoundClient::request().
4. FreesoundClient::request() adjunta la cabecera Authorization a CUALQUIER url, y
   downloadPreview() recibe la url tal cual viene del JSON de la API. No envíes la cabecera de
   autorización a hosts que no sean freesound.org o cdn.freesound.org, y valida el esquema de la
   url del preview.
5. Reduce el estado mutable de SoundResolver extrayendo dos services pequeños:
   App\Services\Audio\ResolutionBudget (los relojes de historia y señal, los contadores de
   intentos) y App\Services\Audio\ProviderCircuit (umbral, isProviderFailure, estado abierto).
   SoundResolver es un singleton con 6 campos mutables y 875 líneas; esto es el primer corte
   natural.

Restricciones: la escalera NUNCA debe lanzar hacia arriba — el pipeline no se detiene por audio,
esa propiedad está probada en tests/Feature/SoundResolverTest.php y debe seguir cumpliéndose. No
uses facades. Mantén Illuminate\Support\Sleep para las esperas (es fakeable en tests).

Criterios de aceptación:
- Test nuevo: búsqueda OK pero descarga del preview que falla 3 veces → el circuito se ABRE y las
  señales siguientes no tocan la red. Hoy este test falla.
- Test nuevo: 401 en la primera búsqueda → el circuito se abre de inmediato y no se lanza una
  segunda petición.
- Test nuevo: tras un 500, la siguiente petición respeta rate_limit_seconds (usa Sleep::fake() y
  Sleep::assertSleptTimes()).
- Test: el reason de un ingest fallido aparece en el log.
- test_resolve_never_throws_when_every_source_is_down sigue pasando.

Verifica al terminar: `php artisan test --filter=SoundResolver` y `php artisan test --filter=Freesound`.
```

---

## Prompt 12 — Solapes de subtítulos que la corrección no corrige

```
Contexto: proyecto Laravel "moviemaker". App\Services\Video\SubtitleGenerator lee
storage/app/stories/{slug}/timings.json y escribe un SRT. Constantes relevantes:
MAX_LINE_CHARS 42, MAX_LINES 2, MIN_DURATION 1.2, MAX_DURATION 6.0, GAP 0.08.
applyTimingRules() aplica en orden: mergeShortCues → capMaxDuration → separateGaps → extendMinDuration.

BUG: en separateGaps(), la cantidad a repartir se calcula así:

    $need = self::GAP - max(0.0, $gap);

Con $gap negativo —es decir, con cues que SE SOLAPAN— el max(0.0, ...) lo aplana a cero y $need
vale 0.08: se corrige un hueco, no el solape. Ejemplo real: dos cues que se pisan 0.9 s reciben
un ajuste de 0.04 s por lado y siguen solapados 0.82 s. El SRT sale con dos subtítulos
simultáneos y el reproductor los superpone o parpadea.

Y el caso no es hipotético: end <= start en timings.json es exactamente lo que el propio
SubtitleGenerator parchea al leer las frases (fuerza end = start + 1.2), lo que puede empujar el
final de una frase por delante del inicio de la siguiente.

Fallos relacionados en la misma clase:
- Los cues nunca se ordenan por tiempo: sentences() respeta el orden del array de entrada y
  applyTimingRules asume monotonía en start.
- Las 5 constantes de tiempo/longitud y las dos listas léxicas ARTICLES/CONJUNCTIONS (bilingües
  ES/EN) están hardcodeadas: no se pueden ajustar sin editar código.
- La clase tiene 771 líneas y tres responsabilidades claramente separables: segmentación
  (cuesForSentence, splitForLength, nextCue, splitOnce, bestSplitAfter, bestQualitySplit,
  qualityScore, avoidArticleSplit), reglas de tiempo (allocateTime, mergeShortCues,
  capMaxDuration, separateGaps, extendMinDuration) y formato SRT (render, formatTimestamp, wrap,
  fitsAsCue, keepArticlesWithNouns, fitWord).

Tarea:
1. Corrige separateGaps(): `$need = self::GAP - $gap;` y verifica que el reparto resuelve
   solapes de cualquier magnitud sin invertir ningún cue ni dejar duraciones por debajo del
   mínimo de seguridad.
2. Ordena los cues por start antes de aplicar las reglas de tiempo.
3. Mueve las constantes a config (nueva sección stories.subtitles: max_line_chars, max_lines,
   min_duration, max_duration, gap) y las listas léxicas a resources/lang o a un fichero de datos,
   seleccionadas por stories.story.language.
4. Trocea la clase en tres services con una responsabilidad cada uno —CueSegmenter, CueTimer,
   SrtWriter— y deja SubtitleGenerator como el orquestador delgado que los compone. Los tres
   deben ser testeables por separado, sin FFmpeg.

Restricciones: los subtítulos usan el texto ORIGINAL del guion, nunca la fonética del TTS ni la
transcripción de whisper. Esa propiedad ya está probada y debe seguir cumpliéndose. No uses
facades. Mantén el formato SRT exacto (HH:MM:SS,mmm, numeración desde 1, bloques separados por
línea en blanco, LF sin BOM).

Criterios de aceptación:
- Test que afirme la INVARIANTE GLOBAL: para todo i, cues[i].end + GAP <= cues[i+1].start. Este
  test es el contrato entero de applyTimingRules y hoy no existe.
- Test con el caso concreto que hoy falla: timings.json con
  [{order:1, start:0.0, end:0.0}, {order:2, start:0.3, end:2.0}] → cues sin solape.
- Test: timings.json con las frases desordenadas por start → SRT válido y monótono.
- Test: una palabra de más de 42 caracteres (rama fitWord) y una frase que obliga al bucle de
  desbordamiento de MAX_DURATION.
- Test con texto multibyte real (acentos, ¿, ¡) comprobando que el conteo usa mb_strlen.
- tests/Unit/SubtitleGeneratorTest.php sigue pasando entero.

Verifica al terminar: `php artisan test --filter=Subtitle` y `./vendor/bin/pint --test`.
```

---

# TANDA 3 — Que no vuelva a romperse

Estructura, red de seguridad y limpieza. Se pueden hacer en paralelo con la Tanda 2, pero los
prompts 13 y 14 conviene lanzarlos ANTES de los refactors grandes, y el 24 justo después del 13:
es el único test flaky que queda, y meterlo en un CI recién nacido es la forma más rápida de
acostumbrarse a ignorar el rojo.

---

## Prompt 13 — Guardas de skip y helpers centralizados en los tests

```
Contexto: proyecto Laravel "moviemaker". 175 tests en 31 ficheros (19 Feature, 12 Unit), PHPUnit
puro (no Pest). Hoy pasan todos. La red se simula con Http::fake y Http::preventStrayRequests.

Problema 1: unos 17 de los 31 ficheros invocan ffmpeg o ffprobe a través de
Symfony\Component\Process\Process, y `grep -rn "markTestSkipped|#\[Requires\]|@requires" tests/`
devuelve CERO resultados. En una máquina o un CI sin esos binarios, la suite no salta como skip:
revienta con errores que no explican la causa.

Problema 2: los helpers de fixtures están duplicados en los 31 ficheros: generación de WAV de
prueba con senoides, montaje de una librería de audio temporal, escritura de manifiestos,
construcción de payloads de historia, dobles de respuesta de Gemini/Freesound/Pollinations.
tests/TestCase.php ya NO está vacío: da a cada test su propio índice local de audio bajo
storage/app/testing/audio-index/ y lo borra en tearDown. Ese mecanismo se conserva tal cual;
los helpers nuevos se añaden alrededor, sin tocarlo.

Problema 3: tests/TestCase.php y los dos tests de fábrica tests/Unit/ExampleTest.php y
tests/Feature/ExampleTest.php no llevan declare(strict_types=1), y los dos ExampleTest no prueban
nada del proyecto.

Tarea:
1. En tests/TestCase.php, añade helpers de requisitos: requiresFfmpeg(), requiresFfprobe(),
   requiresBinary(string $name), que hagan markTestSkipped con un mensaje que diga qué binario
   falta y cómo instalarlo (brew install ffmpeg / apt-get install ffmpeg). Úsalos en el setUp de
   los ficheros que los necesitan. Añade también declare(strict_types=1) a tests/TestCase.php y
   borra los dos ExampleTest.php de fábrica.
2. Centraliza en TestCase (o en traits dentro de tests/Support/) los helpers duplicados. Como
   mínimo: makeSineWav(float $seconds, float $freq = 220, int $rate = 48000): string,
   makeSilenceWav(), makeTempStoryDirectory(string $slug): string, makeAudioLibrary(array $clips),
   fakeGeminiJson(array $payload), fakeFreesoundSearch(array $sounds),
   fakePollinationsImage(). Sustituye las copias en los ficheros de test.
3. Refuerza phpunit.xml: añade failOnWarning="true", failOnRisky="true",
   failOnDeprecation="true" y cacheDirectory. NO añadas coverage todavía (requiere xdebug/pcov).
4. Deja constancia en un comentario del bloque <php> de phpunit.xml de por qué está cada <env>
   (FREESOUND_TOKEN de prueba, TTS_BASE_URL local, etc.).

Restricciones: no cambies ninguna aserción existente ni el comportamiento de ningún test. Esto es
refactor de andamiaje. Los ficheros de prueba siguen yendo a storage/app/testing/<algo>-<random>
y limpiándose en tearDown.

Criterios de aceptación:
- `php artisan test` sigue pasando entero, con 173 tests (175 menos los dos ExampleTest).
- Renombrando temporalmente ffmpeg en el PATH, la suite reporta skips con mensaje claro, no
  errores.
- Ningún fichero de test define ya su propia función de generar WAV.
- El aislamiento del índice local de audio de tests/TestCase.php sigue funcionando: tras
  `php artisan test`, `git status` no muestra cambios en resources/audio/manifest.json y
  storage/app/audio/library.json sigue como estaba.

Verifica al terminar: `php artisan test` (recuento antes y después) y `./vendor/bin/pint --test`.
```

---

## Prompt 14 — CI en GitHub Actions

```
Contexto: proyecto Laravel 13 / PHP 8.3 llamado "moviemaker". No existe .github/ (ni ha existido
nunca), ni Dockerfile, ni Makefile, ni scripts de despliegue. Rama única main, sin protección.
Nada impide que entre un commit que rompa los 175 tests.

Requisitos del entorno de tests: PHP 8.3 con las extensiones habituales de Laravel más GD (se usa
imagecreatetruecolor en ContactSheetCommand y en PollinationsGenerator para los placeholders), y
los binarios ffmpeg y ffprobe. No hace falta whisper-cli: ninguna prueba lo invoca. No hace falta
base de datos: el proyecto no usa ninguna. La red externa está enteramente simulada con
Http::fake, así que el CI es determinista.

Dos avisos importantes antes de escribir el workflow:

- El ffmpeg de apt en el runner será una versión distinta a la de mi máquina (yo tengo la 9.0.1,
  el runner traerá una 6.x o 7.x). El proyecto ya lo soporta: App\Services\Ffmpeg\FfmpegFilterScript
  detecta la versión y elige entre -/filter_complex y -filter_complex_script. NO fijes la versión
  de ffmpeg ni añadas PPAs para igualarla: que el CI corra con otra versión es una ventaja, porque
  prueba la otra rama de esa detección.
- app/Models/User.php y app/Http/Controllers/Controller.php son los DOS únicos ficheros de app/
  sin declare(strict_types=1). Son andamiaje de Laravel que el prompt 16 borra. Si el candado del
  punto 2 no los tiene en cuenta, el CI nace en rojo.

Tarea:
1. Crea .github/workflows/ci.yml con un job en ubuntu-latest que haga, en este orden:
   - checkout
   - shivammathur/setup-php@v2 con php-version 8.3, extensiones gd, mbstring, intl, y coverage none
   - sudo apt-get install -y ffmpeg
   - cache de ~/.composer/cache basada en el hash de composer.lock
   - composer install --prefer-dist --no-progress --no-interaction
   - cp .env.example .env && php artisan key:generate
   - ./vendor/bin/pint --test
   - php artisan test
   Dispáralo en push a main y en pull_request. Añade concurrency para cancelar ejecuciones
   obsoletas de la misma rama.
2. Añade un segundo job (o un paso condicional) que falle si algún fichero de app/ pierde
   declare(strict_types=1) o si aparece un uso de Illuminate\Support\Facades dentro de app/.
   La regla de las facades se cumple hoy al 100% (cero coincidencias). La de strict_types tiene
   las dos excepciones de arriba: exclúyelas por nombre, con un comentario que diga que el prompt
   16 las borra y que entonces hay que quitar la exclusión. Impleméntalo como un script pequeño en
   la raíz o como pasos de grep con exit code, no como un paquete nuevo.
3. Añade también un paso que falle si se intenta versionar un fichero binario de más de 5 MB, para
   que no se reintroduzcan los WAV.
4. Crea .github/PULL_REQUEST_TEMPLATE.md breve con un checklist: tests, pint, AGENTS.md
   actualizado si cambia una convención.

Restricciones: no instales paquetes de composer ni de npm nuevos. No añadas pasos de npm ni de
build de frontend: el proyecto no tiene interfaz. No configures despliegue.

Criterios de aceptación:
- El workflow es válido YAML y los nombres de los pasos están en español, como el resto de la
  consola del proyecto.
- El paso de pint usa --test (nunca corrige en CI).
- El guardián de strict_types y facades falla si introduzco a mano un fichero sin
  declare(strict_types=1) en app/, y NO falla con el árbol tal como está hoy.

Verifica al terminar: revisa el YAML con `python3 -c "import yaml,sys; yaml.safe_load(open('.github/workflows/ci.yml'))"`.
```

---

## Prompt 15 — Análisis estático con Larastan

> Este prompt instala un paquete. `AGENTS.md` obliga a preguntar antes: la pregunta es esta, y la
> respuesta es sí — `larastan/larastan` en `require-dev`.

```
Contexto: proyecto Laravel 13 / PHP 8.3 llamado "moviemaker". No hay ninguna herramienta de
análisis estático configurada (no existen phpstan.neon, psalm.xml ni rector.php).

El punto de partida es inusualmente bueno y por eso merece la pena: 1.433 parámetros sin uno solo
sin tipo, cero tipos de retorno faltantes, cero nullables implícitos, 608 docblocks con array
shapes (list<Shot>, array{start: float, end: float}), ni un solo `array` pelado, y una única
supresión en todo el proyecto. Los 167 `mixed` son fronteras legítimas de deserialización
(json_decode de payloads de Gemini, Freesound, whisper y ffprobe) con narrowing explícito.

Tarea:
1. Instala larastan/larastan como dependencia de desarrollo.
2. Crea phpstan.neon con: level 8 (empieza en 8, no en max), paths app/ y config/, y las
   extensiones de Larastan. Excluye tests/ de momento.
3. Ejecuta el análisis y arregla los errores que salgan, con este criterio estricto:
   - Prohibido silenciar con @phpstan-ignore, baseline o @var mentirosos.
   - Prohibido relajar el nivel.
   - Si un error revela un bug real, arréglalo y dilo explícitamente en el resumen final.
   - Si un error viene de un docblock que describe mal la realidad, corrige el docblock.
   - Si un error solo se puede resolver con una guarda de tipo, añádela: es código más seguro,
     no ruido.
4. Un caso concreto que sé que va a saltar: en RenderVideoCommand hay una comparación
   `array_search($step, self::STEPS, true) >= array_search($this->fromStep, self::STEPS, true)`
   entre valores int|false. Resuélvelo con array_flip y un mapa de índices explícito.
5. Añade el paso de phpstan al script `test` de composer.json y al workflow de CI si existe.
6. Si el número de errores iniciales es tan alto que arreglarlos todos en un solo paso es
   inviable, NO generes un baseline: para, informa de cuántos errores hay y agrúpalos por tipo
   para que yo decida el orden.

Restricciones: no cambies el comportamiento de ninguna clase. Este prompt es solo tipado y
guardas. Si un arreglo exigiera cambiar lógica de negocio, párate y dímelo.

Criterios de aceptación:
- `./vendor/bin/phpstan analyse` termina con 0 errores en nivel 8.
- `php artisan test` sigue pasando entero, con el mismo recuento.
- Cero entradas nuevas de @phpstan-ignore y ningún phpstan-baseline.neon.

Verifica al terminar: `./vendor/bin/phpstan analyse --no-progress` y `php artisan test`.
```

---

## Prompt 16 — Purgar el andamiaje web y de base de datos

```
Contexto: proyecto Laravel 13 llamado "moviemaker". Es un toolkit de línea de comandos: sin base
de datos, sin colas, sin interfaz web. Todo se ejecuta con comandos artisan.

Andamiaje muerto que sigue en el repo:
- database/migrations/: las 3 migraciones de fábrica (users, cache, jobs). CACHE_STORE es file y
  QUEUE_CONNECTION no es database, así que ninguna se usa.
- app/Models/User.php: solo se referencia desde database/seeders/DatabaseSeeder.php.
  `grep -rn "auth()|Auth::|Authenticatable"` en todo el proyecto devuelve UNA coincidencia: el
  `extends Authenticatable` de User.php.
- database/factories/UserFactory.php y database/seeders/DatabaseSeeder.php.
- config/auth.php (117 líneas), config/session.php (233), config/mail.php, config/queue.php:
  puro andamiaje.
- routes/web.php: una ruta GET / que devuelve view('welcome').
- resources/views/welcome.blade.php: 72 KB de la landing de Laravel.
- resources/css/app.css, resources/js/app.js (3 bytes), vite.config.js, package.json,
  package-lock.json (sin trackear) → 81 MB de node_modules para 3 bytes de JavaScript.
- app/Http/Controllers/Controller.php: clase abstracta vacía, sin usar. Junto con
  app/Models/User.php son los dos únicos ficheros de app/ sin declare(strict_types=1), así que
  borrarlos deja esa regla al 100%.
- bootstrap/app.php configura withRouting(web: ..., health: '/up') y un withExceptions con
  shouldRenderJsonWhen($request->is('api/*')) — no hay rutas API.
- composer.json: fakerphp/faker solo se usa en UserFactory.

Ya NO hay que hacer: quitar el `touch database/database.sqlite` y el `migrate --graceful` de
post-create-project-cmd. Ese script ya está reducido a `key:generate`.

Tarea:
1. Borra todo lo anterior. Ojo con el orden y con las dependencias cruzadas.
2. Ajusta bootstrap/app.php para que solo registre comandos: withRouting(commands: ...). Decide
   si conservas el endpoint de salud /up (si lo quitas, elimina también la clave del routing).
3. Limpia phpunit.xml: hoy inyecta DB_CONNECTION=sqlite, SESSION_DRIVER=array, CACHE_STORE=array,
   MAIL_MAILER=array. Quita las que dejen de tener sentido y conserva las que el framework
   siga necesitando para arrancar en tests.
4. Limpia .env.example de las claves que dejen de existir (DB_*, SESSION_*, MAIL_*, QUEUE_*,
   REDIS_*, MEMCACHED_*, AWS_*, BROADCAST_*, VITE_*). Deja las que Laravel siga leyendo.
5. Quita fakerphp/faker de composer.json si tras el borrado ya no se usa.
6. Actualiza .gitignore quitando las entradas de frontend que dejen de aplicar, y borra
   package-lock.json sin trackear.

Restricciones: PARA Y PREGUNTA antes de borrar cualquier fichero de config si detectas que alguna
clase de app/ lee una de sus claves vía config(). Ejecuta la suite completa después de cada
borrado significativo, no solo al final. No borres nada de app/Services, app/DataObjects,
app/Console ni app/Contracts.

Criterios de aceptación:
- `php artisan list` sigue mostrando los 15 comandos del proyecto.
- `php artisan test` pasa entero con el mismo recuento.
- `php artisan config:clear && php artisan story:validate --help` funciona.
- No queda ningún fichero de app/ sin declare(strict_types=1), y si el prompt 14 ya está hecho,
  quita del script del candado la exclusión de User.php y Controller.php.

Verifica al terminar: `php artisan test`, `php artisan list` y `grep -rL "declare(strict_types=1)" app/`.
```

---

## Prompt 17 — Sacar los 172 MB de audio de git

```
Contexto: proyecto Laravel "moviemaker". resources/audio/core/ contiene 24 WAV versionados en git
(172 MB; los mayores son rain.wav con 39 MB y urban-distant.wav con 37 MB). Son 24 de los 209
ficheros trackeados y suponen prácticamente todo el peso del repositorio: git count-objects
reporta un pack de ~142 MiB. Sin ellos el repo pesaría ~1,5 MB.

Lo importante: el instalador que los descarga YA EXISTE Y ESTÁ TESTEADO.
App\Services\Audio\CoreKitInstaller (336 líneas) recorre las 24 categorías de
resources/audio/categories.json, busca en Freesound con su curatedQuery, descarga, procesa con
LibraryClipProcessor y verifica con SoundVerifier. Está expuesto como
`php artisan audio:core-kit {--verify} {--force} {--only=}` y cubierto por
tests/Feature/CoreKitCommandTest.php. Y resources/audio/manifest.json guarda el sha1 de cada clip.

El problema de descargar de Freesound bajo demanda es que pierde determinismo: los clips pueden
retirarse o cambiar. La solución que quiero es intermedia: un tarball sellado, versionado fuera de
git, validado contra los sha1 que ya tenemos.

Tarea:
1. Añade a CoreKitInstaller un modo nuevo "instalar desde tarball sellado": descarga un
   core-kit-v1.tar.gz de una URL configurable (nueva clave stories.audio.core_kit.url),
   lo extrae en resources/audio/core/ y valida cada WAV contra el sha1 de manifest.json,
   fallando con un mensaje claro si alguno no coincide. Exponlo como
   `php artisan audio:core-kit --from-archive`.
2. Añade `php artisan audio:core-kit --pack` que haga lo inverso: empaquete los 24 WAV actuales en
   un tar.gz reproducible (orden estable, sin metadatos variables) e imprima su sha256, para que
   yo pueda subirlo como asset de una GitHub Release.
3. Actualiza .gitignore para dejar de versionar resources/audio/core/*.wav y ejecuta
   `git rm --cached` sobre ellos. NO los borres del disco.
4. Documenta en el README (o en un docs/SETUP.md si el README aún no existe) el paso de setup:
   `php artisan audio:core-kit --from-archive` y, como plan B, `php artisan audio:core-kit` para
   reconstruirlo desde Freesound.
5. Escribe en la salida de audio:core-kit --verify un aviso claro cuando falte algún clip del core,
   con el comando exacto para recuperarlo.

Restricciones: no borres ningún WAV del disco. No reescribas el historial de git en este prompt
(el pack histórico de 142 MiB se queda; eso lo decido yo aparte con git-filter-repo). No instales
paquetes: usa PharData o el binario tar vía Process con array de argumentos.

Criterios de aceptación:
- `php artisan audio:core-kit --pack` produce un tar.gz que, extraído en un directorio limpio,
  pasa `audio:core-kit --verify`.
- `git ls-files "*.wav" | wc -l` baja de 28 a 4 (los 3 samples de tts-service y el silence.wav de
  los fixtures).
- Test que cubra la validación de sha1: un WAV manipulado dentro del tarball hace fallar la
  instalación.
- tests/Feature/CoreKitCommandTest.php sigue pasando.

Verifica al terminar: `php artisan audio:core-kit --verify` y `git count-objects -vH`.
```

---

## Prompt 18 — Escribir el README de verdad

```
Contexto: proyecto Laravel 13 / PHP 8.3 llamado "moviemaker": genera vídeos de historias de terror
para YouTube mediante un pipeline de 8 etapas ejecutado con comandos artisan. Sin base de datos,
sin colas, sin interfaz web.

Problema: README.md son 3.700 bytes del skeleton de Laravel sin tocar — logo de Laravel, badges de
laravel/framework, "About Laravel", Laracasts, "Security Vulnerabilities → taylor@laravel.com".
Cero líneas sobre moviemaker. Ninguno de los 15 comandos está documentado, ni los binarios
necesarios, ni cómo levantar el sidecar de TTS.

Tarea: reescribe README.md desde cero. Antes de escribir, LEE estas fuentes para no inventar nada:
- AGENTS.md (contexto, orden del pipeline, contrato de timings.json, reglas de código)
- app/Console/Commands/*.php (las propiedades $signature y $description de las 14 clases)
- config/stories.php (los parámetros ajustables y sus comentarios, que son buenos)
- tts-service/README.md (instalación y arranque del sidecar de Kokoro)
- composer.json (scripts) y .env.example (variables)

Estructura que quiero:
1. Qué es y qué produce, en tres o cuatro frases. Sin marketing.
2. Requisitos del sistema: PHP 8.3+, Composer, ffmpeg y ffprobe, whisper.cpp (whisper-cli) con un
   modelo ggml, Python 3.11+ y espeak-ng para el sidecar de Kokoro. Comandos de instalación para
   macOS (brew) y Debian/Ubuntu (apt).
3. Instalación paso a paso, incluido el sidecar de TTS, el modelo de whisper y el core kit de
   audio.
4. Variables de entorno: tabla con nombre, para qué sirve y si es obligatoria. NUNCA valores
   reales, solo placeholders.
5. El pipeline: los 8 pasos en orden, cada uno con su comando exacto, el artefacto que consume y
   el que escribe. Incluye un ejemplo end-to-end completo, desde story:generate hasta
   story:render, con nombres de fichero verosímiles.
6. Tabla de referencia de los 14+ comandos con sus opciones reales.
7. Qué se ajusta a menudo y dónde: las pausas de TTS, los objetivos LUFS, la duración de planos,
   el sufijo de estilo de imagen. Remite a los comentarios de config/stories.php.
8. Resolución de problemas: sidecar caído, modelo de whisper ausente, Freesound sin token, ffmpeg
   sin drawtext, disco lleno durante el render (menciona el pico real de disco: decenas de GB en
   un vídeo de ~18 minutos).
9. Tests: cómo ejecutarlos y qué binarios necesitan.
10. Nota de licencias: el código y los assets de audio tienen licencias distintas; remite a
    ATTRIBUTION.md.

Restricciones: en español, directo, sin frases de relleno ni tono corporativo. Nada de badges
decorativos. No inventes ningún comando ni ninguna opción: si no está en el código, no está en el
README. No incluyas valores de secretos.

Criterios de aceptación:
- Cada comando del README existe con esa firma exacta en app/Console/Commands/.
- Un desarrollador nuevo puede llegar desde clonar el repo hasta un MP4 siguiendo solo el README.
- El README no menciona Laravel como producto ni enlaza a laravel.com salvo en una línea de
  agradecimiento al final, si acaso.

Verifica al terminar: contrasta la tabla con `php artisan list | grep -E "story:|audio:"`.
```

---

## Prompt 19 — El stub que hace inalcanzable medio planificador

```
Contexto: proyecto Laravel "moviemaker". App\Services\Image\ShotPlanner (936 líneas, VERSION 3)
convierte timings.json en una lista de planos con duración, encuadre, movimiento, sujeto y etapa
de amenaza.

BUG: el método privado beatForWindow() es un stub que ignora sus cuatro parámetros:

    private function beatForWindow(string $text, int $sentenceIndex, int $sentenceCount, ?StoryScene $scene): array
    {
        return ['index' => 0, 'subject' => 'environment', 'threatStage' => null];
    }

Consecuencias en cascada:
- beatIndex es siempre 0, así que la clave de racha es siempre "{sceneOrder}:0" y
  subjectForRun() produce el patrón fijo environment, detail, environment, detail... por escena.
- threatStage es siempre null, lo que convierte en CÓDIGO MUERTO INALCANZABLE las ramas
  threat/hint y reveal de framingPool() y buena parte de nextFraming() (unas 90 líneas).
- stats()['threatStage'] siempre devuelve hint:0, presence:0, reveal:0, y el resumen del comando
  imprime una sección "Amenaza" siempre vacía.

Problema relacionado: App\Services\Image\ShotDirector::applyDirection() PISA el framing que
calculó el planificador con el que devuelve el LLM. Todo el motor anti-repetición del planificador
(nextFraming, framingPool, pickFromPool, ~65 líneas) se descarta al 100%; solo sobrevive motion.
Y nadie revalida el invariante después: el test que comprueba que dos planos consecutivos no
comparten framing prueba el planificador AISLADO, así que en el pipeline real el fichero
shots.json puede acabar con 30 "medium shot" seguidos sin que nada proteste.

Y un tercer problema del mismo tipo: ShotDirector::systemInstruction() promete al LLM que al menos
el 60% de los planos tendrán figura, como máximo el 25% serán detail, nunca habrá dos environment
seguidos y threatStage nunca excederá lo que permite storyProgress. applyDirection() solo valida
pertenencia a enums: ninguna línea de PHP comprueba esas cuatro reglas. stats() ya calcula los
histogramas necesarios y solo se usan para imprimir.

Tarea: quiero que resuelvas la incoherencia, no que la parchees. Analiza el código y elige una de
estas dos direcciones, justificando la elección en tu resumen final:

(A) El planificador NO dirige. Si el ShotDirector es la autoridad sobre subject, threatStage y
    framing, entonces elimina de ShotPlanner el motor de framing y las ramas inalcanzables, deja
    beatForWindow fuera, y documenta en el docblock de la clase que el planificador solo reparte
    tiempo y texto. Sube VERSION a 4.

(B) El planificador SÍ dirige y el director refina. Implementa beatForWindow de verdad (deriva
    subject y threatStage de la posición relativa en la historia y de señales del texto), y que
    ShotDirector RESPETE el framing del planificador salvo que tenga una razón, en lugar de
    pisarlo sistemáticamente.

En cualquiera de los dos casos, haz además esto:
1. Añade una validación POST-dirección que compruebe las cuatro reglas prometidas en el prompt
   (ratio de figura >= 60%, detail <= 25%, no dos environment consecutivos, threatStage acotado
   por storyProgress) usando los histogramas que stats() ya calcula. Que sean warnings visibles
   en el resumen del comando, y checks en StoryValidator.
2. Unifica la comprobación de plannerVersion, que hoy tiene tres semánticas distintas:
   !== en StoryValidator, < en SoundsCommand, e ignorada en RenderVideoCommand. Que sea una sola,
   en un único sitio.
3. El reintento de ShotDirector::directScene() reenvía el MISMO userPrompt en los dos intentos:
   el diagnóstico que mismatchMessage() construye (índices que faltan, sobran o se repiten) se
   descarta. Inclúyelo en el prompt del segundo intento.
4. Elimina el campo de config stories.shots.target_duration, que se asigna en el constructor de
   ShotPlanner y no se lee nunca; o dale uso real.
5. Da nombre a la constante mágica `maxDuration + 3.0` de splitOversizedHold(), que define el
   techo real de duración de plano de todo el proyecto (12 s, no los 9 s que sugiere la config), y
   sácala a config.

Restricciones: NO rompas el invariante de cobertura del teselado (los planos cubren el audio
exactamente, sin huecos ni solapes, con verificación dura de la suma). Es la mejor pieza del
subsistema. Los tests de tests/Unit/ShotPlannerTest.php que lo comprueban deben seguir pasando
sin tocarlos. No uses facades. Si subes ShotPlanner::VERSION, comprueba que todos los
consumidores lo manejan.

Criterios de aceptación:
- `grep -rn "beatForWindow" app/` es coherente con la dirección elegida (no queda un stub).
- Cero ramas inalcanzables en framingPool() y nextFraming().
- Test nuevo que afirme, sobre el shots.json RESULTANTE (después de la dirección, no sobre el
  planificador aislado), que dos planos consecutivos no comparten framing y que se cumplen los
  ratios de figura y detail.
- tests/Unit/ShotPlannerTest.php pasa entero.

Verifica al terminar: `php artisan test --filter=ShotPlanner`, `php artisan test --filter=ImageGeneration`
y `./vendor/bin/pint --test`.
```

---

## Prompt 20 — Contrato tipado en vez del prefijo `placeholder-`

```
Contexto: proyecto Laravel "moviemaker". App\Services\Image\PollinationsGenerator implementa el
contrato App\Contracts\ImageGenerator::generate(string $prompt, int $seed): string. Nunca lanza:
si agota los reintentos, devuelve un JPEG negro de 1280x720 con la semilla estampada, guardado en
storage/app/image-cache/placeholder-<sha1>.jpg.

PROBLEMA: el prefijo "placeholder-" en el nombre de fichero se ha convertido en el canal de
señalización de errores de TODO el pipeline. Lo escribe PollinationsGenerator y lo detectan con
`str_starts_with(basename($path), 'placeholder-')` cinco consumidores distintos:
GenerateImagesCommand (dos veces), ContactSheetCommand, RenderVideoCommand y StoryValidator. Un
detalle de nomenclatura de ficheros de un proveedor concreto es hoy una regla de negocio replicada
en cinco sitios.

Problemas asociados:
1. PollinationsGenerator::isValidImageData() valida con getimagesize(), que solo lee la cabecera:
   un JPEG truncado (por un Ctrl-C o un disco lleno a mitad de escritura, ya que files->put no es
   atómico) pasa el filtro, se sirve como cacheado en la ejecución siguiente y revienta después en
   FFmpeg durante story:render, lejos del origen.
2. RenderVideoCommand::preflight() solo comprueba que el fichero exista y pese más de 0 bytes. Una
   respuesta HTML de error de 300 bytes guardada como .jpg pasa el preflight, pasa la validación
   y revienta en el plano N.
3. shots.json guarda imagePath como ruta ABSOLUTA: mover el proyecto invalida el fichero entero.

Tarea:
1. Cambia el contrato ImageGenerator para que generate() devuelva un readonly class
   App\DataObjects\GeneratedImage con al menos: path, placeholder (bool), seed, attempts y, si el
   fallo fue definitivo, reason. Actualiza PollinationsGenerator y los cinco consumidores para que
   lean el flag en lugar de inspeccionar el nombre del fichero.
2. Haz la escritura de imágenes atómica: escribe en un temporal y renombra.
3. Refuerza la validación de imagen: además de getimagesize(), comprueba el marcador de fin de
   JPEG (0xFFD9) o decodifica de verdad con imagecreatefromstring. Aplícalo tanto al validar la
   respuesta HTTP como al servir un fichero de caché.
4. Refuerza preflight() de RenderVideoCommand con esa misma validación, para que un fichero
   corrupto se detecte ANTES de empezar un render de media hora, con un mensaje que diga qué plano
   y qué comando lo regenera.
5. Guarda imagePath en shots.json como ruta RELATIVA a storage_path('app'), y resuélvela al leer.
   Mantén compatibilidad con los shots.json existentes que tengan rutas absolutas.
6. Elimina los ficheros huérfanos .probe-* de storage/app/image-cache: isValidImageData los borra
   en un finally, pero un SIGKILL los deja ahí y nadie los limpia. Inclúyelos en el barrido de
   temporales si ya existe un TempSweeper.

Restricciones: la propiedad de que generate() NUNCA lanza debe conservarse: el pipeline no se
detiene porque falle una imagen. No cambies la política de semillas ni el rate limiting. No uses
facades.

Criterios de aceptación:
- `grep -rn "placeholder-" app/` solo aparece en PollinationsGenerator (al construir el nombre) y
  en ningún consumidor.
- Test: un JPEG truncado en caché NO se sirve como válido y se regenera.
- Test: preflight falla con un mensaje útil ante un fichero de 300 bytes que no es imagen.
- Test: un shots.json con rutas absolutas antiguas sigue leyéndose.
- tests/Feature/ImageGenerationTest.php y RenderTest.php siguen pasando.

Verifica al terminar: `php artisan test --filter=ImageGeneration` y `php artisan test --filter=Render`.
```

---

## Prompt 21 — Trocear los tres ficheros de más de 800 líneas

> Lánzalo **al final**, cuando ya tengas CI (prompt 14) y Larastan (prompt 15). Sin esa red, un
> refactor de este tamaño es un salto sin cuerda.

```
Contexto: proyecto Laravel "moviemaker". AGENTS.md establece dos reglas que estos tres ficheros
incumplen: "Un archivo, una responsabilidad" y "No metas lógica de negocio en los comandos".

Objetivos, con su diagnóstico:

1. app/Services/Image/ShotPlanner.php — 936 líneas, cinco responsabilidades:
   (a) parseo y normalización de timings.json (sentences, sceneEnds, sentenceWindows, ~110 L)
   (b) heurística de ritmo narrativo (pacingTarget, words, ACTION_VERBS, ~60 L)
   (c) agrupación y partición (groupWindows, mergeShort, splitOversized, packByInternalPauses,
       splitEqual, ~200 L)
   (d) motor de rotación de encuadres (~65 L)
   (e) teselado sobre el reloj de audio (tile de 131 líneas, splitOversizedHold, ~160 L)
   Corte natural: TimingsReader, PacingHeuristic, TimelineTiler. Y todo el interior mueve arrays
   asociativos cuya forma se repite literalmente 12 veces en docblocks: eso es un
   readonly class ShotWindow pidiendo existir.

2. app/Services/Audio/SoundResolver.php — 875 líneas, 10 dependencias, siete responsabilidades y
   SEIS campos de estado mutable en un singleton (storyStartedAt, signalStartedAt, signalAttempts,
   consecutiveProviderFailures, circuitOpen, storyBudgetWarned, synthSequence).
   Corte natural: ResolutionBudget, ProviderCircuit, LibraryRanker (rankLibrary, tagOverlap,
   excluded, isSynthClip), SynthIndexer (fromSynth, indexSynth, synthProfileFor, nextSynthSeed) y
   StorySignals (signalsFor, storyTags, ambienceQuery — que no tienen nada que ver con resolver).
   Quedarían ~250 líneas de escalera legible.
   NOTA: si ya hiciste el prompt 11, ResolutionBudget y ProviderCircuit pueden existir ya.

3. app/Console/Commands/RenderVideoCommand.php — 832 líneas, 27 métodos. Lógica que no le toca:
   groupByScene(), followedByXfade() (regla de negocio de transiciones, DUPLICADA en
   SceneComposer), plan() (el cálculo completo de duraciones), readShots() (parseo del esquema),
   mixPath(), isValidVideo() y probeDuration(). Solo printPlan, printSummary, renderValidation y
   deltaLine son legítimamente del comando.
   Corte natural: RenderPlanner (plan, agrupación, transiciones) y el ShotPlanRepository /
   MediaProbe / FfmpegRunner que ya deberían existir de prompts anteriores.

Tarea: trocea los tres, uno a la vez, en este orden: RenderVideoCommand, SoundResolver,
ShotPlanner. Después de cada uno, ejecuta la suite completa, pint y phpstan antes de seguir.

Método obligatorio:
- Refactor puro: NINGÚN cambio de comportamiento observable. Mismos ficheros de salida, mismos
  mensajes de consola, mismas excepciones con los mismos mensajes.
- Los tests existentes NO se tocan. Si un test hay que modificarlo, es señal de que has cambiado
  comportamiento: para y dímelo.
- Cada clase nueva: final, declare(strict_types=1), dependencias por constructor, docblocks con
  array shapes.
- Introduce los DTOs que hagan desaparecer los array shapes repetidos (ShotWindow al menos).
- Elimina por el camino el código muerto ya identificado: forgetCachedImage() de
  GenerateImagesCommand, LibraryClipProcessor::isSilent() (nunca llamado), el
  `[$shots] = $loaded;` de RenderVideoCommand que descarta dos de los tres valores calculados,
  la comprobación redundante `isRetryableStatus($response->status()) || $response->failed()` de
  PollinationsGenerator, y TranscriptTimer::isConfigured(), que se quedó sin ningún llamador
  cuando los comandos pasaron a usar modelProblem().
- Añade tests unitarios NUEVOS para cada clase extraída. Ese es el objetivo real del troceo:
  ShotClipRenderer::durationFor(), VideoAssembler::assertSync()/clampedFade()/concatFileLine(),
  FinalEncoder::gradeFilter() (snapshot) y GenerateImagesCommand::parseOnly() son todos
  testeables sin FFmpeg y hoy no tienen ni un test.

Restricciones: no cambies ni un parámetro de FFmpeg. No cambies el orden del pipeline. No toques
la garantía de duración (NarrationClock manda, cuatro cerrojos, sin atempo ni setpts). No instales
paquetes. Si en algún punto no puedes extraer algo sin cambiar comportamiento, PARA y explícame el
conflicto en lugar de forzarlo.

Criterios de aceptación:
- Ningún fichero de app/ supera las 400 líneas, salvo que me expliques por qué uno debe superarlas.
- `php artisan test` pasa con MÁS tests que antes y ninguno modificado.
- `./vendor/bin/phpstan analyse` en 0 errores.
- `./vendor/bin/pint --test` limpio.
- Un `git diff --stat` que muestre movimiento de código, no reescritura.

Verifica al terminar: `php artisan test`, `./vendor/bin/phpstan analyse --no-progress`,
`./vendor/bin/pint --test` y `find app -name "*.php" | xargs wc -l | sort -rn | head -15`.
```

---

## Prompt 22 — Mover los JSON Schema fuera del PHP

```
Contexto: proyecto Laravel "moviemaker". Tres métodos devuelven literales de JSON Schema de Gemini
embebidos en PHP, y entre los tres son unas 290 líneas de datos disfrazados de código:
- App\Services\Story\StorySchema::get() — 98 líneas
- App\Services\Image\VisualBibleGenerator::schema() — 116 líneas
- App\Services\Story\StoryReviewer::schema() — 74 líneas

Tarea:
1. Mueve los tres esquemas a resources/schemas/{story,visual-bible,story-review}.json.
2. Crea un service App\Services\Llm\SchemaRepository que los cargue por nombre, los cachee en
   memoria durante la petición y lance una excepción clara si el fichero falta o el JSON es
   inválido. Inyecta Filesystem por constructor; la ruta base entra como parámetro, no con
   resource_path() dentro de la clase.
3. Sustituye los tres usos. Elimina StorySchema si se queda vacía.

Restricciones: los esquemas resultantes deben ser BYTE A BYTE equivalentes en contenido semántico
a los actuales, incluidas TODAS las cadenas de "description" (son ingeniería de prompt cuidada:
"Fill with every Spanish term that appears in the narration...", el ejemplo de Sacamantecas, etc.).
No reescribas ni resumas ninguna descripción. No cambies el orden de las claves si eso pudiera
alterar la salida del modelo.

Criterios de aceptación:
- Test que compare el array devuelto por SchemaRepository con el literal que había antes (déjalo
  como fixture en el test) y afirme igualdad estricta.
- tests/Feature/StoryGeneratorTest.php y ImageGenerationTest.php siguen pasando.
- app/ pierde ~290 líneas.

Verifica al terminar: `php artisan test --filter=Story` y `git diff --stat`.
```

---

## Prompt 23 — Un `PathResolver` para cerrar la última grieta de inyección

```
Contexto: proyecto Laravel "moviemaker". El proyecto cumple al 100% su regla de no usar facades:
`grep -rn "Illuminate\\Support\\Facades" app/` devuelve cero resultados. La única grieta que queda
son los helpers globales de ruta: 31 usos de storage_path() y 3 de resource_path() repartidos en
26 ficheros, que hacen que las rutas no sean inyectables ni sustituibles en test.

Ubicaciones exactas (34 usos; cuéntalos tú antes de empezar para confirmar):
- Servicios, 21 usos: TranscriptTimer (x2), NarrationAssembler (x2), Mixer (x2), AudioLibrary (x2),
  AmbienceBuilder (x2), VideoAssembler, StoryValidator, StoryPromptBuilder, PollinationsGenerator,
  SyntheticSound, StorySoundManifest, StoryMixer, SoundLibraryImporter, SoundCategorizer,
  MusicPlacer, CoreKitInstaller.
- Comandos, 12 usos: RenderVideoCommand (x2), GenerateImagesCommand (x2), ContactSheetCommand (x2),
  ValidateStoryCommand, SoundsCommand, ResolveAudioCommand, NarrateStoryCommand, MixCommand,
  GenerateStoryCommand. No los dejes fuera: si solo migras los servicios, el criterio de
  aceptación no se cumple.
- AppServiceProvider, 1 uso: es el legítimo y se queda.

El patrón siempre es storage_path('app/'.$config->get(...)): el config SÍ está inyectado, solo la
raíz del filesystem es global. Y ya existe un precedente del arreglo correcto en
AppServiceProvider, que construye KokoroTts pasándole cacheDirectory: storage_path(...) como
parámetro nombrado.

Tarea:
1. Crea App\Support\PathResolver (final, readonly) con la raíz de storage y la de resources
   inyectadas como strings, y métodos con nombre de dominio: storiesDirectory(string $slug),
   imageCacheDirectory(), ttsCacheDirectory(), audioLibraryDirectory(), coreKitDirectory(),
   renderDirectory(string $slug), tempDirectory(string $bucket), whisperModelPath().
2. Regístralo en AppServiceProvider como singleton construido con storage_path() y
   resource_path(), que es el único sitio donde esos helpers son legítimos.
3. Sustituye los 33 usos que no están en AppServiceProvider, inyectando PathResolver por
   constructor.
4. Añade a PathResolver una guarda: assertInsideStorage(string $path) que lance si una ruta
   construida cae fuera de la raíz de storage. Úsala en todos los sitios que borran directorios
   recursivamente.
5. Aprovecha para simplificar los tests: los que hoy manipulan storage/app/testing a mano pueden
   inyectar un PathResolver con una raíz temporal.

Restricciones: refactor puro, cero cambios de comportamiento. Las rutas resultantes deben ser
idénticas a las actuales. Los tests existentes no se tocan; si alguno hay que modificarlo, para y
dímelo. No uses facades. No instales paquetes.

Trampa a evitar: tests/TestCase.php aísla cada test sobrescribiendo en el setUp la clave de config
`stories.audio.local_index_path`, DESPUÉS de que la app haya arrancado. AudioLibrary funciona hoy
porque lee esa clave en cada llamada. Si PathResolver resuelve el índice local una sola vez al
construir el singleton, el aislamiento se rompe y los tests empiezan a escribirse encima. Ese path
concreto tiene que seguir leyéndose del config en el momento de usarlo. Y sigue valiendo la regla
de que el manifiesto versionado de resources/audio/ solo lo escribe addCore().

Criterios de aceptación:
- `grep -rn "storage_path(\|resource_path(" app/` devuelve resultados SOLO en
  app/Providers/AppServiceProvider.php.
- `php artisan test` pasa con el mismo recuento.
- Test unitario de PathResolver, incluida assertInsideStorage con una ruta con "../".

Verifica al terminar: `grep -rn "storage_path(\|resource_path(" app/` y `php artisan test`.
```

---

## Prompt 24 — Hacer reproducible el ruido sintetizado

```
Contexto: proyecto Laravel "moviemaker". App\Services\Audio\SyntheticSound genera camas y efectos
con ffmpeg cuando no hay ningún clip de librería que sirva. Cachea por hash, así que un perfil ya
generado se reutiliza; el problema es la primera generación.

Problema: cuatro de los cinco generadores parten del filtro anoisesrc de ffmpeg
(renderWind, renderRoom, renderImpact y renderFriction, líneas 163, 177, 219 y 234) y NINGUNO le
pasa el parámetro seed. anoisesrc sin seed produce una señal distinta en cada invocación. El
parámetro $seed que recibe SyntheticSound solo hace variar los parámetros del filtrado, no el
ruido de partida, así que dos llamadas con el mismo seed dan dos ficheros con distinto contenido.
Solo renderDrone es determinista, porque usa el filtro sine.

Consecuencia medible: tests/Feature/SyntheticSoundTest.php tiene un test flaky
(test_generated_beds_and_effects_are_audible_on_laptop_speakers). Comprueba que el pico de cada
tipo generado supera un umbral de -28.0 dBFS, y algunos tipos salen justo pegados a ese umbral:
pasa lanzado en aislamiento con --filter y falla en la suite completa, o al revés, según el ruido
que le toque. Un test que depende de la suerte es peor que no tener test.

Tarea:
1. Pasa seed a los cuatro anoisesrc, derivado del $seed que ya recibe el generador, de modo que
   generate($type, $duration, $seed) produzca bit a bit el mismo WAV en dos ejecuciones distintas.
   El seed que acepta anoisesrc es un entero; normaliza el valor al rango que admite el filtro.
2. Mide, con el seed ya fijo, el pico real de cada uno de los cinco tipos y déjalo escrito en un
   comentario del test. Si algún tipo se queda por debajo del umbral de audibilidad, corrige la
   ganancia del generador — no bajes el umbral del test.
3. Reescribe la aserción del test flaky para que sea determinista: mismo seed, pico esperado
   conocido, con la tolerancia mínima que haga falta por diferencias de versión de ffmpeg.
4. Añade un test que genere dos veces el mismo tipo con el mismo seed, en rutas distintas, y
   afirme que los dos ficheros tienen el mismo sha1.
5. Refuerza el test que ya existe, test_seed_varies_the_same_profile: hoy hace
   assertNotSame($first, $second) sobre las RUTAS devueltas, que difieren solo porque el seed entra
   en la clave de caché. Pasaría igual aunque el seed no afectase al audio, así que no prueba nada.
   Cámbialo para comparar el sha1 del CONTENIDO de los dos ficheros.

Restricciones: no cambies el umbral -28.0 dBFS para que el test pase; si el audio no llega, el
problema es la ganancia. No toques la clave de caché de forma que invalide en silencio los clips
synth ya indexados sin decírmelo. Los WAV de prueba van a storage/app/testing/. No uses facades.

Criterios de aceptación:
- `php artisan test --filter=SyntheticSound` pasa 10 veces seguidas sin un solo fallo.
- `php artisan test` pasa entero.
- Existe un test que demuestra que la generación es reproducible por seed.

Verifica al terminar: `for i in 1 2 3 4 5 6 7 8 9 10; do php artisan test --filter=SyntheticSound
|| break; done` y `php artisan test`.
```

---

# Resumen de ejecución

`php artisan test` está hoy en verde: 175 tests, 31 ficheros. Ese es el suelo. Si un prompt lo
deja por debajo, no está terminado.

| # | Prompt | Tanda | Depende de | Estado |
|---|--------|-------|------------|--------|
| 0 | Reglas de Cursor | — | — | ✅ hecho |
| — | Saneado previo (ffmpeg 8, `addCore()`, `--prune`, `--warn-only`) | — | — | ✅ hecho |
| 1 | Validar modelo de whisper + `story:doctor` | 1 | corregir `.env` a mano | ✅ hecho |
| 2 | `.env.example` + `composer setup` | 1 | — | ✅ hecho |
| 3 | Dejar de versionar el índice de audio | 1 | — | ✅ hecho |
| 4 | Atribución CC-BY | 1 | 3 | ⬜ siguiente |
| 5 | `AGENTS.md` + idioma configurable | 1 | 1, 2 | ⬜ |
| 6 | Caché de imágenes (el de mayor impacto) | 2 | — | ⬜ |
| 7 | Tope de palabras del prompt | 2 | — | ⬜ |
| 8 | Subtítulos fuera del `try` + tolerancias | 2 | — | ⬜ |
| 9 | Normalizar SFX + mezcla en float | 2 | — | ⬜ |
| 10 | Fugas de temporales | 2 | 8 | ⬜ |
| 11 | Circuit breaker | 2 | — | ⬜ |
| 12 | Solapes de subtítulos | 2 | 8 | ⬜ |
| 13 | Guardas de skip + helpers de test | 3 | — | ⬜ |
| 14 | CI en GitHub Actions | 3 | 2, 13 | ⬜ |
| 15 | Larastan nivel 8 | 3 | 14 | ⬜ |
| 16 | Purgar andamiaje web y BD | 3 | 14 | ⬜ |
| 17 | Sacar los WAV de git | 3 | 3, 4 | ⬜ |
| 18 | README | 3 | 5 | ⬜ |
| 19 | Stub del planificador | 3 | 6, 14 | ⬜ |
| 20 | Contrato tipado vs `placeholder-` | 3 | 6, 14 | ⬜ |
| 21 | Trocear los ficheros de 800+ líneas | 3 | 14, 15 | ⬜ |
| 22 | Schemas a JSON | 3 | 14 | ⬜ |
| 23 | `PathResolver` | 3 | 14, 15 | ⬜ |
| 24 | Ruido sintetizado reproducible | 3 | 13 | ⬜ |

**Ruta más corta a "vuelve a funcionar":** 6 → 7 (1, 2 y 3 ya están).
**Ruta más corta a "no se vuelve a romper":** 13 → 14 → 15, y 24 justo después de 13 para no
meter un test flaky en el CI recién nacido.
