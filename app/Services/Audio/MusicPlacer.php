<?php

declare(strict_types=1);

namespace App\Services\Audio;

use App\DataObjects\Story;
use App\Exceptions\FfmpegException;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;

final class MusicPlacer
{
    private readonly bool $enabled;

    private readonly float $targetLufs;

    private readonly float $hookFadeOut;

    private readonly float $climaxRatio;

    private readonly float $climaxTail;

    private readonly float $climaxFadeIn;

    private readonly float $climaxFadeOut;

    private readonly string $ffmpeg;

    private readonly int $nice;

    private readonly float $timeout;

    public function __construct(
        private SoundResolver $resolver,
        private LibraryClipProcessor $processor,
        private Filesystem $files,
        private LoggerInterface $logger,
        Repository $config,
    ) {
        $this->enabled = (bool) $config->get('stories.audio.music_enabled', false);
        $this->targetLufs = (float) $config->get('stories.audio.mix.music_lufs', -30.0);
        $this->hookFadeOut = (float) $config->get('stories.audio.music.hook_fade_out', 4.0);
        $this->climaxRatio = (float) $config->get('stories.audio.music.climax_start_ratio', 0.75);
        $this->climaxTail = (float) $config->get('stories.audio.music.climax_tail_seconds', 8.0);
        $this->climaxFadeIn = (float) $config->get('stories.audio.music.climax_fade_in', 6.0);
        $this->climaxFadeOut = (float) $config->get('stories.audio.music.climax_fade_out', 5.0);
        $this->ffmpeg = (string) $config->get('stories.ffmpeg.binary');
        $this->nice = (int) $config->get('stories.ffmpeg.nice');
        $this->timeout = (float) $config->get('stories.ffmpeg.timeout');
    }

    /**
     * @param  array{scenes?: list<array<string, mixed>>}  $timings
     * @param  array<string, array{path: string, gainDb?: float}>  $resolved
     * @return list<AudioTrack>
     */
    public function place(Story $story, array $timings, array $resolved = []): array
    {
        if (! $this->enabled) {
            return [];
        }

        $total = $this->totalDuration($timings);
        $firstEnd = $this->firstSceneEnd($timings);
        $tags = $this->musicTags($story);
        $query = $tags !== [] ? implode(' ', $tags) : 'dark ambient drone';
        $tracks = [];
        $used = [];

        if ($firstEnd !== null && $firstEnd > 0.0) {
            $hook = $this->track(
                $tags,
                $query,
                $used,
                startAt: 0.0,
                endAt: $firstEnd,
                fadeIn: 0.0,
                fadeOut: $this->hookFadeOut,
                label: 'gancho',
                override: $resolved['music.hook'] ?? null,
            );

            if ($hook instanceof AudioTrack) {
                $tracks[] = $hook;
            }
        }

        $climaxStart = round($total * $this->climaxRatio, 3);
        $climaxEnd = round($total - $this->climaxTail, 3);

        if ($total > 0.0 && $climaxEnd > $climaxStart) {
            $climax = $this->track(
                $tags,
                $query,
                $used,
                startAt: $climaxStart,
                endAt: $climaxEnd,
                fadeIn: $this->climaxFadeIn,
                fadeOut: $this->climaxFadeOut,
                label: 'clímax',
                override: $resolved['music.climax'] ?? null,
            );

            if ($climax instanceof AudioTrack) {
                $tracks[] = $climax;
            }
        }

        return $tracks;
    }

