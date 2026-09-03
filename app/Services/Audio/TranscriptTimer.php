<?php

declare(strict_types=1);

namespace App\Services\Audio;

use App\DataObjects\NarrationSentence;
use App\Exceptions\WhisperException;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use InvalidArgumentException;
use JsonException;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Process\Process;

final class TranscriptTimer
{
    public const MODEL_HINT = 'Arréglalo de una de estas dos formas: define WHISPER_MODEL con la ruta absoluta '
        .'a un ggml-*.bin, o déjala vacía y coloca el modelo en storage/app/whisper/ggml-base.en.bin.';

    /**
     * Esquema de timings.json. La 2 añade ttsText junto al texto del guion. La 3 añade words a las
     * frases ancladas por texto, para poder colgar un efecto de la palabra que lo nombra.
     */
    private const TIMINGS_VERSION = 3;

    /** Palabras de whisper que se miran como mucho para anclar una frase corta. */
    private const MIN_ANCHOR_WINDOW = 8;

    /**
     * Fracción de tokens de la frase que tienen que caer en orden para dar el ancla por buena, y
     * fracción de la ventana anclada que tienen que ser tokens de esa frase: sin lo segundo, un
     * match parcial se estira sobre las palabras de las frases siguientes.
     */
    private const MIN_MATCH_RATIO = 0.6;

    /**
     * Tokens de cabecera que se pueden saltar para encontrar el ancla. Una frase que empieza por
     * un nombre fonético («eh-LEH-nah») no casa nunca por su primer token, porque whisper oye el
     * nombre real; anclarla por lo que viene después es mejor que dejarla sin anclar.
     */
    private const MAX_LEADING_SKIP = 3;

    /**
     * Tokens esperados que se pueden saltar de golpe cuando whisper no escribe ninguno de ellos.
     * La fonética reescribe un nombre en hasta cuatro tokens («PEH-nyahs BLAHN-kahs») donde
     * whisper oye dos palabras, y un nombre español sin fonética se le angliza («Tomás» sale
     * «Thomas»): sin poder saltar el token que no aparece, el emparejador se queda clavado en él
     * y pierde la frase entera aunque el resto encaje palabra por palabra.
     */
    private const MAX_EXPECTED_SKIP = 4;

    /** Longitud mínima del prefijo común para aceptar dos tokens como el mismo. */
    private const FUZZY_PREFIX_LENGTH = 4;

    /**
     * Holgura al comprobar que timings.json cabe en el máster. Solo tiene que absorber el redondeo a
     * milisegundos de seconds(), que puede subir un valor medio milisegundo.
     */
    private const MASTER_TOLERANCE = 0.001;

    /** Desde esta longitud se admite una letra de diferencia entre tokens. */
    private const FUZZY_DISTANCE_LENGTH = 5;

    private readonly string $binary;

    private readonly string $model;

    private readonly string $language;

    private readonly float $timeout;

    private readonly int $nice;

    private readonly int $threads;

    private readonly int $maxLen;

    private readonly string $dtw;

    private readonly string $storiesDirectory;

    private readonly float $minTextRatio;

    private readonly float $maxUncoveredSeconds;

    public function __construct(
        private Filesystem $files,
        private LoggerInterface $logger,
        private NarrationClock $clock,
        Repository $config,
    ) {
        $this->binary = (string) $config->get('stories.whisper.binary');
        $this->model = (string) $config->get('stories.whisper.model');
        $language = (string) $config->get('stories.whisper.language');
        $this->language = $language !== '' ? $language : (string) $config->get('stories.story.language');
        $this->timeout = (float) $config->get('stories.whisper.timeout');
        $this->nice = (int) $config->get('stories.whisper.nice');
        $this->threads = (int) $config->get('stories.whisper.threads');
        $this->maxLen = (int) $config->get('stories.whisper.max_len');
        $this->dtw = (string) $config->get('stories.whisper.dtw');
        $this->minTextRatio = (float) $config->get('stories.whisper.alignment.min_text_ratio');
        $this->maxUncoveredSeconds = (float) $config->get('stories.whisper.alignment.max_uncovered_seconds');
        $this->storiesDirectory = storage_path('app/'.$config->get('stories.output_path'));
    }

    public function isConfigured(): bool
    {
        return $this->modelProblem() === null;
    }

