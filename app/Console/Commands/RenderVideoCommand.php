<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\DataObjects\Shot;
use App\Services\Image\ShotPlanner;
use App\Services\Video\FinalEncoder;
use App\Services\Video\SceneComposer;
use App\Services\Video\ShotClipRenderer;
use App\Services\Video\SubtitleGenerator;
use App\Services\Video\VideoAssembler;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use JsonException;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

final class RenderVideoCommand extends Command
{
    private const STEPS = ['clips', 'scenes', 'assemble', 'encode'];

    private const SYNC_TOLERANCE = 0.1;

    private const PLAN_TOLERANCE = 0.01;

    protected $signature = 'story:render
        {file : JSON del guion}
        {--from= : Reinicia desde clips, scenes, assemble o encode}
        {--keep-intermediates : Conserva clips, escenas y vídeo mudo}
        {--no-grade : Codifica sin corrección de color, para comparar}
        {--dry-run : Imprime el plan y lo compara con el audio, sin renderizar}';

    protected $description = 'Monta el vídeo de una historia a partir de shots.json y el mix de audio';

    private readonly string $outputDirectory;

    private readonly string $workRoot;

    private readonly string $ffprobe;

    private readonly float $outroSeconds;

    private readonly float $timeout;

    private ?string $fromStep = null;

    public function __construct(
        private ShotClipRenderer $clips,
        private SceneComposer $scenes,
        private VideoAssembler $assembler,
        private FinalEncoder $encoder,
        private SubtitleGenerator $subtitles,
        private Filesystem $files,
        Repository $config,
    ) {
        parent::__construct();

        $this->outputDirectory = storage_path('app/'.$config->get('stories.output_path'));
        $this->workRoot = storage_path('app/'.$config->get('stories.video.work_path'));
        $this->ffprobe = (string) $config->get('stories.ffmpeg.ffprobe');
        $this->outroSeconds = (float) $config->get('stories.video.outro_seconds');
        $this->timeout = (float) $config->get('stories.ffmpeg.timeout');
    }

