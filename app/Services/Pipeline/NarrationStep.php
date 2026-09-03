<?php

declare(strict_types=1);

namespace App\Services\Pipeline;

use App\Contracts\TextToSpeech;
use App\DataObjects\NarrationSentence;
use App\DataObjects\Story as StoryScript;
use App\Exceptions\TtsUnavailableException;
use App\Models\Story;
use App\Services\Audio\NarrationAssembler;
use App\Services\Audio\SentenceSplitter;
use App\Services\Audio\TranscriptTimer;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use JsonException;
use RuntimeException;
use Throwable;

final class NarrationStep
{
    private readonly string $outputDirectory;

    private readonly string $defaultVoice;

    private readonly float $defaultSpeed;

    private readonly bool $outroEnabled;

    private readonly string $outroText;

    private readonly int $outroOrder;

    private readonly bool $coldOpenEnabled;

    private readonly int $coldOpenOrder;

    private readonly bool $introEnabled;

    private readonly string $introText;

    private readonly int $introOrder;

    public function __construct(
        private TextToSpeech $tts,
        private SentenceSplitter $splitter,
        private NarrationAssembler $assembler,
        private TranscriptTimer $timer,
        private Filesystem $files,
        Repository $config,
    ) {
        $this->outputDirectory = storage_path('app/'.$config->get('stories.output_path'));
        $this->defaultVoice = (string) $config->get('stories.tts.voice');
        $this->defaultSpeed = (float) $config->get('stories.tts.speed');
        $this->outroEnabled = (bool) $config->get('stories.story.outro.enabled');
        $this->outroText = (string) $config->get('stories.story.outro.text');
        $this->outroOrder = (int) $config->get('stories.story.outro.scene_order');
        $this->coldOpenEnabled = (bool) $config->get('stories.story.cold_open.enabled');
        $this->coldOpenOrder = (int) $config->get('stories.story.cold_open.scene_order');
        $this->introEnabled = (bool) $config->get('stories.story.intro.enabled');
        $this->introText = (string) $config->get('stories.story.intro.text');
        $this->introOrder = (int) $config->get('stories.story.intro.scene_order');
    }