    /**
     * Describe por qué el modelo no sirve, o null si está en su sitio.
     */
    public function modelProblem(): ?string
    {
        if ($this->model === '') {
            return 'La ruta del modelo de whisper.cpp está vacía. '.self::MODEL_HINT;
        }

        if ($this->files->isFile($this->model)) {
            return null;
        }

        return sprintf(
            'El modelo de whisper.cpp no existe en %s (%s). %s',
            $this->model,
            $this->files->exists($this->model) ? 'la ruta existe pero no es un fichero' : 'la ruta no existe',
            self::MODEL_HINT,
        );
    }

    /**
     * Transcribe el máster con whisper.cpp y devuelve palabras con su ventana real.
     *
     * @return list<array{start: float, end: float, text: string}>
     */
    public function timestamps(string $audioPath): array
    {
        if (! $this->files->isFile($audioPath)) {
            throw new InvalidArgumentException('No existe el audio '.$audioPath.'.');
        }

        $problem = $this->modelProblem();

        if ($problem !== null) {
            throw new RuntimeException('No se puede transcribir sin modelo de whisper.cpp. '.$problem);
        }

        $workDir = $this->makeWorkDirectory();

        try {
            $prefix = $workDir.DIRECTORY_SEPARATOR.'whisper';
            $this->run($this->whisperCommand($audioPath, $prefix));

            $jsonPath = $prefix.'.json';

            if (! $this->files->isFile($jsonPath)) {
                throw new RuntimeException('whisper.cpp no escribió el JSON de salida.');
            }

            $segments = $this->parseWhisperJson($this->files->get($jsonPath));
            $this->warnIfWhisperDisagreesWithWav($audioPath, $segments);

            return $segments;
        } finally {
            $this->files->deleteDirectory($workDir);
        }
    }

    /**
     * Empareja cada frase original con su ventana en el audio. Nunca lanza por desajuste.
     *
     * Va en dos pasadas: primero se anclan por texto las frases que casan con la transcripción y
     * después se reparten las que no, proporcionalmente a su recuento de palabras, dentro del hueco
     * que dejan sus dos anclas. Así un fallo de anclaje se queda encerrado en su hueco en vez de
     * desplazar todo lo que viene detrás.
     *
     * @param  list<array{start: float, end: float, text: string}>  $segments
     * @param  list<NarrationSentence|array{order?: int, sceneOrder?: int, text: string, ttsText?: string, pauseAfter?: float}|string>  $sentences
     * @return list<array{order: int, sceneOrder: int, text: string, ttsText: string, start: float, end: float, pauseAfter: float, alignment: 'text'|'sequential', words: list<array{token: string, start: float, end: float}>}>
     */
    public function alignToSentences(array $segments, array $sentences, ?float $narrationEnd = null): array
    {
        $words = $this->wordsFromSegments($segments);
        $rows = [];
        $cursor = 0;
        $pending = 0;

        foreach ($sentences as $index => $sentence) {
            $fields = $this->sentenceFields($index, $sentence);
            $expected = $this->tokens($fields['ttsText']);
            $match = $this->matchExpected($words, $cursor, $expected, $pending);

            // La fonética reescribe un nombre en varios tokens que whisper no escribe nunca
            // («sahn-tee-YAH-nah» donde oye «Santillana»), y bestAnchor no sabe saltarse un token
            // esperado que no aparece: cuenta el fallo sin avanzar la posición, se atasca en él y
            // pierde la frase entera. Medido sobre una historia real, fallaban las 16 frases con
            // fonética y ninguna sin ella. El texto del guion sí se parece a lo que whisper oye,
            // así que cuando la fonética no ancla se reintenta con la ortografía original.
            if ($match === null && $fields['ttsText'] !== $fields['text']) {
                $fromScript = $this->tokens($fields['text']);
                $match = $this->matchExpected($words, $cursor, $fromScript, $pending);

                if ($match !== null) {
                    $expected = $fromScript;
                }
            }

            $row = [
                'order' => $fields['order'],
                'sceneOrder' => $fields['sceneOrder'],
                'text' => $fields['text'],
                'ttsText' => $fields['ttsText'],
                'start' => 0.0,
                'end' => 0.0,
                'pauseAfter' => $fields['pauseAfter'],
                'alignment' => 'sequential',
                'words' => [],
                'weight' => max(count($expected), 1),
            ];

            if ($match !== null) {
                $row['start'] = min($words[$match['from']]['start'], $words[$match['to']]['end']);
                $row['end'] = max($words[$match['from']]['start'], $words[$match['to']]['end']);
                $row['alignment'] = 'text';
                $row['words'] = array_slice($words, $match['from'], $match['to'] - $match['from'] + 1);
                $cursor = $match['to'] + 1;
                $pending = 0;
            } else {
                // La frase que no ancla no mueve el cursor: si lo empujara a estimación se comería
                // palabras que son de la frase siguiente. Lo que crece es la ventana de búsqueda,
                // por las palabras que esa frase debería haber consumido, para que el habla siga
                // al alcance de las que vienen detrás.
                $pending += count($expected);
            }

            $rows[] = $row;
        }

        return $this->interpolateHoles(
            $rows,
            $this->speechLimit($words, $rows, $narrationEnd),
            $narrationEnd,
        );
    }

