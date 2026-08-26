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
    private readonly string $binary;

    private readonly string $model;

    private readonly string $language;

    private readonly float $timeout;

    private readonly int $nice;

    private readonly int $threads;

    private readonly int $maxLen;

    private readonly string $dtw;

    private readonly string $storiesDirectory;

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
        $this->storiesDirectory = storage_path('app/'.$config->get('stories.output_path'));
    }

    public function isConfigured(): bool
    {
        return $this->model !== '' && $this->files->isFile($this->model);
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

        if ($this->model === '' || ! $this->files->isFile($this->model)) {
            throw new RuntimeException(
                'No hay un modelo de whisper.cpp. Define WHISPER_MODEL con la ruta a un ggml-*.bin.',
            );
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
     * @param  list<array{start: float, end: float, text: string}>  $segments
     * @param  list<NarrationSentence|array{order?: int, sceneOrder?: int, text: string, pauseAfter?: float}|string>  $sentences
     * @return list<array{order: int, sceneOrder: int, text: string, start: float, end: float, pauseAfter: float, alignment: 'text'|'sequential'}>
     */
    public function alignToSentences(array $segments, array $sentences): array
    {
        $words = $this->wordsFromSegments($segments);
        $wordCount = count($words);
        $cursor = 0;
        $lastEnd = 0.0;
        $aligned = [];

        foreach ($sentences as $index => $sentence) {
            $fields = $this->sentenceFields($index, $sentence);
            $expected = $this->tokens($fields['text']);
            $match = $this->matchExpected($words, $cursor, $expected);

            if ($match !== null) {
                $start = $words[$match['from']]['start'];
                $end = $words[$match['to']]['end'];
                $cursor = $match['to'] + 1;
                $alignment = 'text';
            } else {
                $take = min(max(count($expected), 1), max($wordCount - $cursor, 0));

                if ($take === 0) {
                    $start = $lastEnd;
                    $end = $lastEnd + max(0.25, count($expected) * 0.35);
                } else {
                    $from = $cursor;
                    $to = $cursor + $take - 1;
                    $start = $words[$from]['start'];
                    $end = $words[$to]['end'];
                    $cursor = $to + 1;
                }

                $alignment = 'sequential';
            }

            if ($end < $start) {
                [$start, $end] = [$end, $start];
            }

            $lastEnd = $end;
            $aligned[] = [
                'order' => $fields['order'],
                'sceneOrder' => $fields['sceneOrder'],
                'text' => $fields['text'],
                'start' => $this->seconds($start),
                'end' => $this->seconds($end),
                'pauseAfter' => $fields['pauseAfter'],
                'alignment' => $alignment,
            ];
        }

        return $aligned;
    }

    /**
     * Transcribe, alinea y escribe timings.json para la Fase 3.
     *
     * @param  list<NarrationSentence|array{order?: int, sceneOrder?: int, text: string, pauseAfter?: float}|string>  $sentences
     * @return list<array{order: int, sceneOrder: int, text: string, start: float, end: float, pauseAfter: float, alignment: 'text'|'sequential'}>
     */
    public function time(string $slug, string $audioPath, array $sentences): array
    {
        $aligned = $this->alignToSentences($this->timestamps($audioPath), $sentences);
        $this->save($slug, $aligned);

        return $aligned;
    }

    /**
     * @param  list<array{order: int, sceneOrder: int, text: string, start: float, end: float, pauseAfter: float, alignment: string}>  $sentences
     */
    public function save(string $slug, array $sentences): string
    {
        $slug = $this->assertSlug($slug);
        $directory = $this->storiesDirectory.DIRECTORY_SEPARATOR.$slug;
        $this->files->ensureDirectoryExists($directory);

        $path = $directory.DIRECTORY_SEPARATOR.'timings.json';
        $json = json_encode(
            [
                'version' => 1,
                'sentences' => $sentences,
                'scenes' => $this->sceneWindows($sentences),
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

            $text = trim((string) ($token['text'] ?? ''));

            if ($text === '' || $this->isSpecialToken($text)) {
                continue;
            }

            $start = $this->nodeSeconds($token, 'from');
            $end = $this->nodeSeconds($token, 'to');

            if ($start === null || $end === null) {
                return [];
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
     * @param  list<array{start: float, end: float, token: string}>  $words
     * @param  list<string>  $expected
     * @return array{from: int, to: int}|null
     */
    private function matchExpected(array $words, int $cursor, array $expected): ?array
    {
        $wordCount = count($words);
        $expectedCount = count($expected);

        if ($expectedCount === 0 || $cursor >= $wordCount) {
            return null;
        }

        $searchLimit = min($wordCount, $cursor + 4);

        for ($start = $cursor; $start < $searchLimit; $start++) {
            if ($words[$start]['token'] !== $expected[0]) {
                continue;
            }

            $matched = 1;
            $end = $start;
            $skips = 0;

            for ($index = $start + 1; $index < $wordCount && $matched < $expectedCount; $index++) {
                if ($words[$index]['token'] === $expected[$matched]) {
                    $matched++;
                    $end = $index;
                    $skips = 0;

                    continue;
                }

                $skips++;

                if ($skips > $expectedCount) {
                    break;
                }
            }

            if ($matched === $expectedCount) {
                return ['from' => $start, 'to' => $end];
            }
        }

        return null;
    }

    /**
     * @param  list<array{order: int, sceneOrder: int, text: string, start: float, end: float, pauseAfter: float, alignment: string}>  $sentences
     * @return list<array{order: int, start: float, end: float, duration: float, sentenceCount: int}>
     */
    private function sceneWindows(array $sentences): array
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
     * @return array{order: int, sceneOrder: int, text: string, pauseAfter: float}
     */
    private function sentenceFields(int $index, mixed $sentence): array
    {
        if ($sentence instanceof NarrationSentence) {
            return [
                'order' => $sentence->order,
                'sceneOrder' => $sentence->sceneOrder,
                'text' => $sentence->text,
                'pauseAfter' => $sentence->pauseAfter,
            ];
        }

        if (is_string($sentence)) {
            return [
                'order' => $index + 1,
                'sceneOrder' => 1,
                'text' => $sentence,
                'pauseAfter' => 0.0,
            ];
        }

        if (! is_array($sentence)) {
            return [
                'order' => $index + 1,
                'sceneOrder' => 1,
                'text' => '',
                'pauseAfter' => 0.0,
            ];
        }

        return [
            'order' => (int) ($sentence['order'] ?? $index + 1),
            'sceneOrder' => (int) ($sentence['sceneOrder'] ?? 1),
            'text' => (string) ($sentence['text'] ?? ''),
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