    public function handle(): int
    {
        $from = $this->resolveFrom();

        if ($from === false) {
            return self::FAILURE;
        }

        $this->fromStep = $from;

        $storyFile = $this->resolveStoryFile((string) $this->argument('file'));

        if ($storyFile === null) {
            return self::FAILURE;
        }

        $payload = $this->readJson($storyFile, 'El guion no es un JSON válido.');

        if ($payload === null) {
            return self::FAILURE;
        }

        $slug = pathinfo($storyFile, PATHINFO_FILENAME);
        $storyDirectory = $this->outputDirectory.DIRECTORY_SEPARATOR.$slug;
        $loaded = $this->readShots($storyDirectory.DIRECTORY_SEPARATOR.'shots.json');

        if ($loaded === null) {
            return self::FAILURE;
        }

        [$shots, $placeholderOrders, $plannerVersion] = $loaded;

        $audioPath = $this->mixPath($storyDirectory);
        $preflight = $this->preflight($shots, $placeholderOrders, $audioPath, (bool) $this->option('dry-run'));

        if ($preflight === false) {
            return self::FAILURE;
        }

        $audioDuration = $this->probeDuration((string) $audioPath);

        if ($audioDuration === null) {
            $this->error('ffprobe no pudo leer la duración del mix de audio.');

            return self::FAILURE;
        }

        $this->warnStalePlanner($plannerVersion);

        if (! $this->shotsCoverAudio($shots, $audioDuration)) {
            return self::FAILURE;
        }

        $grouped = $this->groupByScene($shots);

        if ((bool) $this->option('dry-run')) {
            $this->printPlan($shots, $grouped, $this->plan($grouped), $audioDuration);

            return self::SUCCESS;
        }

        $workDir = $this->workRoot.DIRECTORY_SEPARATOR.$slug;
        $silentPath = $workDir.DIRECTORY_SEPARATOR.'silent.mp4';
        $videoPath = $storyDirectory.DIRECTORY_SEPARATOR.((bool) $this->option('no-grade') ? 'video-nograde.mp4' : 'video.mp4');
        $subtitlesPath = $storyDirectory.DIRECTORY_SEPARATOR.'subtitles.srt';
        $started = hrtime(true);

        try {
            $clipPaths = $this->renderClips($shots, $workDir);
            $scenePaths = $this->composeScenes($grouped, $clipPaths, $workDir);
            $this->assembleVideo($scenePaths, $audioDuration, $silentPath);
            $this->encodeVideo($silentPath, (string) $audioPath, $videoPath);
            $this->writeSubtitles($storyDirectory.DIRECTORY_SEPARATOR.'timings.json', $subtitlesPath);
        } catch (Throwable $exception) {
            $this->newLine();
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $elapsed = (hrtime(true) - $started) / 1e9;
        $videoDuration = $this->probeDuration($videoPath) ?? 0.0;
        $bytes = $this->files->isFile($videoPath) ? $this->files->size($videoPath) : 0;

        if (! (bool) $this->option('keep-intermediates')) {
            $this->files->deleteDirectory($workDir);
            $this->comment('Intermedios borrados.');
        }

        $this->printSummary($videoDuration, $audioDuration, $bytes, $elapsed, $videoPath);
        $expected = round($audioDuration + $this->outroSeconds, 3);
        $this->updateStoryPayload($storyFile, $payload, [
            'mp4' => $videoPath,
            'subtitles' => $this->files->isFile($subtitlesPath) ? $subtitlesPath : null,
            'durationSeconds' => round($videoDuration, 3),
            'audioDurationSeconds' => round($audioDuration, 3),
            'deltaSeconds' => round($videoDuration - $expected, 3),
            'bytes' => $bytes,
            'elapsedSeconds' => round($elapsed, 1),
            'realtimeFactor' => round($elapsed / max($videoDuration, 0.001), 2),
            'grade' => ! (bool) $this->option('no-grade'),
            'keptIntermediates' => (bool) $this->option('keep-intermediates'),
        ]);

        return self::SUCCESS;
    }

    /**
     * @return 'clips'|'scenes'|'assemble'|'encode'|null|false
     */
    private function resolveFrom(): string|false|null
    {
        $from = $this->option('from');

        if ($from === null || $from === '') {
            return null;
        }

        $from = trim((string) $from);

        if (! in_array($from, self::STEPS, true)) {
            $this->error('--from debe ser clips, scenes, assemble o encode.');

            return false;
        }

        return $from;
    }

    /**
     * @param  list<Shot>  $shots
     * @param  list<int>  $placeholderOrders
     */
    private function preflight(array $shots, array $placeholderOrders, ?string $audioPath, bool $dryRun): bool
    {
        if ($audioPath === null) {
            $this->error('No hay mix de audio. Ejecuta story:mix primero.');

            return false;
        }

        $missing = [];

        foreach ($shots as $shot) {
            $path = trim((string) $shot->imagePath);

            if ($path === '' || ! $this->files->isFile($path) || $this->files->size($path) < 1) {
                $missing[] = $shot->order;
            }
        }

        if ($missing !== []) {
            $this->error('Faltan imágenes de estos planos: #'.implode('  #', $missing).'.');
            $this->line('Ejecuta story:images antes de renderizar.');

            return false;
        }

        if ($placeholderOrders === []) {
            return true;
        }

        $file = basename((string) $this->argument('file'));

        $this->newLine();
        $this->line('<fg=red>Hay marcadores de Pollinations. Esos planos saldrán negros o con el aviso de error:</>');
        $this->line('<fg=red>  #'.implode('  #', $placeholderOrders).'</>');
        $this->line('<fg=red>  php artisan story:images '.$file.' --only='.implode(',', $placeholderOrders).'</>');

        if ($dryRun) {
            $this->warn('Simulación: el render se detendría aquí a falta de confirmación.');

            return true;
        }

        if (! $this->confirm('¿Renderizar igual?', false)) {
            $this->comment('Cancelado. Regenera los marcadores y vuelve a lanzar story:render.');

            return false;
        }

        return true;
    }

    /**
     * @param  list<Shot>  $shots
     * @return array<string, string>
     */
    private function renderClips(array $shots, string $workDir): array
    {
        $this->info('Clips');
        $bar = $this->output->createProgressBar(count($shots));
        $bar->setFormat('%current%/%max% [%bar%] %percent:3s%%  %message%');
        $bar->setMessage('');
        $bar->start();

        $paths = [];
        $skipped = 0;
        $grouped = $this->groupByScene($shots);

        try {
            foreach ($shots as $shot) {
                $path = $this->clipPath($workDir, $shot);
                $paths[(string) $shot->order] = $path;
                $bar->setMessage('plano '.$shot->order);

                $followedByXfade = $this->followedByXfade($shot, $grouped);
                $expected = $this->clips->durationFor($shot, $followedByXfade);
                $force = $this->shouldRerun('clips');

                if (! $force && $this->isValidVideo($path, $expected)) {
                    $skipped++;
                    $bar->advance();

                    continue;
                }

                $this->clips->render($shot, $path, $followedByXfade);
                $bar->advance();
            }
        } finally {
            $bar->finish();
            $this->newLine();
        }

        if ($skipped > 0) {
            $this->comment("  Omitidos {$skipped} clips ya válidos.");
        }

        return $paths;
    }

    /**
     * @param  array<int, list<Shot>>  $grouped
     * @param  array<string, string>  $clipPaths
     * @return list<string>
     */
    private function composeScenes(array $grouped, array $clipPaths, string $workDir): array
    {
        $this->info('Escenas');
        $bar = $this->output->createProgressBar(count($grouped));
        $bar->setFormat('%current%/%max% [%bar%] %percent:3s%%  %message%');
        $bar->setMessage('');
        $bar->start();

        $paths = [];
        $skipped = 0;

        try {
            foreach ($grouped as $order => $shots) {
                $path = $this->scenePath($workDir, $order);
                $paths[] = $path;
                $bar->setMessage('escena '.$order);

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
                    $bar->advance();

                    continue;
                }

                $this->scenes->compose($clips, $path);
                $bar->advance();
            }
        } finally {
            $bar->finish();
            $this->newLine();
        }

        if ($skipped > 0) {
            $this->comment("  Omitidas {$skipped} escenas ya válidas.");
        }

        return $paths;
    }

