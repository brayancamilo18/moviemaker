<?php

declare(strict_types=1);

namespace App\Services\Pipeline;

use App\DataObjects\Shot;
use App\Models\Story;
use App\Services\Ffmpeg\MediaProbe;
use App\Services\Storage\RenderedStoryPurger;
use App\Services\Storage\TempSweeper;
use App\Services\Story\StoryValidator;
use App\Services\Video\FinalEncoder;
use App\Services\Video\SceneComposer;
use App\Services\Video\ShotClipRenderer;
use App\Services\Video\SubtitleGenerator;
use App\Services\Video\VideoAssembler;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use JsonException;
use RuntimeException;
use Throwable;

final class RenderStep
{
    private const STEPS = ['clips', 'scenes', 'assemble', 'encode'];

    private readonly string $outputDirectory;

    private readonly string $workRoot;

    private readonly float $outroSeconds;

    private readonly float $tailSeconds;

    private readonly float $syncTolerance;

    private ?string $fromStep = null;

    public function __construct(
        private ShotClipRenderer $clips,
        private SceneComposer $scenes,
        private VideoAssembler $assembler,
        private FinalEncoder $encoder,
        private SubtitleGenerator $subtitles,
        private StoryValidator $validator,
        private TempSweeper $sweeper,
        private RenderedStoryPurger $purger,
        private Filesystem $files,
        private MediaProbe $probe,
        Repository $config,
    ) {
        $this->outputDirectory = storage_path('app/'.$config->get('stories.output_path'));
        $this->workRoot = storage_path('app/'.$config->get('stories.video.work_path'));
        $this->outroSeconds = (float) $config->get('stories.video.outro_seconds');
        $this->tailSeconds = (float) $config->get('stories.audio.tail_seconds');
        $this->syncTolerance = (float) $config->get('stories.video.sync_tolerance');
    }

    /**
     * @param  (callable(string, int, int, ?string): void)|null  $onProgress
     * @param  array{from?: string|null, keep_intermediates?: bool, no_grade?: bool, dry_run?: bool, keep_audio?: bool}  $options
     * @return array<string, mixed>
     */
    public function run(Story $story, ?callable $onProgress = null, array $options = []): array
    {
        $swept = $this->sweeper->sweep();
        $this->fromStep = isset($options['from']) && is_string($options['from']) && $options['from'] !== ''
            ? $options['from']
            : null;

        $slug = $story->slug;
        $storyFile = $this->scriptPath($slug);
        $payload = $this->readJson($storyFile);

        if ($payload === null) {
            return ['ok' => false, 'error' => 'El guion no es un JSON válido.', 'swept' => $swept];
        }

        $storyDirectory = $this->outputDirectory.DIRECTORY_SEPARATOR.$slug;
        $loaded = $this->readShots($storyDirectory.DIRECTORY_SEPARATOR.'shots.json');

        if (($loaded['ok'] ?? true) === false) {
            $loaded['swept'] = $swept;

            return $loaded;
        }

        /** @var list<Shot> $shots */
        $shots = $loaded['shots'];
        $audioPath = $this->mixPath($storyDirectory);
        $preflight = $this->preflight($shots, $audioPath, $slug);

        if ($preflight !== null) {
            $preflight['swept'] = $swept;

            return $preflight;
        }

        $report = $this->validator->validate($slug);

        if (! $report['passed']) {
            return [
                'ok' => false,
                'error' => 'Validación: hay bloqueantes. No se renderiza.',
                'validation' => $report,
                'swept' => $swept,
            ];
        }

        $audioDuration = $this->probe->tryDuration((string) $audioPath);

        if ($audioDuration === null) {
            return [
                'ok' => false,
                'error' => 'ffprobe no pudo leer la duración del mix de audio.',
                'validation' => $report,
                'swept' => $swept,
            ];
        }

        $grouped = $this->groupByScene($shots);
        $plan = $this->plan($grouped);
        $dryRun = (bool) ($options['dry_run'] ?? false);

        $base = [
            'ok' => true,
            'validation' => $report,
            'swept' => $swept,
            'shots' => $shots,
            'grouped' => $grouped,
            'plan' => $plan,
            'audio_duration' => $audioDuration,
            'outro_seconds' => $this->outroSeconds,
            'tail_seconds' => $this->tailSeconds,
            'sync_tolerance' => $this->syncTolerance,
            'dry_run' => $dryRun,
        ];

        if ($dryRun) {
            $base['shot_rows'] = $this->shotRows($shots, $grouped);
            $base['scene_rows'] = $plan['scenes'];

            return $base;
        }

        $graded = ! (bool) ($options['no_grade'] ?? false);
        $keepIntermediates = (bool) ($options['keep_intermediates'] ?? false);
        $workDir = $this->workRoot.DIRECTORY_SEPARATOR.$slug;
        $silentPath = $workDir.DIRECTORY_SEPARATOR.'silent.mp4';
        $videoPath = $storyDirectory.DIRECTORY_SEPARATOR.($graded ? 'video.mp4' : 'video-nograde.mp4');
        $subtitlesPath = $storyDirectory.DIRECTORY_SEPARATOR.'subtitles.srt';
        $started = hrtime(true);

        try {
            $clipResult = $this->renderClips($shots, $workDir, $onProgress);
            $sceneResult = $this->composeScenes($grouped, $clipResult['paths'], $workDir, $onProgress);
            $assembleSkipped = $this->assembleVideo($sceneResult['paths'], $audioDuration, $silentPath, $keepIntermediates, $onProgress);
            $encodeSkipped = $this->encodeVideo($silentPath, (string) $audioPath, $videoPath, $graded, $onProgress);
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'error' => $exception->getMessage(),
                'exception' => $exception,
                'blank_line' => true,
                'validation' => $report,
                'swept' => $swept,
            ];
        }

