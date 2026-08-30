<?php

declare(strict_types=1);

namespace App\Services\Pipeline;

use App\Contracts\JsonLlm;
use App\DataObjects\CoverageReport;
use App\DataObjects\Story as StoryScript;
use App\Models\Story;
use App\Services\Audio\AttributionWriter;
use App\Services\Audio\CoverageAuditor;
use App\Services\Audio\StoryMixer;
use App\Services\Audio\StorySoundManifest;
use App\Services\Image\ShotPlanner;
use App\Services\Storage\TempSweeper;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use InvalidArgumentException;
use JsonException;
use Throwable;

final class SoundStep
{
    private readonly string $outputDirectory;

    public function __construct(
        private StorySoundManifest $manifest,
        private CoverageAuditor $auditor,
        private StoryMixer $mixer,
        private AttributionWriter $attribution,
        private TempSweeper $sweeper,
        private JsonLlm $llm,
        private Filesystem $files,
        Repository $config,
    ) {
        $this->outputDirectory = storage_path('app/'.$config->get('stories.output_path'));
    }

    /**
     * @param  (callable(string, int, int): void)|null  $onProgress
     * @param  array{
     *     refresh?: bool,
     *     refresh_cue?: string|null,
     *     audit?: bool,
     *     mix?: bool,
     *     no_music?: bool,
     *     no_sfx?: bool,
     *     no_ambience?: bool,
     *     dry_run?: bool
     * }  $options
     * @return array<string, mixed>
     */
    public function run(Story $story, ?callable $onProgress = null, array $options = []): array
    {
        if ((bool) ($options['mix'] ?? false)) {
            return $this->runMix($story, $onProgress, $options);
        }

        return $this->runSounds($story, $onProgress, $options);
    }

