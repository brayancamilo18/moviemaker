<?php

declare(strict_types=1);

namespace App\Services\Audio;

use App\DataObjects\Story;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

final class StoryMixer
{
    private readonly string $storiesDirectory;

    private readonly bool $musicEnabled;

    private readonly float $climaxRatio;

    private readonly float $climaxTail;

    public function __construct(
        private StorySoundManifest $manifest,
        private AmbienceBuilder $ambience,
        private SfxPlacer $sfx,
        private MusicPlacer $music,
        private Mixer $mixer,
        private MasterProcessor $master,
        private LibraryClipProcessor $processor,
        private Filesystem $files,
        Repository $config,
    ) {
        $this->storiesDirectory = storage_path('app/'.$config->get('stories.output_path'));
        $this->musicEnabled = (bool) $config->get('stories.audio.music_enabled', false);
        $this->climaxRatio = (float) $config->get('stories.audio.music.climax_start_ratio', 0.75);
        $this->climaxTail = (float) $config->get('stories.audio.music.climax_tail_seconds', 8.0);
    }

    /**
     * @param  array{noAmbience?: bool, noSfx?: bool, noMusic?: bool, dryRun?: bool}  $options
     * @return array{
     *     dryRun: bool,
     *     tracks: list<array{role: string, startAt: float, endAt: ?float, gainDb: float, duckable: bool, file: string}>,
     *     usedCues: list<array<string, mixed>>,
     *     wav: ?string,
     *     mp3: ?string,
     *     duration: float,
     *     lastPhraseEnd: float,
     *     tailSeconds: float,
     *     measurement: ?array{lufs: float, truePeak: float, lra: float}
     * }
     */
    public function mix(string $slug, Story $story, array $options = []): array
    {
        $directory = $this->directory($slug);
        $narration = $directory.DIRECTORY_SEPARATOR.'narration.wav';
        $timingsPath = $directory.DIRECTORY_SEPARATOR.'timings.json';

        if (! $this->files->isFile($narration)) {
            throw new InvalidArgumentException('No hay narration.wav. Ejecuta story:narrate primero.');
        }

        if (! $this->files->isFile($timingsPath)) {
            throw new InvalidArgumentException('No hay timings.json. Ejecuta story:narrate primero.');
        }

        $timings = $this->readTimings($timingsPath);

        if (! $this->manifest->exists($slug)) {
            $this->manifest->sync($slug, $story, $timings);
        }

        $cues = $this->manifest->load($slug)['cues'];
        $noAmbience = (bool) ($options['noAmbience'] ?? false);
        $noSfx = (bool) ($options['noSfx'] ?? false);
        $noMusic = (bool) ($options['noMusic'] ?? false) || ! $this->musicEnabled;
        $dryRun = (bool) ($options['dryRun'] ?? false);

        $duration = $this->processor->duration($narration);
        $lastPhraseEnd = $this->ambience->lastPhraseEnd($timings);
        $tailSeconds = $this->ambience->tailSeconds();
        $masterDuration = $this->ambience->expectedDuration($timings);
        $rows = [[
            'role' => AudioTrack::ROLE_NARRATION,
            'startAt' => 0.0,
            'endAt' => $duration,
            'gainDb' => 0.0,
            'duckable' => false,
            'file' => $narration,
        ]];
        $used = [];
        $audioTracks = [
            new AudioTrack($narration, AudioTrack::ROLE_NARRATION, 0.0, null, 0.0, false, 0.0, 0.0),
        ];

        if (! $noAmbience) {
            foreach ($cues as $cue) {
                if (($cue['type'] ?? '') === 'ambience' && trim((string) ($cue['file'] ?? '')) !== '') {
                    $used[] = $cue;
                }
            }

            $rows[] = [
                'role' => AudioTrack::ROLE_AMBIENCE,
                'startAt' => 0.0,
                'endAt' => $masterDuration,
                'gainDb' => 0.0,
                'duckable' => true,
                'file' => 'cama ('.count($this->manifest->ambienceByScene($cues)).' escenas)',
            ];

            if (! $dryRun) {
                $audioTracks[] = $this->ambience->build($story, $timings, $this->manifest->ambienceByScene($cues));
            }
        }

        if (! $noSfx) {
            $sfxTracks = $this->sfx->place($story, $timings, $this->manifest->overrides($cues, 'sfx'));

            foreach ($sfxTracks as $track) {
                $audioTracks[] = $track;
                $rows[] = $this->rowFromTrack($track);
                $matched = $this->cueByFile($cues, $track->path);

                if ($matched !== null) {
                    $used[] = $matched;
                }
            }
        }

        if (! $noMusic) {
            if ($dryRun) {
                foreach ($this->musicPlan($timings, $cues) as $row) {
                    $rows[] = $row;
                    $matched = $this->cueByFile($cues, $row['file']);

                    if ($matched !== null) {
                        $used[] = $matched;
                    }
                }
            } else {
                $musicTracks = $this->music->place($story, $timings, $this->manifest->overrides($cues, 'music'));

                foreach ($musicTracks as $index => $track) {
                    $audioTracks[] = $track;
                    $rows[] = $this->rowFromTrack($track);
                    $cueId = $index === 0 && $track->startAt === 0.0 ? 'music.hook' : 'music.climax';

                    foreach ($cues as $cue) {
                        if (($cue['id'] ?? '') === $cueId) {
                            $used[] = $cue;
                        }
                    }
                }
            }
        }

        if ($dryRun) {
            return [
                'dryRun' => true,
                'tracks' => $rows,
                'usedCues' => $used,
                'wav' => null,
                'mp3' => null,
                'duration' => $masterDuration,
                'lastPhraseEnd' => $lastPhraseEnd,
                'tailSeconds' => $tailSeconds,
                'measurement' => null,
            ];
        }

        $raw = $directory.DIRECTORY_SEPARATOR.'mix.wav';
        $this->mixer->mix($audioTracks, $raw);
        $mastered = $this->master->process($raw, $directory, $masterDuration);
        $this->files->delete($raw);

        return [
            'dryRun' => false,
            'tracks' => $rows,
            'usedCues' => $used,
            'wav' => $mastered['wav'],
            'mp3' => $mastered['mp3'],
            'duration' => $masterDuration,
            'lastPhraseEnd' => $lastPhraseEnd,
            'tailSeconds' => $tailSeconds,
            'measurement' => $this->master->measure($mastered['wav']),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $cues
     * @return list<array{role: string, startAt: float, endAt: ?float, gainDb: float, duckable: bool, file: string}>
     */
    private function musicPlan(array $timings, array $cues): array
    {
        $overrides = $this->manifest->overrides($cues, 'music');
        $total = $this->totalDuration($timings);
        $firstEnd = $this->firstSceneEnd($timings);
        $rows = [];

        if ($firstEnd !== null && $firstEnd > 0.0 && isset($overrides['music.hook'])) {
            $rows[] = [
                'role' => AudioTrack::ROLE_MUSIC,
                'startAt' => 0.0,
                'endAt' => $firstEnd,
                'gainDb' => $overrides['music.hook']['gainDb'],
                'duckable' => true,
                'file' => $overrides['music.hook']['path'],
            ];
        }

        $climaxStart = round($total * $this->climaxRatio, 3);
        $climaxEnd = round($total - $this->climaxTail, 3);

        if ($total > 0.0 && $climaxEnd > $climaxStart && isset($overrides['music.climax'])) {
            $rows[] = [
                'role' => AudioTrack::ROLE_MUSIC,
                'startAt' => $climaxStart,
                'endAt' => $climaxEnd,
                'gainDb' => $overrides['music.climax']['gainDb'],
                'duckable' => true,
                'file' => $overrides['music.climax']['path'],
            ];
        }

        return $rows;
    }

    /**
     * @return array{role: string, startAt: float, endAt: ?float, gainDb: float, duckable: bool, file: string}
     */
    private function rowFromTrack(AudioTrack $track): array
    {
        return [
            'role' => $track->role,
            'startAt' => $track->startAt,
            'endAt' => $track->endAt,
            'gainDb' => $track->gainDb,
            'duckable' => $track->duckable,
            'file' => $track->path,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $cues
     * @return array<string, mixed>|null
     */
    private function cueByFile(array $cues, string $path): ?array
    {
        $normalized = str_replace('\\', '/', $path);

        foreach ($cues as $cue) {
            $absolute = str_replace('\\', '/', $this->manifest->absoluteFile((string) ($cue['file'] ?? '')));

            if ($absolute !== '' && ($absolute === $normalized || basename($absolute) === basename($normalized))) {
                return $cue;
            }
        }

        return null;
    }

    /**
     * @return array{scenes?: list<array<string, mixed>>, sentences?: list<array<string, mixed>>}
     */
    private function readTimings(string $path): array
    {
        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($this->files->get($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('timings.json no es un JSON válido.', previous: $exception);
        }

        return $decoded;
    }

    /**
     * @param  array{scenes?: list<array<string, mixed>>}  $timings
     */
    private function totalDuration(array $timings): float
    {
        $end = 0.0;

        foreach ($timings['scenes'] ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }

            $end = max($end, (float) ($row['end'] ?? 0), (float) ($row['start'] ?? 0) + (float) ($row['duration'] ?? 0));
        }

        return round($end, 3);
    }

    /**
     * @param  array{scenes?: list<array<string, mixed>>}  $timings
     */
    private function firstSceneEnd(array $timings): ?float
    {
        $first = null;

        foreach ($timings['scenes'] ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }

            $order = (int) ($row['order'] ?? 0);

            if ($first === null || $order < $first['order']) {
                $first = ['order' => $order, 'end' => (float) ($row['end'] ?? 0)];
            }
        }

        return $first !== null && $first['end'] > 0 ? round($first['end'], 3) : null;
    }

    private function directory(string $slug): string
    {
        $slug = trim($slug);

        if ($slug === '' || basename($slug) !== $slug) {
            throw new InvalidArgumentException('El slug de la historia no es válido.');
        }

        return $this->storiesDirectory.DIRECTORY_SEPARATOR.$slug;
    }
}