    /**
     * Hasta dónde puede llegar el habla. Con el máster delante se descuenta la pausa final, que es
     * silencio pedido al ensamblar y no habla; sin él, lo último que transcribió whisper.
     *
     * @param  list<array{start: float, end: float, token: string}>  $words
     * @param  list<array{pauseAfter: float}>  $rows
     */
    private function speechLimit(array $words, array $rows, ?float $narrationEnd): float
    {
        if ($narrationEnd !== null) {
            $lastPause = $rows === [] ? 0.0 : $rows[array_key_last($rows)]['pauseAfter'];

            return max(0.0, $narrationEnd - $lastPause);
        }

        if ($words === []) {
            return 0.0;
        }

        return $words[array_key_last($words)]['end'];
    }

    /**
     * Reparte las frases sin ancla dentro del hueco entre las dos que sí la tienen, y encierra a
     * todas, ancladas incluidas, dentro del máster.
     *
     * @param  list<array{order: int, sceneOrder: int, text: string, ttsText: string, start: float, end: float, pauseAfter: float, alignment: 'text'|'sequential', words: list<array{start: float, end: float, token: string}>, weight: int}>  $rows
     * @return list<array{order: int, sceneOrder: int, text: string, ttsText: string, start: float, end: float, pauseAfter: float, alignment: 'text'|'sequential', words: list<array{token: string, start: float, end: float}>}>
     */
    private function interpolateHoles(array $rows, float $limit, ?float $masterEnd = null): array
    {
        $count = count($rows);
        $previousEnd = 0.0;
        $index = 0;

        while ($index < $count) {
            if ($rows[$index]['alignment'] === 'text') {
                $rows[$index]['start'] = max($rows[$index]['start'], $previousEnd);
                $rows[$index]['end'] = max($rows[$index]['end'], $rows[$index]['start']);
                $previousEnd = $rows[$index]['end'];
                $index++;

                continue;
            }

            $last = $index;

            while ($last + 1 < $count && $rows[$last + 1]['alignment'] !== 'text') {
                $last++;
            }

            $nextStart = $last + 1 < $count ? $rows[$last + 1]['start'] : $limit;
            $span = max(0.0, $nextStart - $previousEnd);
            $weight = 0;

            for ($position = $index; $position <= $last; $position++) {
                $weight += $rows[$position]['weight'];
            }

            $cursor = $previousEnd;

            for ($position = $index; $position <= $last; $position++) {
                $rows[$position]['start'] = $cursor;
                $rows[$position]['end'] = $position === $last
                    ? $previousEnd + $span
                    : $cursor + ($weight > 0 ? $span * $rows[$position]['weight'] / $weight : 0.0);
                $cursor = $rows[$position]['end'];
            }

            $previousEnd = $cursor;
            $index = $last + 1;
        }

        $aligned = [];

        foreach ($rows as $row) {
            unset($row['weight']);
            $row['start'] = $this->seconds($this->withinMaster($row['start'], $masterEnd));
            $row['end'] = $this->seconds($this->withinMaster(max($row['end'], $row['start']), $masterEnd));
            $row['words'] = $this->clampWords($row['words'], $row['start'], $row['end']);
            $aligned[] = $row;
        }

        return $aligned;
    }

    /**
     * Nada de lo que se escriba puede caer más allá del final del WAV. NarrationClock fija la línea
     * de tiempo y whisper solo orienta: en audios largos sigue colocando palabras después de que el
     * fichero se haya terminado, y ese tiempo inventado no existe para ninguna etapa posterior.
     */
    private function withinMaster(float $value, ?float $masterEnd): float
    {
        return $masterEnd === null ? $value : min($value, $masterEnd);
    }

