<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Contracts\TextToSpeech;
use App\DataObjects\NarrationSentence;
use App\DataObjects\Story;
use App\Exceptions\TtsUnavailableException;
use App\Services\Audio\NarrationAssembler;
use App\Services\Audio\SentenceSplitter;
use App\Services\Audio\TranscriptTimer;
use App\Services\Tts\KokoroTts;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use JsonException;
use RuntimeException;
use Throwable;

final class NarrateStoryCommand extends Command
{
    protected $signature = 'story:narrate
        {file : Ruta al JSON del guion generado en la Fase 1}
        {--voice= : Voz de Kokoro}
        {--speed= : Velocidad de habla}
        {--no-cache : Ignora la caché de WAV}
        {--skip-timings : No genera timings.json}
        {--timings-only : Alinea un máster existente y escribe timings.json}';

    protected $description = 'Sintetiza la narración de un guion JSON y escribe el máster de audio';

    private readonly string $outputDirectory;

    private readonly string $defaultVoice;

    private readonly float $defaultSpeed;

    public function __construct(
        private TextToSpeech $tts,
        private SentenceSplitter $splitter,
        private NarrationAssembler $assembler,
        private TranscriptTimer $timer,
        private Filesystem $files,
        Repository $config,
    ) {
        parent::__construct();

        $this->outputDirectory = storage_path('app/'.$config->get('stories.output_path'));
        $this->defaultVoice = (string) $config->get('stories.tts.voice');
        $this->defaultSpeed = (float) $config->get('stories.tts.speed');
    }