    /**
     * @param  list<string>  $scenePaths
     */
    private function assembleVideo(array $scenePaths, float $audioDuration, string $silentPath): void
    {
        $this->info('Ensamblado');
        $expected = round($audioDuration + $this->outroSeconds, 3);

        if (! $this->shouldRerun('assemble') && $this->isValidVideo($silentPath, $expected)) {
            $this->comment('  Vídeo mudo ya válido, se omite.');

            return;
        }

        $bar = $this->output->createProgressBar(1);
        $bar->setFormat('%current%/%max% [%bar%] %percent:3s%%  %message%');
        $bar->setMessage('concat + outro');
        $bar->start();

        try {
            $this->assembler->assemble($scenePaths, $audioDuration, $silentPath);
        } finally {
            $bar->advance();
            $bar->finish();
            $this->newLine();
        }
    }

    private function encodeVideo(string $silentPath, string $audioPath, string $videoPath): void
    {
        $this->info('Codificación final');

        if (! $this->files->isFile($silentPath)) {
            throw new RuntimeException('No hay vídeo mudo válido. Ejecuta sin --from o con --from=assemble.');
        }

        $audioDuration = $this->probeDuration($audioPath) ?? 0.0;
        $grade = ! (bool) $this->option('no-grade');
        $expected = round($audioDuration + $this->outroSeconds, 3);

        if (! $this->shouldRerun('encode') && $this->isValidVideo($videoPath, $expected)) {
            $this->comment('  Vídeo final ya válido, se omite.');

            return;
        }

        $bar = $this->output->createProgressBar(1);
        $bar->setFormat('%current%/%max% [%bar%] %percent:3s%%  %message%');
        $bar->setMessage($grade ? 'gradación + encode' : 'encode sin gradación');
        $bar->start();

        try {
            $this->encoder->encode($silentPath, $audioPath, $videoPath, $grade);
        } finally {
            $bar->advance();
            $bar->finish();
            $this->newLine();
        }
    }