        $elapsed = (hrtime(true) - $started) / 1e9;
        $subtitleWarning = null;

        try {
            $subtitleWarning = $this->writeSubtitles($storyDirectory.DIRECTORY_SEPARATOR.'timings.json', $subtitlesPath);
        } catch (Throwable $exception) {
            $subtitleWarning = 'No se pudieron generar los subtítulos: '.$exception->getMessage();
        }

        $videoDuration = $this->probe->tryDuration($videoPath) ?? 0.0;
        $bytes = $this->files->isFile($videoPath) ? $this->files->size($videoPath) : 0;

        if (! $keepIntermediates) {
            $this->files->deleteDirectory($workDir);
        }

        $expected = $this->masterDuration($audioDuration);
        $this->updateStoryPayload($storyFile, $payload, [
            'mp4' => $graded ? $videoPath : $this->previousVideoPath($payload, 'mp4'),
            'mp4_nograde' => $graded ? $this->previousVideoPath($payload, 'mp4_nograde') : $videoPath,
            'subtitles' => $this->files->isFile($subtitlesPath) ? $subtitlesPath : null,
            'durationSeconds' => round($videoDuration, 3),
            'audioDurationSeconds' => round($audioDuration, 3),
            'deltaSeconds' => round($videoDuration - $expected, 3),
            'bytes' => $bytes,
            'elapsedSeconds' => round($elapsed, 1),
            'realtimeFactor' => round($elapsed / max($videoDuration, 0.001), 2),
            'grade' => $graded,
            'keptIntermediates' => $keepIntermediates,
        ]);