    /**
     * @param  (callable(string, int, int, ?string): void)|null  $onProgress
     * @param  array{voice?: string, speed?: float, skip_cache?: bool, skip_timings?: bool, timings_only?: bool}  $options
     * @return array<string, mixed>
     */
    public function run(Story $story, ?callable $onProgress = null, array $options = []): array
    {
        $skipTimings = (bool) ($options['skip_timings'] ?? false);
        $timingsOnly = (bool) ($options['timings_only'] ?? false);
        $storyFile = $this->scriptPath($story->slug);
        $payload = $this->readStoryPayload($storyFile);

        if ($payload === null) {
            return ['ok' => false, 'error' => 'El JSON no contiene un guion de historia.'];
        }

        if ($skipTimings && $timingsOnly) {
            return ['ok' => false, 'error' => 'No combines --skip-timings y --timings-only.'];
        }

        if ($timingsOnly) {
            return $this->alignExistingMaster($storyFile, $payload, $story->slug);
        }

        if (! $this->tts->isAvailable()) {
            return [
                'ok' => false,
                'error' => 'El sidecar de Kokoro no está levantado.',
                'hints' => ['Arráncalo con: '. \App\Services\Tts\KokoroTts::START_COMMAND],
            ];
        }

        if (! $skipTimings) {
            $problem = $this->timer->modelProblem();

            if ($problem !== null) {
                return [
                    'ok' => false,
                    'error' => $problem,
                    'hints' => ['También puedes saltarte la alineación con --skip-timings.'],
                ];
            }
        }

        $script = StoryScript::fromArray($payload);
        $voice = $this->resolveVoice(isset($options['voice']) ? (string) $options['voice'] : null);
        $speed = $this->resolveSpeed($options['speed'] ?? null);
        $skipCache = (bool) ($options['skip_cache'] ?? false);
        $outputDirectory = $this->outputDirectory.DIRECTORY_SEPARATOR.$story->slug;
        $ttsOptions = [
            'voice' => $voice,
            'speed' => $speed,
            'skip_cache' => $skipCache,
        ];

        $sentences = $this->splitSentences($script);

        if ($sentences === []) {
            return ['ok' => false, 'error' => 'El guion no tiene frases que narrar.'];
        }

        $startedAt = microtime(true);
        $cacheHits = 0;
        $alignment = null;

        try {
            $clips = $this->synthesizeSentences($sentences, $ttsOptions, $skipCache, $cacheHits, $onProgress);
            $master = $this->assembler->assemble($story->slug, $clips);

            $timingsPath = null;

            if (! $skipTimings) {
                $aligned = $this->timer->time($story->slug, $master['wav'], $sentences);
                $timingsPath = $outputDirectory.DIRECTORY_SEPARATOR.'timings.json';
                $alignment = $this->timer->alignmentReport($aligned, $master['wav']);
            }

            $this->writeAudioMetadata($storyFile, $payload, $master, $timingsPath, $voice, $speed, count($sentences));
        } catch (TtsUnavailableException $exception) {
            $this->cleanupOutputs($outputDirectory);

            return [
                'ok' => false,
                'error' => 'El sidecar de Kokoro no está levantado.',
                'exception' => $exception,
                'hints' => ['Arráncalo con: '.\App\Services\Tts\KokoroTts::START_COMMAND],
                'blank_line' => true,
            ];
        } catch (Throwable $exception) {
            $this->cleanupOutputs($outputDirectory);

            return [
                'ok' => false,
                'error' => $exception->getMessage(),
                'exception' => $exception,
                'blank_line' => true,
            ];
        }

        $problems = $alignment === null ? [] : $this->timer->alignmentProblems($alignment);

        return [
            'ok' => $problems === [],
            'error' => $problems[0] ?? null,
            'alignment_problems' => $problems,
            'narration_seconds' => $master['duration'],
            'sentence_count' => count($sentences),
            'cache_hits' => $cacheHits,
            'elapsed' => microtime(true) - $startedAt,
            'alignment' => $alignment,
            'voice' => $voice,
            'speed' => $speed,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function alignExistingMaster(string $storyFile, array $payload, string $slug): array
    {
        $problem = $this->timer->modelProblem();

        if ($problem !== null) {
            return ['ok' => false, 'error' => $problem];
        }

        $script = StoryScript::fromArray($payload);
        $outputDirectory = $this->outputDirectory.DIRECTORY_SEPARATOR.$slug;
        $wav = $outputDirectory.DIRECTORY_SEPARATOR.'narration.wav';

        if (! $this->files->isFile($wav)) {
            return ['ok' => false, 'error' => 'No hay narration.wav. Ejecuta story:narrate primero.'];
        }

        $sentences = $this->splitSentences($script);

        if ($sentences === []) {
            return ['ok' => false, 'error' => 'El guion no tiene frases que narrar.'];
        }

        try {
            $aligned = $this->timer->time($slug, $wav, $sentences);
        } catch (Throwable $exception) {
            return ['ok' => false, 'error' => $exception->getMessage(), 'exception' => $exception];
        }

        $timingsPath = $outputDirectory.DIRECTORY_SEPARATOR.'timings.json';
        $audio = is_array($payload['audio'] ?? null) ? $payload['audio'] : [];
        $audio['timings'] = $timingsPath;
        $payload['audio'] = $audio;
        $this->writeStoryPayload($storyFile, $payload);

        $alignment = $this->timer->alignmentReport($aligned, $wav);
        $problems = $this->timer->alignmentProblems($alignment);

        return [
            'ok' => $problems === [],
            'error' => $problems[0] ?? null,
            'alignment_problems' => $problems,
            'timings_only' => true,
            'timings_path' => $timingsPath,
            'alignment' => $alignment,
            'sentence_count' => count($sentences),
        ];
    }

    /**
     * @return list<NarrationSentence>
     */
    private function splitSentences(StoryScript $story): array
    {
        $scenes = $story->scenesForNarrationWithBookends(
            $this->coldOpenEnabled ? $this->coldOpenOrder : null,
            $this->introEnabled ? $this->introText : '',
            $this->introOrder,
            $this->outroEnabled ? $this->outroText : '',
            $this->outroOrder,
        );

        return $this->splitter->splitScenes(
            $scenes,
            static fn (string $sentence): string => $story->textForTts($sentence),
        );
    }

    /**
     * @param  list<NarrationSentence>  $sentences
     * @param  array{voice: string, speed: float, skip_cache: bool}  $options
     * @param  (callable(string, int, int, ?string): void)|null  $onProgress
     * @return list<array{path: string, pauseAfter: float}>
     */
    private function synthesizeSentences(
        array $sentences,
        array $options,
        bool $skipCache,
        int &$cacheHits,
        ?callable $onProgress,
    ): array {
        $total = count($sentences);
        $done = 0;
        $this->progress($onProgress, '', 0, $total);

        $clips = [];

        foreach ($sentences as $sentence) {
            $spoken = $sentence->forTts();

            if (! $skipCache && $this->tts->isCached($spoken, $options)) {
                $cacheHits++;
            }

            $clips[] = [
                'path' => $this->tts->synthesize($spoken, $options),
                'pauseAfter' => $sentence->pauseAfter,
            ];

            $done++;
            $this->progress($onProgress, $sentence->text, $done, $total);
        }

        return $clips;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array{wav: string, mp3: string, duration: float}  $master
     */
    private function writeAudioMetadata(
        string $storyFile,
        array $payload,
        array $master,
        ?string $timingsPath,
        string $voice,
        float $speed,
        int $sentenceCount,
    ): void {
        $payload['audio'] = [
            'wav' => $master['wav'],
            'mp3' => $master['mp3'],
            'timings' => $timingsPath,
            'durationSeconds' => round($master['duration'], 3),
            'sentenceCount' => $sentenceCount,
            'voice' => $voice,
            'speed' => $speed,
        ];

        $this->writeStoryPayload($storyFile, $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function writeStoryPayload(string $storyFile, array $payload): void
    {
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        if ($json === false) {
            throw new RuntimeException('No se pudo serializar el JSON de la historia.');
        }

        $this->files->put($storyFile, $json."\n");
    }

    private function cleanupOutputs(string $directory): void
    {
        foreach (['narration.wav', 'narration.mp3', 'timings.json'] as $name) {
            $path = $directory.DIRECTORY_SEPARATOR.$name;

            if ($this->files->exists($path)) {
                $this->files->delete($path);
            }
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readStoryPayload(string $path): ?array
    {
        try {
            $decoded = json_decode($this->files->get($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! is_array($decoded) || ! isset($decoded['scenes']) || ! is_array($decoded['scenes'])) {
            return null;
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private function resolveVoice(?string $voice): string
    {
        $voice = trim((string) ($voice ?: $this->defaultVoice));

        return $voice !== '' ? $voice : $this->defaultVoice;
    }

    private function resolveSpeed(mixed $speed): float
    {
        if ($speed === null || $speed === '') {
            return $this->defaultSpeed;
        }

        return (float) $speed;
    }

    private function scriptPath(string $slug): string
    {
        return $this->outputDirectory.DIRECTORY_SEPARATOR.$slug.'.json';
    }

    /**
     * @param  (callable(string, int, int, ?string): void)|null  $onProgress
     */
    private function progress(?callable $onProgress, string $label, int $done, int $total): void
    {
        if ($onProgress !== null) {
            $onProgress($label, $done, $total, null);
        }
    }
}