    /**
     * Encierra las palabras en la ventana definitiva de su frase. La ventana se recorta al ajustarla
     * contra la frase anterior, y una palabra que sobresaliera dejaría un timings.json que se
     * contradice consigo mismo.
     *
     * @param  list<array{start: float, end: float, token: string}>  $words
     * @return list<array{token: string, start: float, end: float}>
     */
    private function clampWords(array $words, float $start, float $end): array
    {
        $clamped = [];

        foreach ($words as $word) {
            $from = $this->seconds(min(max($word['start'], $start), $end));
            $to = $this->seconds(min(max($word['end'], $from), $end));
            $clamped[] = [
                'token' => $word['token'],
                'start' => $from,
                'end' => $to,
            ];
        }

        return $clamped;
    }

    /**
     * Transcribe, alinea y escribe timings.json para la Fase 3.
     *
     * @param  list<NarrationSentence|array{order?: int, sceneOrder?: int, text: string, ttsText?: string, pauseAfter?: float}|string>  $sentences
     * @return list<array{order: int, sceneOrder: int, text: string, ttsText: string, start: float, end: float, pauseAfter: float, alignment: 'text'|'sequential', words: list<array{token: string, start: float, end: float}>}>
     */
    public function time(string $slug, string $audioPath, array $sentences): array
    {
        $narrationEnd = $this->clock->narrationEnd($audioPath);
        $aligned = $this->alignToSentences(
            $this->timestamps($audioPath),
            $sentences,
            $narrationEnd,
        );
        $this->save($slug, $aligned, $narrationEnd);

        foreach ($this->alignmentProblems($this->alignmentReport($aligned, $audioPath)) as $problem) {
            $this->logger->warning($problem);
        }

        return $aligned;
    }

    /**
     * Calidad de la alineación: cuántas frases anclaron por texto y cuánto máster se queda fuera
     * del habla. Sirve para el informe de story:narrate y para auditar un timings.json ya escrito,
     * porque las frases de las dos fuentes tienen la misma forma.
     *
     * @param  list<array{start?: float, end?: float, alignment?: string}>  $sentences
     * @return array{sentences: int, textAligned: int, sequential: int, textRatio: float, speechEnd: float, narrationEnd: float, uncovered: float}
     */
    public function alignmentReport(array $sentences, string $audioPath): array
    {
        $count = count($sentences);
        $textAligned = 0;
        $speechEnd = 0.0;

        foreach ($sentences as $sentence) {
            if (($sentence['alignment'] ?? '') === 'text') {
                $textAligned++;
            }

            $speechEnd = max($speechEnd, (float) ($sentence['end'] ?? 0));
        }

        $narrationEnd = $this->clock->narrationEnd($audioPath);

        return [
            'sentences' => $count,
            'textAligned' => $textAligned,
            'sequential' => $count - $textAligned,
            'textRatio' => $count > 0 ? round($textAligned / $count, 4) : 0.0,
            'speechEnd' => $this->seconds($speechEnd),
            'narrationEnd' => $this->seconds($narrationEnd),
            'uncovered' => $this->seconds(max(0.0, $narrationEnd - $speechEnd)),
        ];
    }

    /**
     * @param  array{sentences: int, textAligned: int, sequential: int, textRatio: float, speechEnd: float, narrationEnd: float, uncovered: float}  $report
     * @return list<string>
     */
    public function alignmentProblems(array $report): array
    {
        if ($report['sentences'] === 0) {
            return [];
        }

        $problems = [];

        if ($report['textRatio'] < $this->minTextRatio) {
            $problems[] = sprintf(
                'Solo %d de %d frases anclaron por texto (%.0f%%, mínimo %.0f%%): la alineación se ha ido por posición.',
                $report['textAligned'],
                $report['sentences'],
                $report['textRatio'] * 100,
                $this->minTextRatio * 100,
            );
        }

        if ($report['uncovered'] > $this->maxUncoveredSeconds) {
            $problems[] = sprintf(
                'El habla alineada acaba en %.3f s y el máster dura %.3f s: %.3f s sin cubrir (máximo %.1f s).',
                $report['speechEnd'],
                $report['narrationEnd'],
                $report['uncovered'],
                $this->maxUncoveredSeconds,
            );
        }

        return $problems;
    }

