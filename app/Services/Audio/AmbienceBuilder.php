<?php

declare(strict_types=1);

namespace App\Services\Audio;

use App\DataObjects\SceneAmbience;
use App\DataObjects\Story;
use App\DataObjects\StoryScene;
use App\Exceptions\FfmpegException;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Process\Process;

final class AmbienceBuilder
{
    private readonly string $ffmpeg;

    private readonly int $nice;

    private readonly float $timeout;

    private readonly float $acrossfade;

    private readonly float $tailSeconds;

    /**
     * @var array<string, float>
     */
    private readonly array $intensityLufs;

    public function __construct(
        private SoundResolver $resolver,
        private SyntheticSound $synth,
        private LibraryClipProcessor $processor,
        private Filesystem $files,
        private NarrationClock $clock,
        Repository $config,
    ) {
        $this->ffmpeg = (string) $config->get('stories.ffmpeg.binary');
        $this->nice = (int) $config->get('stories.ffmpeg.nice');
        $this->timeout = (float) $config->get('stories.ffmpeg.timeout');
        $this->acrossfade = (float) $config->get('stories.audio.ambience.acrossfade_seconds', 2.0);
        $this->tailSeconds = (float) $config->get('stories.audio.tail_seconds', 10.0);
        $this->intensityLufs = [
            SceneAmbience::INTENSITY_SUBTLE => (float) $config->get('stories.audio.ambience.intensity_lufs.subtle', -34.0),
            SceneAmbience::INTENSITY_MODERATE => (float) $config->get('stories.audio.ambience.intensity_lufs.moderate', -30.0),
            SceneAmbience::INTENSITY_HEAVY => (float) $config->get('stories.audio.ambience.intensity_lufs.heavy', -27.0),
        ];
    }

    /**
     * @param  array{scenes?: list<array<string, mixed>>}  $timings
     * @param  array<int, array{path: string, gainDb?: float}>  $resolvedScenes
     */
    public function build(Story $story, array $timings, string $narrationWavPath, array $resolvedScenes = []): AudioTrack
    {
        $windows = $this->sceneWindows($timings);

        if ($windows === []) {
            throw new InvalidArgumentException('timings.json no tiene escenas con duración.');
        }

        $expected = $this->expectedDuration($narrationWavPath);
        $windows = $this->pinTimeline($windows, $expected);
        $start = $windows[array_key_first($windows)]['start'];

        $workDir = storage_path('app/tmp/ambience-'.bin2hex(random_bytes(6)));
        $this->files->ensureDirectoryExists($workDir);
        $segments = [];

        try {
            $lastIndex = count($windows) - 1;

            foreach (array_values($windows) as $index => $window) {
                $scene = $this->sceneByOrder($story, $window['order']);
                $spec = $this->specFor($story, $scene);
                $needed = $window['duration'] + ($index === $lastIndex ? 0.0 : $this->acrossfade);
                $needed = max($needed, $this->acrossfade);
                $minDuration = max(0.05, $window['duration'] / 4.0);
                $override = $resolvedScenes[$window['order']] ?? null;
                $overridePath = is_array($override) ? (string) ($override['path'] ?? '') : '';

                if ($overridePath !== '' && $this->files->isFile($overridePath)) {
                    $source = $overridePath;
                    $gainDb = array_key_exists('gainDb', $override)
                        ? (float) $override['gainDb']
                        : $this->targetLufs($spec->intensity) - $this->processor->integratedLufs($source);
                } else {
                    $resolved = $this->resolver->resolve(
                        $spec->tags,
                        $spec->query !== '' ? $spec->query : implode(' ', $spec->tags),
                        'ambience',
                        $minDuration,
                    );
                    $source = $this->usablePath($resolved->path, $spec, $minDuration);
                    $lufs = $resolved->lufs !== 0.0
                        ? $resolved->lufs
                        : $this->processor->integratedLufs($source);
                    $gainDb = $this->targetLufs($spec->intensity) - $lufs;
                }

                $segment = $workDir.DIRECTORY_SEPARATOR.sprintf('scene-%02d.wav', $window['order']);

                $this->loopToDuration($source, $segment, $needed, $gainDb);
                $segments[] = $segment;
            }

            $output = storage_path('app/tmp/ambience-beds/'.bin2hex(random_bytes(6)).'.wav');
            $this->files->ensureDirectoryExists(dirname($output));
            $this->join($segments, $output);

            $fileDuration = round($expected - $start, 3);
            $this->fitToDuration($output, $fileDuration);

            $duration = $this->processor->duration($output);

            if (abs($duration - $fileDuration) > 0.05) {
                throw new RuntimeException(sprintf(
                    'La cama de ambiente dura %.3f s y se esperaban %.3f s (última frase + cola).',
                    $duration,
                    $fileDuration,
                ));
            }

            return new AudioTrack(
                path: $output,
                role: AudioTrack::ROLE_AMBIENCE,
                startAt: $start,
                endAt: round($start + $duration, 3),
                // El nivel por escena ya está en el WAV; el mezclador volvería a aplicar gainDb.
                gainDb: 0.0,
                duckable: true,
                fadeIn: 0.0,
                fadeOut: 0.0,
            );
        } finally {
            $this->files->deleteDirectory($workDir);
        }
    }