    public function handle(): int
    {
        $storyFile = $this->resolveStoryFile((string) $this->argument('file'));

        if ($storyFile === null) {
            return self::FAILURE;
        }

        $payload = $this->readStoryPayload($storyFile);

        if ($payload === null) {
            return self::FAILURE;
        }

        $skipTimings = (bool) $this->option('skip-timings');
        $timingsOnly = (bool) $this->option('timings-only');

        if ($skipTimings && $timingsOnly) {
            $this->error('No combines --skip-timings y --timings-only.');

            return self::FAILURE;
        }

        if ($timingsOnly) {
            return $this->alignExistingMaster($storyFile, $payload);
        }

        if (! $this->tts->isAvailable()) {
            $this->error('El sidecar de Kokoro no está levantado.');
            $this->line('Arráncalo con: '.KokoroTts::START_COMMAND);

            return self::FAILURE;
        }

        if (! $skipTimings) {
            $problem = $this->timer->modelProblem();

            if ($problem !== null) {
                $this->error($problem);
                $this->line('También puedes saltarte la alineación con --skip-timings.');

                return self::FAILURE;
            }
        }

        $story = Story::fromArray($payload);
        $voice = $this->resolveVoice();
        $speed = $this->resolveSpeed();
        $skipCache = (bool) $this->option('no-cache');
        $slug = pathinfo($storyFile, PATHINFO_FILENAME);
        $outputDirectory = $this->outputDirectory.DIRECTORY_SEPARATOR.$slug;
        $options = [
            'voice' => $voice,
            'speed' => $speed,
            'skip_cache' => $skipCache,
        ];

        // Fonética de narrationForTts(), partida por escena para conservar la pausa entre planos.
        $sentences = $this->splitter->splitScenes($story->scenesForTts());

        if ($sentences === []) {
            $this->error('El guion no tiene frases que narrar.');

            return self::FAILURE;
        }

        $startedAt = microtime(true);
        $cacheHits = 0;
        $clips = [];

        try {
            $clips = $this->synthesizeSentences($sentences, $options, $skipCache, $cacheHits);
            $master = $this->assembler->assemble($slug, $clips);

            $timingsPath = null;

            if (! $skipTimings) {
                $this->timer->time($slug, $master['wav'], $sentences);
                $timingsPath = $outputDirectory.DIRECTORY_SEPARATOR.'timings.json';
            }

            $this->writeAudioMetadata($storyFile, $payload, $master, $timingsPath, $voice, $speed, count($sentences));
        } catch (TtsUnavailableException $exception) {
            $this->cleanupOutputs($outputDirectory);
            $this->newLine();
            $this->error('El sidecar de Kokoro no está levantado.');
            $this->line('Arráncalo con: '.KokoroTts::START_COMMAND);

            return self::FAILURE;
        } catch (Throwable $exception) {
            $this->cleanupOutputs($outputDirectory);
            $this->newLine();
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $elapsed = microtime(true) - $startedAt;
        $this->renderSummary($master['duration'], count($sentences), $cacheHits, $elapsed);

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function alignExistingMaster(string $storyFile, array $payload): int
    {
        $problem = $this->timer->modelProblem();

        if ($problem !== null) {
            $this->error($problem);

            return self::FAILURE;
        }

        $story = Story::fromArray($payload);
        $slug = pathinfo($storyFile, PATHINFO_FILENAME);
        $outputDirectory = $this->outputDirectory.DIRECTORY_SEPARATOR.$slug;
        $wav = $outputDirectory.DIRECTORY_SEPARATOR.'narration.wav';

        if (! $this->files->isFile($wav)) {
            $this->error('No hay narration.wav. Ejecuta story:narrate primero.');

            return self::FAILURE;
        }

        $sentences = $this->splitter->splitScenes($story->scenesForTts());

        if ($sentences === []) {
            $this->error('El guion no tiene frases que narrar.');

            return self::FAILURE;
        }

        $this->info('Alineando el máster existente con whisper.cpp…');

        try {
            $this->timer->time($slug, $wav, $sentences);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $timingsPath = $outputDirectory.DIRECTORY_SEPARATOR.'timings.json';
        $audio = is_array($payload['audio'] ?? null) ? $payload['audio'] : [];
        $audio['timings'] = $timingsPath;
        $payload['audio'] = $audio;
        $this->writeStoryPayload($storyFile, $payload);

        $this->info('timings.json listo: '.$timingsPath);

        return self::SUCCESS;
    }

    /**
     * @param  list<NarrationSentence>  $sentences
     * @param  array{voice: string, speed: float, skip_cache: bool}  $options
     * @return list<array{path: string, pauseAfter: float}>
     */
    private function synthesizeSentences(array $sentences, array $options, bool $skipCache, int &$cacheHits): array
    {
        $bar = $this->output->createProgressBar(count($sentences));
        $bar->setFormat('%current%/%max% [%bar%] %percent:3s%% %message%');
        $bar->setMessage('');
        $bar->start();

        $clips = [];

        try {
            foreach ($sentences as $sentence) {
                $bar->setMessage($this->truncate($sentence->text));

                if (! $skipCache && $this->tts->isCached($sentence->text, $options)) {
                    $cacheHits++;
                }

                $clips[] = [
                    'path' => $this->tts->synthesize($sentence->text, $options),
                    'pauseAfter' => $sentence->pauseAfter,
                ];

                $bar->advance();
            }
        } finally {
            $bar->finish();
            $this->newLine();
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

    private function renderSummary(float $duration, int $sentenceCount, int $cacheHits, float $elapsed): void
    {
        $totalSeconds = (int) round($duration);
        $rtf = $duration > 0 ? $elapsed / $duration : 0.0;

        $this->newLine();
        $this->info('Narración lista.');
        $this->line(sprintf('  Duración: %02d:%02d', intdiv($totalSeconds, 60), $totalSeconds % 60));
        $this->line('  Frases: '.$sentenceCount);
        $this->line(sprintf('  Caché: %d/%d', $cacheHits, $sentenceCount));
        $this->line(sprintf('  Cómputo: %.1f s', $elapsed));
        $this->line(sprintf('  Factor tiempo real: %.2fx', $rtf));
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

    private function resolveStoryFile(string $file): ?string
    {
        $candidates = [
            $file,
            $this->outputDirectory.DIRECTORY_SEPARATOR.basename($file),
        ];

        foreach ($candidates as $candidate) {
            if ($this->files->isFile($candidate)) {
                return realpath($candidate) ?: $candidate;
            }
        }

        $this->error("No se encontró el guion '{$file}'.");

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readStoryPayload(string $path): ?array
    {
        try {
            $decoded = json_decode($this->files->get($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->error('El guion no es un JSON válido.');

            return null;
        }

        if (! is_array($decoded) || ! isset($decoded['scenes']) || ! is_array($decoded['scenes'])) {
            $this->error('El JSON no contiene un guion de historia.');

            return null;
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private function resolveVoice(): string
    {
        $voice = trim((string) ($this->option('voice') ?: $this->defaultVoice));

        return $voice !== '' ? $voice : $this->defaultVoice;
    }

    private function resolveSpeed(): float
    {
        $speed = $this->option('speed');

        if ($speed === null || $speed === '') {
            return $this->defaultSpeed;
        }

        return (float) $speed;
    }

    private function truncate(string $text, int $width = 60): string
    {
        $text = preg_replace('/\s+/u', ' ', trim($text)) ?? trim($text);

        if (mb_strlen($text) <= $width) {
            return $text;
        }

        return mb_substr($text, 0, $width - 1).'…';
    }
}