    /**
     * @param  list<array{order: int, sceneOrder: int, text: string, start: float, end: float, pauseAfter: float, alignment: string, words?: list<array{token: string, start: float, end: float}>}>  $sentences
     * @param  float|null  $masterEnd  Duración del máster según NarrationClock, para comprobar que lo escrito cabe en él.
     */
    public function save(string $slug, array $sentences, ?float $masterEnd = null): string
    {
        $slug = $this->assertSlug($slug);
        $directory = $this->storiesDirectory.DIRECTORY_SEPARATOR.$slug;
        $this->files->ensureDirectoryExists($directory);

        $scenes = $this->sceneWindows($sentences, $masterEnd);

        if ($masterEnd !== null) {
            $this->assertWithinMaster($sentences, $scenes, $masterEnd);
        }

        $path = $directory.DIRECTORY_SEPARATOR.'timings.json';
        $json = json_encode(
            [
                'version' => self::TIMINGS_VERSION,
                'sentences' => $sentences,
                'scenes' => $scenes,
            ],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE,
        );

        if ($json === false) {
            throw new RuntimeException('No se pudo serializar timings.json.');
        }

        $this->files->put($path, $json."\n");

        return $path;
    }

    /**
     * timings.json no puede reclamar tiempo que el máster no tiene. NarrationClock fija la línea de
     * tiempo y whisper solo orienta, así que un end por encima del WAV es un dato imposible. Se para
     * aquí, nombrando qué se sale y por cuánto, en vez de dejar que el desfase viaje cuatro etapas y
     * aparezca al renderizar como un plano de cierre que no cubre su propio audio.
     *
     * @param  list<array{order: int, start: float, end: float, words?: list<array{token: string, start: float, end: float}>}>  $sentences
     * @param  list<array{order: int, start: float, end: float}>  $scenes
     */
    private function assertWithinMaster(array $sentences, array $scenes, float $masterEnd): void
    {
        $limit = $masterEnd + self::MASTER_TOLERANCE;

        foreach ($sentences as $sentence) {
            foreach ($sentence['words'] ?? [] as $word) {
                if ($word['end'] > $limit) {
                    throw new RuntimeException(sprintf(
                        'La palabra «%s» de la frase %d acaba en %.3f s y el máster dura %.3f s: %.3f s de más.',
                        $word['token'],
                        $sentence['order'],
                        $word['end'],
                        $masterEnd,
                        $word['end'] - $masterEnd,
                    ));
                }
            }

            if ($sentence['end'] > $limit) {
                throw new RuntimeException(sprintf(
                    'La frase %d acaba en %.3f s y el máster dura %.3f s: %.3f s de más.',
                    $sentence['order'],
                    $sentence['end'],
                    $masterEnd,
                    $sentence['end'] - $masterEnd,
                ));
            }
        }

        foreach ($scenes as $scene) {
            if ($scene['end'] > $limit) {
                throw new RuntimeException(sprintf(
                    'La escena %d acaba en %.3f s y el máster dura %.3f s: %.3f s de más.',
                    $scene['order'],
                    $scene['end'],
                    $masterEnd,
                    $scene['end'] - $masterEnd,
                ));
            }
        }
    }

    /**
     * @return list<string>
     */
    private function whisperCommand(string $audioPath, string $prefix): array
    {
        $command = [
            $this->binary,
            '--model', $this->model,
            '--file', $audioPath,
            '--language', $this->language,
            '--output-json',
            '--output-json-full',
            '--output-file', $prefix,
            '--split-on-word',
            '--max-len', (string) $this->maxLen,
            '--threads', (string) $this->threads,
            '--suppress-nst',
        ];

        if ($this->dtw !== '') {
            $command[] = '--dtw';
            $command[] = $this->dtw;
        }

        return $command;
    }

    /**
     * @return list<array{start: float, end: float, text: string}>
     */
    private function parseWhisperJson(string $json): array
    {
        try {
            /** @var array{transcription?: list<array<string, mixed>>} $payload */
            $payload = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('whisper.cpp devolvió JSON inválido.', previous: $exception);
        }

        $segments = [];

        foreach ($payload['transcription'] ?? [] as $item) {
            if (! is_array($item)) {
                continue;
            }

            $fromTokens = $this->segmentsFromTokens($item);

            if ($fromTokens !== []) {
                foreach ($fromTokens as $segment) {
                    $segments[] = $segment;
                }

                continue;
            }

            $text = trim((string) ($item['text'] ?? ''));
            $start = $this->nodeSeconds($item, 'from');
            $end = $this->nodeSeconds($item, 'to');

            if ($text === '' || $start === null || $end === null) {
                continue;
            }

            $segments[] = [
                'start' => $this->seconds($start),
                'end' => $this->seconds($end),
                'text' => $text,
            ];
        }

        return $segments;
    }