    /**
     * @param  list<string>  $tags
     * @param  list<string>  $exclude
     * @param  array{path: string, gainDb?: float}|null  $override
     */
    private function track(
        array $tags,
        string $query,
        array &$exclude,
        float $startAt,
        float $endAt,
        float $fadeIn,
        float $fadeOut,
        string $label,
        ?array $override = null,
    ): ?AudioTrack {
        $window = round($endAt - $startAt, 3);

        if ($window <= 0.0) {
            return null;
        }

        $overridePath = is_array($override) ? (string) ($override['path'] ?? '') : '';
        $gainDb = null;

        if ($overridePath !== '' && $this->files->isFile($overridePath)) {
            $source = $overridePath;
            $gainDb = array_key_exists('gainDb', $override) ? (float) $override['gainDb'] : null;
        } else {
            $resolved = $this->resolver->resolve($tags, $query, 'music', 0.0, $exclude);
            $source = $resolved->path;

            if ($source !== '' && $this->files->isFile($source) && $resolved->lufs !== 0.0) {
                $gainDb = $this->targetLufs - $resolved->lufs;
            }
        }

        if ($source === '' || ! $this->files->isFile($source)) {
            $this->logger->warning('Música sin archivo usable; se omite la pista.', [
                'pista' => $label,
                'query' => $query,
            ]);

            return null;
        }

        $exclude[] = $source;
        $path = $this->fitToWindow($source, $window);

        if ($gainDb === null) {
            $gainDb = $this->targetLufs - $this->processor->integratedLufs($path);
        }

        return new AudioTrack(
            path: $path,
            role: AudioTrack::ROLE_MUSIC,
            startAt: round($startAt, 3),
            endAt: round($endAt, 3),
            gainDb: round($gainDb, 3),
            duckable: true,
            fadeIn: $fadeIn,
            fadeOut: $fadeOut,
        );
    }

    /**
     * @param  array{scenes?: list<array<string, mixed>>}  $timings
     */
    private function firstSceneEnd(array $timings): ?float
    {
        $windows = $this->sceneWindows($timings);

        if ($windows === []) {
            return null;
        }

        return $windows[0]['end'];
    }

    /**
     * @param  array{scenes?: list<array<string, mixed>>}  $timings
     */
    private function totalDuration(array $timings): float
    {
        $end = 0.0;

        foreach ($this->sceneWindows($timings) as $window) {
            $end = max($end, $window['end']);
        }

        return round($end, 3);
    }

    /**
     * @param  array{scenes?: list<array<string, mixed>>}  $timings
     * @return list<array{order: int, start: float, end: float}>
     */
    private function sceneWindows(array $timings): array
    {
        $windows = [];

        foreach ($timings['scenes'] ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }

            $start = (float) ($row['start'] ?? 0);
            $duration = (float) ($row['duration'] ?? 0);
            $end = (float) ($row['end'] ?? ($start + $duration));

            if ($duration <= 0 && $end > $start) {
                $duration = $end - $start;
            }

            if ($end <= $start && $duration > 0) {
                $end = $start + $duration;
            }

            if ($end <= $start) {
                continue;
            }

            $windows[] = [
                'order' => (int) ($row['order'] ?? count($windows) + 1),
                'start' => round($start, 3),
                'end' => round($end, 3),
            ];
        }

        usort(
            $windows,
            static fn (array $left, array $right): int => $left['order'] <=> $right['order'],
        );

        return $windows;
    }

    /**
     * @return list<string>
     */
    private function musicTags(Story $story): array
    {
        $query = trim(implode(' ', $story->tags));

        if ($query === '') {
            $query = 'dark ambient drone';
        }

        return SoundLibraryImporter::tagsFromQuery($query);
    }

    private function fitToWindow(string $source, float $duration): string
    {
        $duration = round(max(0.001, $duration), 3);
        $output = storage_path('app/tmp/music-beds/'.bin2hex(random_bytes(6)).'.wav');
        $this->files->ensureDirectoryExists(dirname($output));

        $this->run([
            $this->ffmpeg, '-nostdin', '-y', '-hide_banner',
            '-stream_loop', '-1',
            '-i', $source,
            '-t', sprintf('%.3f', $duration),
            '-ac', '2',
            '-ar', '48000',
            '-sample_fmt', 's16',
            $output,
        ]);

        return $output;
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