    /**
     * @param  (callable(string, int, int): void)|null  $onProgress
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function runSounds(Story $story, ?callable $onProgress, array $options): array
    {
        $payload = $this->readStoryPayload($this->scriptPath($story->slug));

        if ($payload === null) {
            return ['ok' => false, 'error' => 'El JSON no contiene un guion de historia.'];
        }

        $slug = $story->slug;
        $script = StoryScript::fromArray($payload);
        $timings = $this->readTimings($slug);
        $refresh = (bool) ($options['refresh'] ?? false);
        $refreshCue = trim((string) ($options['refresh_cue'] ?? ''));
        $auditOnly = (bool) ($options['audit'] ?? false);

        if (! $auditOnly) {
            $directed = $this->assertDirectedShots($slug);

            if ($directed !== null) {
                return $directed;
            }
        }

        $this->progress($onProgress, $auditOnly ? 'auditoría' : 'resolución', 0, 1);

        try {
            if ($auditOnly) {
                if (! $this->manifest->exists($slug)) {
                    return ['ok' => false, 'error' => 'No hay sounds.json. Ejecuta story:sounds sin --audit primero.'];
                }

                $manifest = $this->manifest->load($slug);
            } else {
                $manifest = $this->manifest->sync(
                    $slug,
                    $script,
                    $timings,
                    $refresh,
                    $refreshCue !== '' ? $refreshCue : null,
                );
            }
        } catch (InvalidArgumentException $exception) {
            return ['ok' => false, 'error' => $exception->getMessage(), 'exception' => $exception];
        } catch (Throwable $exception) {
            return ['ok' => false, 'error' => $exception->getMessage(), 'exception' => $exception];
        }

        $this->progress($onProgress, $auditOnly ? 'auditoría' : 'resolución', 1, 1);

        $cues = $manifest['cues'];
        $coverage = null;

        if ($auditOnly) {
            $coverage = $this->auditor->audit(
                $script,
                $cues,
                $this->outputDirectory.DIRECTORY_SEPARATOR.$slug.DIRECTORY_SEPARATOR.'narration.wav',
            );
        }

        return [
            'ok' => $coverage instanceof CoverageReport ? $coverage->passed : true,
            'error' => $coverage instanceof CoverageReport && ! $coverage->passed
                ? 'Auditoría de cobertura: hay bloqueantes.'
                : null,
            'effect_count' => $this->effectCount($cues),
            'cues' => $cues,
            'path' => $this->manifest->pathFor($slug),
            'fallback_notice' => $this->llm->fallbackNotice(),
            'audit' => $auditOnly,
            'coverage' => $coverage,
        ];
    }

    /**
     * @param  (callable(string, int, int): void)|null  $onProgress
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function runMix(Story $story, ?callable $onProgress, array $options): array
    {
        $swept = $this->sweeper->sweep();
        $payload = $this->readStoryPayload($this->scriptPath($story->slug));

        if ($payload === null) {
            return ['ok' => false, 'error' => 'El JSON no contiene un guion de historia.', 'swept' => $swept];
        }

        $slug = $story->slug;
        $script = StoryScript::fromArray($payload);
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $directory = $this->outputDirectory.DIRECTORY_SEPARATOR.$slug;
        $narration = $directory.DIRECTORY_SEPARATOR.'narration.wav';
        $timingsPath = $directory.DIRECTORY_SEPARATOR.'timings.json';

        if (! $this->files->isFile($narration)) {
            return ['ok' => false, 'error' => 'No hay narration.wav. Ejecuta story:narrate primero.', 'swept' => $swept];
        }

        if (! $this->files->isFile($timingsPath)) {
            return ['ok' => false, 'error' => 'No hay timings.json. Ejecuta story:narrate primero.', 'swept' => $swept];
        }

        $this->progress($onProgress, 'mezcla', 0, 1);

        try {
            if (! $this->manifest->exists($slug)) {
                $this->manifest->sync($slug, $script, $this->readTimingsFile($timingsPath));
            }

            $cues = $this->manifest->load($slug)['cues'];
            $report = $this->auditor->audit($script, $cues, $narration);

            if (! $report->passed) {
                return [
                    'ok' => false,
                    'error' => 'Hay bloqueantes. No se mezcla.',
                    'coverage' => $report,
                    'swept' => $swept,
                ];
            }

            $result = $this->mixer->mix($slug, $script, [
                'noAmbience' => (bool) ($options['no_ambience'] ?? false),
                'noSfx' => (bool) ($options['no_sfx'] ?? false),
                'noMusic' => (bool) ($options['no_music'] ?? false),
                'dryRun' => $dryRun,
            ]);
        } catch (InvalidArgumentException $exception) {
            return ['ok' => false, 'error' => $exception->getMessage(), 'exception' => $exception, 'swept' => $swept];
        } catch (Throwable $exception) {
            return ['ok' => false, 'error' => $exception->getMessage(), 'exception' => $exception, 'swept' => $swept];
        }

        $this->progress($onProgress, 'mezcla', 1, 1);

        $credits = $dryRun ? [] : $this->attribution->cueCredits($result['usedCues']);
        $creditsPath = null;

        if (! $dryRun) {
            $creditsPath = $directory.DIRECTORY_SEPARATOR.'credits.txt';
            $this->attribution->write($creditsPath, $this->attribution->storyDocument($slug, $credits));
        }

        $measurement = $result['measurement'];

        return [
            'ok' => true,
            'master_seconds' => $result['duration'],
            'lufs' => is_array($measurement) ? $measurement['lufs'] : null,
            'true_peak' => is_array($measurement) ? $measurement['truePeak'] : null,
            'effect_count' => $this->effectCount($result['usedCues']),
            'coverage' => $report,
            'tracks' => $result['tracks'],
            'sfx_skipped' => $result['sfxSkipped'],
            'dry_run' => $dryRun,
            'measurement' => $measurement,
            'credits' => $credits,
            'credits_path' => $creditsPath,
            'wav' => $result['wav'],
            'mp3' => $result['mp3'],
            'last_transcribed_phrase_end' => $result['lastTranscribedPhraseEnd'],
            'tail_seconds' => $result['tailSeconds'],
            'swept' => $swept,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $cues
     */
    private function effectCount(array $cues): int
    {
        $count = 0;

        foreach ($cues as $cue) {
            $id = (string) ($cue['id'] ?? '');

            if (str_starts_with($id, 'sfx.') || ($cue['type'] ?? '') === 'sfx') {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @return array{ok: false, error: string}|null
     */
    private function assertDirectedShots(string $slug): ?array
    {
        $path = $this->outputDirectory.DIRECTORY_SEPARATOR.$slug.DIRECTORY_SEPARATOR.'shots.json';

        if (! $this->files->isFile($path)) {
            return ['ok' => false, 'error' => 'No hay shots.json. Ejecuta story:images primero.'];
        }

        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($this->files->get($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return ['ok' => false, 'error' => 'shots.json no es un JSON válido.'];
        }

        if (! isset($decoded['shots']) || ! is_array($decoded['shots']) || $decoded['shots'] === []) {
            return ['ok' => false, 'error' => 'shots.json no contiene planos.'];
        }

        $version = array_key_exists('plannerVersion', $decoded) ? (int) $decoded['plannerVersion'] : 0;

        if ($version < ShotPlanner::VERSION) {
            $seen = array_key_exists('plannerVersion', $decoded) ? (string) $version : 'ausente';

            return [
                'ok' => false,
                'error' => sprintf(
                    'shots.json tiene plannerVersion %s; hace falta %d. Regenera con story:images.',
                    $seen,
                    ShotPlanner::VERSION,
                ),
            ];
        }

        return null;
    }

    /**
     * @return array{scenes?: list<array<string, mixed>>, sentences?: list<array<string, mixed>>}
     */
    private function readTimings(string $slug): array
    {
        return $this->readTimingsFile(
            $this->outputDirectory.DIRECTORY_SEPARATOR.$slug.DIRECTORY_SEPARATOR.'timings.json',
        );
    }

    /**
     * @return array{scenes?: list<array<string, mixed>>, sentences?: list<array<string, mixed>>}
     */
    private function readTimingsFile(string $path): array
    {
        if (! $this->files->isFile($path)) {
            return [];
        }

        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($this->files->get($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        return $decoded;
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

    private function scriptPath(string $slug): string
    {
        return $this->outputDirectory.DIRECTORY_SEPARATOR.$slug.'.json';
    }

    /**
     * @param  (callable(string, int, int): void)|null  $onProgress
     */
    private function progress(?callable $onProgress, string $label, int $done, int $total): void
    {
        if ($onProgress !== null) {
            $onProgress($label, $done, $total);
        }
    }
}