    /**
     * whisper.cpp no devuelve palabras, devuelve tokens BPE: «wedged» llega como «wed» + «ged» y
     * «hand,» como «hand» + «,». El token que no empieza por espacio continúa la palabra anterior,
     * así que se pegan y la ventana de la palabra es la unión de las de sus trozos. Sin esto, el
     * alineador compara palabras del guion contra fragmentos y nunca ancla.
     *
     * @param  array<string, mixed>  $item
     * @return list<array{start: float, end: float, text: string}>
     */
    private function segmentsFromTokens(array $item): array
    {
        $tokens = $item['tokens'] ?? null;

        if (! is_array($tokens) || $tokens === []) {
            return [];
        }

        $segments = [];

        foreach ($tokens as $token) {
            if (! is_array($token)) {
                continue;
            }

            $raw = (string) ($token['text'] ?? '');
            $text = trim($raw);

            if ($text === '' || $this->isSpecialToken($text)) {
                continue;
            }

            $start = $this->nodeSeconds($token, 'from');
            $end = $this->nodeSeconds($token, 'to');

            if ($start === null || $end === null) {
                return [];
            }

            if ($segments !== [] && ! str_starts_with($raw, ' ')) {
                $last = array_key_last($segments);
                $segments[$last]['text'] .= $text;
                $segments[$last]['end'] = max($segments[$last]['end'], $this->seconds($end));

                continue;
            }

            $segments[] = [
                'start' => $this->seconds($start),
                'end' => $this->seconds($end),
                'text' => $text,
            ];
        }

        return $segments;
    }

    /**
     * @param  list<array{start: float, end: float, text: string}>  $segments
     * @return list<array{start: float, end: float, token: string}>
     */
    private function wordsFromSegments(array $segments): array
    {
        $words = [];

        foreach ($segments as $segment) {
            $tokens = $this->tokens((string) ($segment['text'] ?? ''));

            if ($tokens === []) {
                continue;
            }

            $start = (float) ($segment['start'] ?? 0);
            $end = (float) ($segment['end'] ?? $start);
            $count = count($tokens);
            $span = max($end - $start, 0.0);

            foreach ($tokens as $index => $token) {
                $from = $count === 1 ? $start : $start + ($span * $index / $count);
                $to = $count === 1 ? $end : $start + ($span * ($index + 1) / $count);
                $words[] = [
                    'start' => $from,
                    'end' => $to,
                    'token' => $token,
                ];
            }
        }

        return $words;
    }

    /**
     * Ancla la frase en la transcripción. La ventana de búsqueda crece con la frase y con $slack,
     * las palabras que dejaron sin consumir las frases anteriores que no anclaron. Los tokens se
     * comparan con holgura y basta con que caiga en orden MIN_MATCH_RATIO de la frase: whisper
     * parte palabras, se come artículos y escribe los números en cifra.
     *
     * @param  list<array{start: float, end: float, token: string}>  $words
     * @param  list<string>  $expected
     * @return array{from: int, to: int}|null
     */
    private function matchExpected(array $words, int $cursor, array $expected, int $slack = 0): ?array
    {
        $expectedCount = count($expected);

        if ($expectedCount === 0 || $cursor >= count($words)) {
            return null;
        }

        $skipLimit = min(self::MAX_LEADING_SKIP, $expectedCount - 1);

        for ($skip = 0; $skip <= $skipLimit; $skip++) {
            $anchor = $this->bestAnchor($words, $cursor, $expected, $skip, $slack);

            if ($anchor !== null) {
                return $anchor;
            }
        }

        return null;
    }

    /**
     * Mejor ancla que empieza en $expected[$skip]. La fracción se mide siempre sobre la frase
     * completa: saltar tokens de cabecera sirve para encontrar el sitio, no para bajar el listón.
     *
     * @param  list<array{start: float, end: float, token: string}>  $words
     * @param  list<string>  $expected
     * @return array{from: int, to: int}|null
     */
    private function bestAnchor(array $words, int $cursor, array $expected, int $skip, int $slack): ?array
    {
        $wordCount = count($words);
        $expectedCount = count($expected);
        $searchLimit = min($wordCount, $cursor + max(self::MIN_ANCHOR_WINDOW, $expectedCount * 2) + $slack);
        $best = null;

        for ($start = $cursor; $start < $searchLimit; $start++) {
            if (! $this->tokensMatch($words[$start]['token'], $expected[$skip])) {
                continue;
            }

            $matched = 1;
            $position = $skip + 1;
            $end = $start;
            $misses = 0;

            for ($index = $start + 1; $index < $wordCount && $position < $expectedCount; $index++) {
                $landed = $this->nextExpected($words[$index]['token'], $expected, $position, $expectedCount);

                if ($landed !== null) {
                    $matched++;
                    $position = $landed + 1;
                    $end = $index;
                    $misses = 0;

                    continue;
                }

                $misses++;

                if ($misses > $expectedCount) {
                    break;
                }
            }

            $ratio = $matched / $expectedCount;
            $density = $matched / ($end - $start + 1);

            if ($ratio < self::MIN_MATCH_RATIO || $density < self::MIN_MATCH_RATIO) {
                continue;
            }

            if ($best === null || $ratio > $best['ratio']) {
                $best = ['from' => $start, 'to' => $end, 'ratio' => $ratio];
            }

            if ($position === $expectedCount) {
                break;
            }
        }

        if ($best === null) {
            return null;
        }

        return ['from' => $best['from'], 'to' => $best['to']];
    }