    private function writeSubtitles(string $timingsPath, string $outputPath): void
    {
        if (! $this->files->isFile($timingsPath)) {
            $this->warn('No hay timings.json; no se generan subtítulos.');

            return;
        }

        $timings = $this->readJson($timingsPath, 'timings.json no es un JSON válido.');

        if ($timings === null) {
            $this->warn('No se pudieron leer los timings; no se generan subtítulos.');

            return;
        }

        $this->subtitles->generate($timings, $outputPath);
        $this->line('Subtítulos: '.$outputPath);
    }

    /**
     * @param  list<Shot>  $shots
     * @param  array<int, list<Shot>>  $grouped
     * @param  array{scenes: list<array{order: int, shots: int, duration: float, joins: list<string>}>, body: float, silent: float}  $plan
     */
    private function printPlan(array $shots, array $grouped, array $plan, float $audioDuration): void
    {
        $this->warn('Modo simulación: no se renderizará.');
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
                '#'.$shot->order,
                $shot->sceneOrder,
                $shot->motion,
                sprintf('%.3f', $real),
                sprintf('%.3f', $clip),
                sprintf('%+.3f', $clip - $real),
                $join,
            ];
        }

        $this->table(['Plano', 'Escena', 'Motion', 'Real s', 'Clip s', 'Extra s', 'Empalme'], $rows);

        $sceneRows = [];

        foreach ($plan['scenes'] as $scene) {
            $sceneRows[] = [
                $scene['order'],
                $scene['shots'],
                sprintf('%.3f', $scene['duration']),
                implode(', ', $scene['joins']) ?: '—',
            ];
        }

        $this->table(['Escena', 'Planos', 'Duración s', 'Empalmes'], $sceneRows);

        $delta = $plan['body'] - $audioDuration;
        $master = round($audioDuration + $this->outroSeconds, 3);
        $this->newLine();
        $this->line('Planos: '.count($shots).'  Escenas: '.count($grouped));
        $this->line(sprintf('Cuerpo previsto: %s (%.3f s)', $this->formatClock($plan['body']), $plan['body']));
        $this->line(sprintf('Outro: %.1f s  Vídeo mudo previsto: %s', $this->outroSeconds, $this->formatClock($plan['silent'])));
        $this->line(sprintf('Audio: %s (%.3f s)', $this->formatClock($audioDuration), $audioDuration));
        $this->line(sprintf('Máster previsto: %s (%.3f s)', $this->formatClock($master), $master));
        $this->line($this->deltaLine($delta, 'cuerpo−audio'));
        $this->comment('El audio se rellena con silencio durante el outro para que -shortest corte por el vídeo.');
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

    private function printSummary(
        float $videoDuration,
        float $audioDuration,
        int $bytes,
        float $elapsed,
        string $videoPath,
    ): void {
        $expected = round($audioDuration + $this->outroSeconds, 3);
        $delta = $videoDuration - $expected;

        $this->newLine();
        $this->info('Render listo.');
        $this->line('  Vídeo: '.$this->formatClock($videoDuration).sprintf(' (%.3f s)', $videoDuration));
        $this->line('  Audio: '.$this->formatClock($audioDuration).sprintf(' (%.3f s)', $audioDuration));
        $this->line(sprintf('  Outro: %.1f s  Esperado: %s (%.3f s)', $this->outroSeconds, $this->formatClock($expected), $expected));
        $this->line('  '.$this->deltaLine($delta, 'vídeo−(audio+outro)'));
        $this->line(sprintf('  Tamaño: %.1f MiB', $bytes / 1048576));
        $this->line(sprintf('  Tiempo: %.1f s', $elapsed));
        $this->line(sprintf('  Factor tiempo real: %.2fx', $elapsed / max($videoDuration, 0.001)));
        $this->line('  Fichero: '.$videoPath);
    }

    private function deltaLine(float $delta, string $label): string
    {
        $text = sprintf('Desfase %s: %+.3f s', $label, $delta);

        if (abs($delta) > self::SYNC_TOLERANCE) {
            return '<fg=red>'.$text.'</>';
        }

        return $text;
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

        $duration = $this->probeDuration($path);

        if ($duration === null) {
            return false;
        }

        if ($expected !== null && abs($duration - $expected) > 0.15) {
            return false;
        }

        return true;
    }

    private function probeDuration(string $path): ?float
    {
        $process = new Process([
            $this->ffprobe, '-v', 'error',
            '-show_entries', 'format=duration',
            '-of', 'csv=p=0',
            $path,
        ]);
        $process->setTimeout($this->timeout);
        $process->run();

        if (! $process->isSuccessful()) {
            return null;
        }

        $duration = (float) trim($process->getOutput());

        return $duration > 0 ? round($duration, 3) : null;
    }

    private function formatClock(float $seconds): string
    {
        $total = (int) round(max(0.0, $seconds));
        $hours = intdiv($total, 3600);
        $minutes = intdiv($total % 3600, 60);
        $secs = $total % 60;

        if ($hours > 0) {
            return sprintf('%d:%02d:%02d', $hours, $minutes, $secs);
        }

        return sprintf('%02d:%02d', $minutes, $secs);
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
     * @return array{0: list<Shot>, 1: list<int>, 2: ?int}|null
     */
    private function readShots(string $path): ?array
    {
        if (! $this->files->isFile($path)) {
            $this->error('No hay shots.json. Ejecuta story:images primero.');

            return null;
        }

        $decoded = $this->readJson($path, 'shots.json no es un JSON válido.');

        if ($decoded === null || ! isset($decoded['shots']) || ! is_array($decoded['shots'])) {
            $this->error('shots.json no tiene el esquema esperado.');

            return null;
        }

        $shots = [];
        $placeholders = [];
        $plannerVersion = array_key_exists('plannerVersion', $decoded)
            ? (int) $decoded['plannerVersion']
            : null;

        foreach ($decoded['shots'] as $row) {
            if (! is_array($row) || ! isset($row['order'], $row['sceneOrder'])) {
                continue;
            }

            $imagePath = isset($row['imagePath']) && is_string($row['imagePath']) ? $row['imagePath'] : null;
            $threat = $row['threatStage'] ?? null;
            $placeholder = (bool) ($row['placeholder'] ?? false);

            if (is_string($imagePath) && str_starts_with(basename($imagePath), 'placeholder-')) {
                $placeholder = true;
            }

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

            if ($placeholder) {
                $placeholders[] = (int) $row['order'];
            }
        }

        if ($shots === []) {
            $this->error('shots.json no contiene planos.');

            return null;
        }

        return [$shots, $placeholders, $plannerVersion];
    }

    /**
     * @param  list<Shot>  $shots
     */
    private function shotsCoverAudio(array $shots, float $audioDuration): bool
    {
        $sum = 0.0;

        foreach ($shots as $shot) {
            $sum += max(0.0, $shot->end - $shot->start);
        }

        $sum = round($sum, 3);
        $delta = round($sum - $audioDuration, 3);

        if (abs($delta) <= self::PLAN_TOLERANCE) {
            return true;
        }

        $this->error(sprintf(
            'Los planos cubren %.3f s y el audio dura %.3f s (desfase %+.3f s).',
            $sum,
            $audioDuration,
            $delta,
        ));
        $this->line('Regenera el plan con story:images para teselar los silencios sobre el máster.');

        return false;
    }

    private function warnStalePlanner(?int $plannerVersion): void
    {
        if ($plannerVersion !== null && $plannerVersion >= ShotPlanner::VERSION) {
            return;
        }

        $seen = $plannerVersion === null ? 'ausente' : (string) $plannerVersion;

        $this->warn(sprintf(
            'Plan de plannerVersion %s; el actual es %d. Regenera con story:images.',
            $seen,
            ShotPlanner::VERSION,
        ));
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
    private function readJson(string $path, string $invalidMessage): ?array
    {
        try {
            $decoded = json_decode($this->files->get($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->error($invalidMessage);

            return null;
        }

        if (! is_array($decoded)) {
            $this->error($invalidMessage);

            return null;
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