        return $base + [
            'purged' => $this->purgeArtifacts($slug, $graded, $bytes, (bool) ($options['keep_audio'] ?? false)),
            'video_seconds' => $videoDuration,
            'bytes' => $bytes,
            'elapsed' => $elapsed,
            'video_path' => $videoPath,
            'subtitle_warning' => $subtitleWarning,
            'subtitles_path' => $this->files->isFile($subtitlesPath) ? $subtitlesPath : null,
            'skipped_clips' => $clipResult['skipped'],
            'skipped_scenes' => $sceneResult['skipped'],
            'skipped_assemble' => $assembleSkipped,
            'skipped_encode' => $encodeSkipped,
            'kept_intermediates' => $keepIntermediates,
            'expected_duration' => $expected,
        ];
    }

    /**
     * Con el MP4 escrito, la narración y la mezcla dejan de hacer falta y son casi 180 MB. Se salta
     * cuando no hay vídeo que enseñar y cuando se pidió --no-grade, porque ese modo existe para
     * comparar dos codificaciones y la segunda necesitaría el audio otra vez.
     *
     * @return array{files: int, bytes: int}
     */
    private function purgeArtifacts(string $slug, bool $graded, int $bytes, bool $keepAudio): array
    {
        $nothing = ['files' => 0, 'bytes' => 0];

        if ($keepAudio || ! $graded || $bytes < 1 || ! $this->purger->enabled()) {
            return $nothing;
        }

        return $this->purger->purge($slug);
    }

    private function bodyDuration(float $audioDuration): float
    {
        return round($audioDuration - $this->tailSeconds, 3);
    }

    private function masterDuration(float $audioDuration): float
    {
        return round($this->bodyDuration($audioDuration) + $this->outroSeconds, 3);
    }

    /**
     * @param  list<Shot>  $shots
     * @return array{ok: false, error: string}|null
     */
    private function preflight(array $shots, ?string $audioPath, string $slug): ?array
    {
        if ($audioPath === null) {
            return ['ok' => false, 'error' => 'No hay mix de audio. Ejecuta story:mix primero.'];
        }

        $missing = [];

        foreach ($shots as $shot) {
            $path = trim((string) $shot->imagePath);

            if ($path === '' || ! $this->files->isFile($path) || $this->files->size($path) < 1) {
                $missing[] = $shot->order;
            }
        }

        if ($missing !== []) {
            return [
                'ok' => false,
                'error' => 'Faltan imágenes de estos planos: #'.implode('  #', $missing).'.',
                'hints' => ['Ejecuta story:images antes de renderizar.'],
            ];
        }

        $outro = $this->validator->outroCheck($slug);

        if ($outro['blocking'] && $outro['status'] === 'fail') {
            return ['ok' => false, 'error' => $outro['detail']];
        }

        return null;
    }

    /**
     * @param  list<Shot>  $shots
     * @param  (callable(string, int, int, ?string): void)|null  $onProgress
     * @return array{paths: array<string, string>, skipped: int}
     */
    private function renderClips(array $shots, string $workDir, ?callable $onProgress): array
    {
        $paths = [];
        $skipped = 0;
        $grouped = $this->groupByScene($shots);
        $total = count($shots);
        $done = 0;
        $this->progress($onProgress, 'clips', 0, $total);

        foreach ($shots as $shot) {
            $path = $this->clipPath($workDir, $shot);
            $paths[(string) $shot->order] = $path;

            $followedByXfade = $this->followedByXfade($shot, $grouped);
            $expected = $this->clips->durationFor($shot, $followedByXfade);
            $force = $this->shouldRerun('clips');

            if (! $force && $this->isValidVideo($path, $expected)) {
                $skipped++;
                $done++;
                $this->progress($onProgress, 'plano '.$shot->order, $done, $total);

                continue;
            }

            $this->clips->render($shot, $path, $followedByXfade);
            $done++;
            $this->progress($onProgress, 'plano '.$shot->order, $done, $total);
        }

        return ['paths' => $paths, 'skipped' => $skipped];
    }

    /**
     * @param  array<int, list<Shot>>  $grouped
     * @param  array<string, string>  $clipPaths
     * @param  (callable(string, int, int, ?string): void)|null  $onProgress
     * @return array{paths: list<string>, skipped: int}
     */
    private function composeScenes(array $grouped, array $clipPaths, string $workDir, ?callable $onProgress): array
    {
        $paths = [];
        $skipped = 0;
        $total = count($grouped);
        $done = 0;
        $this->progress($onProgress, 'escenas', 0, $total);

        foreach ($grouped as $order => $shots) {
            $path = $this->scenePath($workDir, $order);
            $paths[] = $path;

            $clips = [];

            foreach ($shots as $shot) {
                $clips[] = [
                    'path' => $clipPaths[(string) $shot->order],
                    'shot' => $shot,
                ];
            }

            $expected = $this->scenes->calculateOffsets($shots)['duration'];
            $force = $this->shouldRerun('scenes');

            if (! $force && $this->isValidVideo($path, $expected)) {
                $skipped++;
                $done++;
                $this->progress($onProgress, 'escena '.$order, $done, $total);

                continue;
            }

            $this->scenes->compose($clips, $path);
            $done++;
            $this->progress($onProgress, 'escena '.$order, $done, $total);
        }

        return ['paths' => $paths, 'skipped' => $skipped];
    }

    /**
     * @param  list<string>  $scenePaths
     * @param  (callable(string, int, int, ?string): void)|null  $onProgress
     */
    private function assembleVideo(
        array $scenePaths,
        float $audioDuration,
        string $silentPath,
        bool $keepIntermediates,
        ?callable $onProgress,
    ): bool {
        $expected = $this->masterDuration($audioDuration);

        if (! $this->shouldRerun('assemble') && $this->isValidVideo($silentPath, $expected)) {
            $this->progress($onProgress, 'concat + outro', 1, 1);

            return true;
        }

        $this->progress($onProgress, 'concat + outro', 0, 1);
        $this->assembler->assemble(
            $scenePaths,
            $this->bodyDuration($audioDuration),
            $silentPath,
            $keepIntermediates,
        );
        $this->progress($onProgress, 'concat + outro', 1, 1);

        return false;
    }

    /**
     * @param  (callable(string, int, int, ?string): void)|null  $onProgress
     */
    private function encodeVideo(
        string $silentPath,
        string $audioPath,
        string $videoPath,
        bool $grade,
        ?callable $onProgress,
    ): bool {
        if (! $this->files->isFile($silentPath)) {
            throw new RuntimeException('No hay vídeo mudo válido. Ejecuta sin --from o con --from=assemble.');
        }

        $audioDuration = $this->probe->tryDuration($audioPath) ?? 0.0;
        $expected = $this->masterDuration($audioDuration);

        if (! $this->shouldRerun('encode') && $this->isValidVideo($videoPath, $expected)) {
            $this->progress($onProgress, $grade ? 'gradación + encode' : 'encode sin gradación', 1, 1);

            return true;
        }

        $this->progress($onProgress, $grade ? 'gradación + encode' : 'encode sin gradación', 0, 1);
        $this->encoder->encode($silentPath, $audioPath, $videoPath, $grade);
        $this->progress($onProgress, $grade ? 'gradación + encode' : 'encode sin gradación', 1, 1);

        return false;
    }

    private function writeSubtitles(string $timingsPath, string $outputPath): ?string
    {
        if (! $this->files->isFile($timingsPath)) {
            return 'No hay timings.json; no se generan subtítulos.';
        }

        $timings = $this->readJson($timingsPath);

        if ($timings === null) {
            return 'No se pudieron leer los timings; no se generan subtítulos.';
        }

        $this->subtitles->generate($timings, $outputPath);

        return null;
    }

    /**
     * @param  list<Shot>  $shots
     * @param  array<int, list<Shot>>  $grouped
     * @return list<array{order: int, sceneOrder: int, motion: string, real: float, clip: float, extra: float, join: string}>
     */
    private function shotRows(array $shots, array $grouped): array
    {
        $rows = [];

        foreach ($shots as $shot) {
            $join = '—';
            $sceneShots = $grouped[$shot->sceneOrder] ?? [];

            foreach ($sceneShots as $index => $candidate) {
                if ($candidate->order !== $shot->order) {
                    continue;
                }

                if ($index > 0) {
                    $join = mb_strtolower(trim($shot->motion)) === 'static' ? 'corte' : 'fade';
                }

                break;
            }

            $real = round(max(0.0, $shot->end - $shot->start), 3);
            $clip = $this->clips->durationFor($shot, $this->followedByXfade($shot, $grouped));

            $rows[] = [
                'order' => $shot->order,
                'sceneOrder' => $shot->sceneOrder,
                'motion' => $shot->motion,
                'real' => $real,
                'clip' => $clip,
                'extra' => $clip - $real,
                'join' => $join,
            ];
        }

        return $rows;
    }

    /**
     * @param  array<int, list<Shot>>  $grouped
     * @return array{scenes: list<array{order: int, shots: int, duration: float, joins: list<string>}>, body: float, silent: float}
     */
    private function plan(array $grouped): array
    {
        $scenes = [];
        $body = 0.0;

        foreach ($grouped as $order => $shots) {
            $offsets = $this->scenes->calculateOffsets($shots);
            $joins = [];

            foreach ($offsets['offsets'] as $offset) {
                $joins[] = $offset === null ? 'corte' : 'fade';
            }

            $scenes[] = [
                'order' => $order,
                'shots' => count($shots),
                'duration' => $offsets['duration'],
                'joins' => $joins,
            ];
            $body += $offsets['duration'];
        }

        $body = round($body, 3);

        return [
            'scenes' => $scenes,
            'body' => $body,
            'silent' => round($body + $this->outroSeconds, 3),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function previousVideoPath(array $payload, string $key): ?string
    {
        $video = $payload['video'] ?? null;

        if (! is_array($video)) {
            return null;
        }

        $path = $video[$key] ?? null;

        return is_string($path) && $path !== '' ? $path : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $video
     */
    private function updateStoryPayload(string $storyFile, array $payload, array $video): void
    {
        $payload['video'] = $video;
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        if ($json === false) {
            throw new RuntimeException('No se pudo serializar el JSON de la historia.');
        }

        $this->files->put($storyFile, $json."\n");
    }

    /**
     * @param  list<Shot>  $shots
     * @return array<int, list<Shot>>
     */
    private function groupByScene(array $shots): array
    {
        $grouped = [];

        foreach ($shots as $shot) {
            $grouped[$shot->sceneOrder][] = $shot;
        }

        ksort($grouped);

        foreach ($grouped as $order => $sceneShots) {
            usort($sceneShots, static fn (Shot $left, Shot $right): int => $left->order <=> $right->order);
            $grouped[$order] = $sceneShots;
        }

        return $grouped;
    }

    private function shouldRerun(string $step): bool
    {
        if ($this->fromStep === null) {
            return false;
        }

        return array_search($step, self::STEPS, true) >= array_search($this->fromStep, self::STEPS, true);
    }

    /**
     * @param  array<int, list<Shot>>  $grouped
     */
    private function followedByXfade(Shot $shot, array $grouped): bool
    {
        $scene = $grouped[$shot->sceneOrder] ?? [];

        foreach ($scene as $index => $candidate) {
            if ($candidate->order !== $shot->order) {
                continue;
            }

            $next = $scene[$index + 1] ?? null;

            if ($next === null) {
                return false;
            }

            return mb_strtolower(trim($next->motion)) !== 'static';
        }

        return false;
    }

    private function clipPath(string $workDir, Shot $shot): string
    {
        return $workDir.DIRECTORY_SEPARATOR.'clips'.DIRECTORY_SEPARATOR.'shot-'.sprintf('%03d', $shot->order).'.mp4';
    }

    private function scenePath(string $workDir, int $order): string
    {
        return $workDir.DIRECTORY_SEPARATOR.'scenes'.DIRECTORY_SEPARATOR.'scene-'.sprintf('%02d', $order).'.mp4';
    }

    private function isValidVideo(string $path, ?float $expected = null): bool
    {
        if (! $this->files->isFile($path) || $this->files->size($path) < 1) {
            return false;
        }

        $duration = $this->probe->tryDuration($path);

        if ($duration === null) {
            return false;
        }

        if ($expected !== null && abs($duration - $expected) > $this->syncTolerance) {
            return false;
        }

        return true;
    }

    private function mixPath(string $directory): ?string
    {
        foreach (['narration_mix.wav', 'narration_mix.mp3'] as $name) {
            $path = $directory.DIRECTORY_SEPARATOR.$name;

            if ($this->files->isFile($path) && $this->files->size($path) > 0) {
                return $path;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function readShots(string $path): array
    {
        if (! $this->files->isFile($path)) {
            return ['ok' => false, 'error' => 'No hay shots.json. Ejecuta story:images primero.'];
        }

        $decoded = $this->readJson($path);

        if ($decoded === null || ! isset($decoded['shots']) || ! is_array($decoded['shots'])) {
            return ['ok' => false, 'error' => $decoded === null ? 'shots.json no es un JSON válido.' : 'shots.json no tiene el esquema esperado.'];
        }

        $shots = [];

        foreach ($decoded['shots'] as $row) {
            if (! is_array($row) || ! isset($row['order'], $row['sceneOrder'])) {
                continue;
            }

            $imagePath = isset($row['imagePath']) && is_string($row['imagePath']) ? $row['imagePath'] : null;
            $threat = $row['threatStage'] ?? null;

            $shots[] = new Shot(
                order: (int) $row['order'],
                sceneOrder: (int) $row['sceneOrder'],
                start: (float) ($row['start'] ?? 0),
                end: (float) ($row['end'] ?? 0),
                sourceText: is_string($row['sourceText'] ?? null) ? $row['sourceText'] : '',
                framing: is_string($row['framing'] ?? null) ? $row['framing'] : '',
                motion: is_string($row['motion'] ?? null) ? $row['motion'] : 'static',
                subject: is_string($row['subject'] ?? null) ? $row['subject'] : '',
                threatStage: is_string($threat) && $threat !== '' ? $threat : null,
                imagePath: $imagePath,
            );
        }

        if ($shots === []) {
            return ['ok' => false, 'error' => 'shots.json no contiene planos.'];
        }

        return ['ok' => true, 'shots' => $shots];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readJson(string $path): ?array
    {
        try {
            $decoded = json_decode($this->files->get($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! is_array($decoded)) {
            return null;
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
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