    /**
     * Posición del token esperado con el que casa esta palabra: la de $position si casa con ella,
     * o la del primer token dentro de los MAX_EXPECTED_SKIP siguientes. Los saltados no cuentan
     * como acertados, así que la fracción de MIN_MATCH_RATIO sigue midiéndose sobre la frase
     * entera y saltar no sirve para colar un ancla mala.
     *
     * @param  list<string>  $expected
     */
    private function nextExpected(string $token, array $expected, int $position, int $expectedCount): ?int
    {
        $limit = min($expectedCount, $position + self::MAX_EXPECTED_SKIP + 1);

        for ($candidate = $position; $candidate < $limit; $candidate++) {
            if ($this->tokensMatch($token, $expected[$candidate])) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Dos tokens son el mismo si coinciden, si uno es prefijo del otro con FUZZY_PREFIX_LENGTH
     * caracteres o si a partir de FUZZY_DISTANCE_LENGTH se diferencian en una letra.
     */
    private function tokensMatch(string $left, string $right): bool
    {
        if ($left === $right) {
            return true;
        }

        if ($left === '' || $right === '') {
            return false;
        }

        $leftLength = mb_strlen($left);
        $rightLength = mb_strlen($right);
        $shortest = min($leftLength, $rightLength);

        if ($shortest >= self::FUZZY_PREFIX_LENGTH) {
            if ($leftLength <= $rightLength ? str_starts_with($right, $left) : str_starts_with($left, $right)) {
                return true;
            }
        }

        if ($shortest < self::FUZZY_DISTANCE_LENGTH || abs($leftLength - $rightLength) > 1) {
            return false;
        }

        return levenshtein($left, $right) <= 1;
    }

    /**
     * El final de la última escena es habla más la pausa pedida al ensamblar, y esa pausa es una
     * petición, no una medida: sumada a un end que ya roza el final del WAV se sale del máster.
     *
     * @param  list<array{order: int, sceneOrder: int, text: string, start: float, end: float, pauseAfter: float, alignment: string}>  $sentences
     * @return list<array{order: int, start: float, end: float, duration: float, sentenceCount: int}>
     */
    private function sceneWindows(array $sentences, ?float $masterEnd = null): array
    {
        $groups = [];

        foreach ($sentences as $sentence) {
            $groups[$sentence['sceneOrder']][] = $sentence;
        }

        ksort($groups);

        $orders = array_keys($groups);
        $scenes = [];

        foreach ($orders as $index => $order) {
            $group = $groups[$order];
            $first = $group[0];
            $last = $group[array_key_last($group)];
            $nextOrder = $orders[$index + 1] ?? null;
            $start = $first['start'];
            $end = $nextOrder === null
                ? $last['end'] + $last['pauseAfter']
                : $groups[$nextOrder][0]['start'];

            if ($end < $start) {
                $end = $last['end'] + $last['pauseAfter'];
            }

            $end = max($this->withinMaster($end, $masterEnd), $start);

            $scenes[] = [
                'order' => $order,
                'start' => $this->seconds($start),
                'end' => $this->seconds($end),
                'duration' => $this->seconds($end - $start),
                'sentenceCount' => count($group),
            ];
        }

        return $scenes;
    }

    /**
     * @return array{order: int, sceneOrder: int, text: string, ttsText: string, pauseAfter: float}
     */
    private function sentenceFields(int $index, mixed $sentence): array
    {
        if ($sentence instanceof NarrationSentence) {
            return [
                'order' => $sentence->order,
                'sceneOrder' => $sentence->sceneOrder,
                'text' => $sentence->text,
                'ttsText' => $sentence->forTts(),
                'pauseAfter' => $sentence->pauseAfter,
            ];
        }

        if (is_string($sentence)) {
            return [
                'order' => $index + 1,
                'sceneOrder' => 1,
                'text' => $sentence,
                'ttsText' => $sentence,
                'pauseAfter' => 0.0,
            ];
        }

        if (! is_array($sentence)) {
            return [
                'order' => $index + 1,
                'sceneOrder' => 1,
                'text' => '',
                'ttsText' => '',
                'pauseAfter' => 0.0,
            ];
        }

        $text = (string) ($sentence['text'] ?? '');
        $ttsText = trim((string) ($sentence['ttsText'] ?? ''));

        return [
            'order' => (int) ($sentence['order'] ?? $index + 1),
            'sceneOrder' => (int) ($sentence['sceneOrder'] ?? 1),
            'text' => $text,
            'ttsText' => $ttsText !== '' ? $ttsText : $text,
            'pauseAfter' => (float) ($sentence['pauseAfter'] ?? 0),
        ];
    }

    /**
     * @return list<string>
     */
    private function tokens(string $text): array
    {
        $normalized = $this->normalize($text);

        if ($normalized === '') {
            return [];
        }

        return explode(' ', $normalized);
    }

    private function normalize(string $text): string
    {
        $text = mb_strtolower($text);
        $text = str_replace(["'", '’', '‘'], '', $text);
        $text = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function nodeSeconds(array $node, string $bound): ?float
    {
        $offsets = $node['offsets'] ?? null;

        if (is_array($offsets) && isset($offsets[$bound]) && is_numeric($offsets[$bound])) {
            return ((int) $offsets[$bound]) / 1000;
        }

        $timestamps = $node['timestamps'] ?? null;

        if (is_array($timestamps) && isset($timestamps[$bound]) && is_string($timestamps[$bound])) {
            return $this->parseClock($timestamps[$bound]);
        }

        return null;
    }

    private function parseClock(string $stamp): float
    {
        $stamp = str_replace(',', '.', $stamp);

        if (! preg_match('/^(?:(\d+):)?(\d+):(\d+(?:\.\d+)?)$/', $stamp, $matches)) {
            return 0.0;
        }

        $hours = (int) ($matches[1] ?? 0);
        $minutes = (int) $matches[2];
        $seconds = (float) $matches[3];

        return ($hours * 3600) + ($minutes * 60) + $seconds;
    }

    private function isSpecialToken(string $text): bool
    {
        return str_starts_with($text, '[') || str_starts_with($text, '<|');
    }

    private function seconds(float $value): float
    {
        return round($value, 3);
    }

    /**
     * @param  list<array{start: float, end: float, text: string}>  $segments
     */
    private function warnIfWhisperDisagreesWithWav(string $audioPath, array $segments): void
    {
        $whisperEnd = 0.0;

        foreach ($segments as $segment) {
            $whisperEnd = max($whisperEnd, (float) ($segment['end'] ?? 0));
        }

        $narrationEnd = $this->clock->narrationEnd($audioPath);

        if (abs($narrationEnd - $whisperEnd) <= 1.0) {
            return;
        }

        $this->logger->warning(sprintf(
            'Whisper y el WAV de narración no coinciden: último end %.3f s, %s dura %.3f s.',
            $whisperEnd,
            basename($audioPath),
            $narrationEnd,
        ));
    }

    /**
     * @param  list<string>  $arguments
     */
    private function run(array $arguments): Process
    {
        $process = new Process(['nice', '-n', (string) $this->nice, ...$arguments]);
        $process->setTimeout($this->timeout);
        $process->run();

        $this->logger->info('Salida de whisper.cpp.', [
            'command' => $process->getCommandLine(),
            'exit_code' => $process->getExitCode(),
            'stderr' => $process->getErrorOutput(),
        ]);

        if (! $process->isSuccessful()) {
            throw WhisperException::fromProcess($process);
        }

        return $process;
    }

    private function makeWorkDirectory(): string
    {
        $directory = storage_path('app/tmp/whisper-'.bin2hex(random_bytes(8)));
        $this->files->ensureDirectoryExists($directory);

        return $directory;
    }

    private function assertSlug(string $slug): string
    {
        $slug = trim($slug);

        if ($slug === '' || basename($slug) !== $slug) {
            throw new InvalidArgumentException('El slug de la historia no es válido.');
        }

        return $slug;
    }
}