    /**
     * @param  array{scenes?: list<array<string, mixed>>}  $timings
     * @return list<array{order: int, start: float, end: float, duration: float}>
     */
    private function sceneWindows(array $timings): array
    {
        $windows = [];

        foreach ($timings['scenes'] ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }

            $duration = (float) ($row['duration'] ?? 0);
            $start = (float) ($row['start'] ?? 0);
            $end = (float) ($row['end'] ?? ($start + $duration));

            if ($duration <= 0 && $end > $start) {
                $duration = $end - $start;
            }

            if ($duration <= 0) {
                continue;
            }

            $windows[] = [
                'order' => (int) ($row['order'] ?? count($windows) + 1),
                'start' => round($start, 3),
                'end' => round($end, 3),
                'duration' => round($duration, 3),
            ];
        }

        usort(
            $windows,
            static fn (array $left, array $right): int => $left['order'] <=> $right['order'],
        );

        return $windows;
    }

    /**
     * Duración absoluta del máster: fin del WAV de narración + cola de ambiente.
     */
    public function expectedDuration(string $narrationWavPath): float
    {
        return $this->clock->masterDuration($narrationWavPath, $this->tailSeconds);
    }

    /**
     * Último end de whisper. Diagnóstico; no manda la línea de tiempo.
     *
     * @param  array{sentences?: list<array<string, mixed>>, scenes?: list<array<string, mixed>>}  $timings
     */
    public function lastTranscribedPhraseEnd(array $timings): float
    {
        $end = 0.0;

        foreach ($timings['sentences'] ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }

            $end = max($end, (float) ($row['end'] ?? 0));
        }

        if ($end > 0) {
            return round($end, 3);
        }

        foreach ($timings['scenes'] ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }

            $end = max($end, (float) ($row['end'] ?? 0), (float) ($row['start'] ?? 0) + (float) ($row['duration'] ?? 0));
        }

        return round($end, 3);
    }

    public function tailSeconds(): float
    {
        return $this->tailSeconds;
    }

    /**
     * @param  list<array{order: int, start: float, end: float, duration: float}>  $windows
     * @return list<array{order: int, start: float, end: float, duration: float}>
     */
    private function pinTimeline(array $windows, float $expected): array
    {
        $first = array_key_first($windows);
        $windows[$first]['start'] = 0.0;
        $windows[$first]['duration'] = round($windows[$first]['end'] - 0.0, 3);

        $last = array_key_last($windows);
        $start = $windows[$last]['start'];
        $end = max($windows[$last]['end'], $expected);

        $windows[$last]['end'] = round($end, 3);
        $windows[$last]['duration'] = round($end - $start, 3);

        return $windows;
    }

    private function sceneByOrder(Story $story, int $order): ?StoryScene
    {
        foreach ($story->scenes as $scene) {
            if ($scene->order === $order) {
                return $scene;
            }
        }

        return null;
    }

    private function specFor(Story $story, ?StoryScene $scene): SceneAmbience
    {
        if ($scene?->ambience instanceof SceneAmbience && $scene->ambience->query !== '') {
            return $scene->ambience;
        }

        foreach ($this->resolver->signalsFor($story) as $signal) {
            if (($signal['type'] ?? '') !== 'ambience') {
                continue;
            }

            return new SceneAmbience(
                query: $signal['query'],
                tags: $signal['tags'],
                intensity: $scene?->ambience?->intensity ?? SceneAmbience::INTENSITY_MODERATE,
            );
        }

        return new SceneAmbience(
            query: 'low drone ominous',
            tags: ['drone', 'dread'],
            intensity: SceneAmbience::INTENSITY_MODERATE,
        );
    }

    private function targetLufs(string $intensity): float
    {
        return $this->intensityLufs[$intensity] ?? $this->intensityLufs[SceneAmbience::INTENSITY_MODERATE];
    }

    /**
     * @param  list<string>  $tags
     */
    private function usablePath(string $path, SceneAmbience $spec, float $minDuration): string
    {
        if ($path !== '' && $this->files->isFile($path)) {
            return $path;
        }

        $profile = $this->synth->ambienceProfile($this->synth->inferProfile($spec->query, $spec->tags));
        $seed = (int) sprintf('%u', crc32($spec->query.'|'.implode(',', $spec->tags)));

        return $this->synth->generate($profile, max($minDuration, 6.0), $seed);
    }

    private function loopToDuration(string $source, string $destination, float $duration, float $gainDb): void
    {
        $duration = round(max(0.001, $duration), 3);
        $volume = $this->formatDb($gainDb);

        $this->run([
            $this->ffmpeg, '-nostdin', '-y', '-hide_banner',
            '-stream_loop', '-1',
            '-i', $source,
            '-t', sprintf('%.3f', $duration),
            '-ac', '2',
            '-ar', '48000',
            '-sample_fmt', 's16',
            '-af', 'volume='.$volume.',aformat=sample_rates=48000:channel_layouts=stereo',
            $destination,
        ]);
    }

    private function fitToDuration(string $path, float $duration): void
    {
        $duration = round(max(0.001, $duration), 3);
        $fitted = $path.'.fit.wav';

        $this->run([
            $this->ffmpeg, '-nostdin', '-y', '-hide_banner',
            '-i', $path,
            '-t', sprintf('%.3f', $duration),
            '-af', sprintf(
                'atrim=0:%.3f,apad=whole_dur=%.3f,asetpts=PTS-STARTPTS,aformat=sample_rates=48000:channel_layouts=stereo',
                $duration,
                $duration,
            ),
            '-ac', '2',
            '-ar', '48000',
            '-sample_fmt', 's16',
            $fitted,
        ]);

        $this->files->delete($path);
        $this->files->move($fitted, $path);
    }

    /**
     * @param  list<string>  $segments
     */
    private function join(array $segments, string $output): void
    {
        if ($segments === []) {
            throw new RuntimeException('No hay segmentos de ambiente que unir.');
        }

        if (count($segments) === 1) {
            $this->files->copy($segments[0], $output);

            return;
        }

        $script = $this->acrossfadeScript(count($segments));
        $scriptPath = $output.'.acrossfade.txt';
        $this->files->put($scriptPath, $script);

        $arguments = [$this->ffmpeg, '-nostdin', '-y', '-hide_banner'];

        foreach ($segments as $segment) {
            $arguments[] = '-i';
            $arguments[] = $segment;
        }

        $arguments[] = '-filter_complex_script';
        $arguments[] = $scriptPath;
        $arguments[] = '-map';
        $arguments[] = '[out]';
        $arguments[] = '-c:a';
        $arguments[] = 'pcm_s16le';
        $arguments[] = '-ar';
        $arguments[] = '48000';
        $arguments[] = '-ac';
        $arguments[] = '2';
        $arguments[] = $output;

        try {
            $this->run($arguments);
        } finally {
            $this->files->delete($scriptPath);
        }
    }

    private function acrossfadeScript(int $count): string
    {
        $lines = [];
        $previous = '0:a';

        for ($index = 1; $index < $count; $index++) {
            $label = $index === $count - 1 ? 'out' : 'a'.$index;
            // Cortes secos de ambiente suenan a chasquido; el acrossfade esconde el empalme.
            $lines[] = sprintf(
                '[%s][%d:a]acrossfade=d=%.3f:o=1:c1=tri:c2=tri[%s]',
                $previous,
                $index,
                $this->acrossfade,
                $label,
            );
            $previous = $label;
        }

        return implode(";\n", $lines)."\n";
    }

    private function formatDb(float $gainDb): string
    {
        $formatted = rtrim(rtrim(sprintf('%.3f', $gainDb), '0'), '.');

        if ($formatted === '' || $formatted === '-') {
            $formatted = '0';
        }

        return $formatted.'dB';
    }

    /**
     * @param  list<string>  $arguments
     */
    private function run(array $arguments): void
    {
        $process = new Process(['nice', '-n', (string) $this->nice, ...$arguments]);
        $process->setTimeout($this->timeout);
        $process->run();

        if (! $process->isSuccessful()) {
            throw FfmpegException::fromProcess($process);
        }
    }
}
