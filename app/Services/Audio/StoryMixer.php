<?php

declare(strict_types=1);

namespace App\Services\Audio;

use App\DataObjects\NarrationWord;
use App\DataObjects\SoundCredit;
use App\DataObjects\Story;
use App\Services\Storage\TempSweeper;
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
        private TempSweeper $sweeper,
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
     *     lastTranscribedPhraseEnd: float,
     *     tailSeconds: float,
     *     sfxSkipped: list<array<string, mixed>>,
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

        $manifest = $this->manifest->load($slug);
        $cues = $manifest['cues'];
        $noAmbience = (bool) ($options['noAmbience'] ?? false);
        $noSfx = (bool) ($options['noSfx'] ?? false);
        $noMusic = (bool) ($options['noMusic'] ?? false) || ! $this->musicEnabled;
        $dryRun = (bool) ($options['dryRun'] ?? false);

        $duration = $this->processor->duration($narration);
        $lastTranscribedPhraseEnd = $this->ambience->lastTranscribedPhraseEnd($timings);
        $tailSeconds = $this->ambience->tailSeconds();
        $masterDuration = $this->ambience->expectedDuration($narration);
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

        // Camas derivadas: WAV de un solo uso que nadie más va a leer. Se borran pase lo que pase,
        // con la misma disciplina que mix.wav; si no, cada mezcla deja cientos de MB en tmp.
        $derived = [];

        // Efectos que se quedan fuera por no tener ancla. No es un detalle de log: es la diferencia
        // entre una historia con golpes y una sin ellos, y quien mezcla tiene que verla.
        $sfxSkipped = [];

        try {
            if (! $noAmbience) {
                $rows[] = [
                    'role' => AudioTrack::ROLE_AMBIENCE,
                    'startAt' => 0.0,
                    'endAt' => $masterDuration,
                    'gainDb' => 0.0,
                    'duckable' => true,
                    'file' => 'cama ('.count($this->manifest->ambienceByScene($cues)).' escenas)',
                ];

                if ($dryRun) {
                    // En simulación no se construye la cama, así que no hay clip real que acreditar:
                    // vale lo que declare sounds.json.
                    foreach ($cues as $cue) {
                        if (($cue['type'] ?? '') === 'ambience' && trim((string) ($cue['file'] ?? '')) !== '') {
                            $used[] = $cue;
                        }
                    }
                } else {
                    $bed = $this->ambience->build($story, $timings, $narration, $this->manifest->ambienceByScene($cues));
                    $audioTracks[] = $bed;
                    $derived[] = $bed->path;

                    foreach ($this->creditsFor($cues, $bed) as $credit) {
                        $used[] = $credit;
                    }
                }
            }

            if (! $noSfx) {
                $placed = $this->sfx->place(
                    $this->manifest->loadShots($slug),
                    $manifest['directedSfx'],
                    $this->manifest->overrides($cues, 'sfx'),
                    $this->narrationWords($timings),
                );
                $sfxSkipped = $placed['skipped'];

                foreach ($placed['tracks'] as $track) {
                    $audioTracks[] = $track;
                    $rows[] = $this->rowFromTrack($track);
                    $credit = $placed['credits'][$track->path] ?? null;
                    $matched = $this->cueByFile($cues, $track->path)
                        ?? ($credit instanceof SoundCredit ? $credit->toCue() : null);

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

                    foreach ($musicTracks as $track) {
                        $audioTracks[] = $track;
                        $rows[] = $this->rowFromTrack($track);
                        $derived[] = $track->path;

                        foreach ($this->creditsFor($cues, $track) as $credit) {
                            $used[] = $credit;
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
                    'lastTranscribedPhraseEnd' => $lastTranscribedPhraseEnd,
                    'tailSeconds' => $tailSeconds,
                    'sfxSkipped' => $sfxSkipped,
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
                'lastTranscribedPhraseEnd' => $lastTranscribedPhraseEnd,
                'tailSeconds' => $tailSeconds,
                'sfxSkipped' => $sfxSkipped,
                'measurement' => $this->master->measure($mastered['wav']),
            ];
        } finally {
            foreach ($derived as $path) {
                $this->sweeper->discard($path);
            }
        }
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
     * Acredita lo que de verdad suena en una pista, clip a clip.
     *
     * No se empareja por la ruta de la pista: la cama y la música son WAV derivados y no coinciden
     * con ningún cue. Se empareja por la ruta del clip de origen que declara el placer. Si hay cue
     * para ese fichero manda el cue, porque es quien trae la licencia del override; si no lo hay,
     * el clip lo resolvió el placer y la licencia viene en su propio crédito.
     *
     * @param  list<array<string, mixed>>  $cues
     * @return list<array<string, mixed>>
     */
    private function creditsFor(array $cues, AudioTrack $track): array
    {
        $credits = [];

        foreach ($track->credits as $credit) {
            $credits[] = $this->cueByFile($cues, $credit->file) ?? $credit->toCue();
        }

        return $credits;
    }

    /**
     * Empareja por ruta completa: dos clips con el mismo basename en directorios distintos son
     * clips distintos, y confundirlos acredita al autor equivocado.
     *
     * @param  list<array<string, mixed>>  $cues
     * @return array<string, mixed>|null
     */
    private function cueByFile(array $cues, string $path): ?array
    {
        $normalized = str_replace('\\', '/', $path);

        foreach ($cues as $cue) {
            $absolute = str_replace('\\', '/', $this->manifest->absoluteFile((string) ($cue['file'] ?? '')));

            if ($absolute !== '' && $absolute === $normalized) {
                return $cue;
            }
        }

        return null;
    }

    /**
     * Palabras del máster, planas y en orden, para que el colocador cuelgue cada golpe de la suya.
     * Solo las traen las frases que anclaron por texto: las demás no publican palabras.
     *
     * @param  array{sentences?: list<array<string, mixed>>}  $timings
     * @return list<NarrationWord>
     */
    private function narrationWords(array $timings): array
    {
        $words = [];

        foreach ($timings['sentences'] ?? [] as $sentence) {
            if (! is_array($sentence)) {
                continue;
            }

            foreach (is_array($sentence['words'] ?? null) ? $sentence['words'] : [] as $word) {
                if (is_array($word)) {
                    $words[] = NarrationWord::fromArray($word);
                }
            }
        }

        usort(
            $words,
            static fn (NarrationWord $left, NarrationWord $right): int => $left->start <=> $right->start,
        );

        return $words;
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
