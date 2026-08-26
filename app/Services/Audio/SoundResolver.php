<?php

declare(strict_types=1);

namespace App\Services\Audio;

use App\DataObjects\ResolvedSound;
use App\DataObjects\Story;
use App\Exceptions\FreesoundException;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Psr\Log\LoggerInterface;
use Throwable;

final class SoundResolver
{
    private const MAX_ATTEMPTS_PER_LEVEL = 3;

    private const MAX_ATTEMPTS_PER_SIGNAL = 8;

    private const CIRCUIT_FAILURE_THRESHOLD = 3;

    private readonly float $cacheThreshold;

    private readonly int $searchCandidates;

    private readonly int $verifyAttempts;

    private readonly float $signalBudgetSeconds;

    private readonly float $storyBudgetSeconds;

    private float $storyStartedAt = 0.0;

    private float $signalStartedAt = 0.0;

    private int $signalAttempts = 0;

    private int $consecutiveProviderFailures = 0;

    private bool $circuitOpen = false;

    private bool $storyBudgetWarned = false;

    private int $synthSequence = 0;

    public function __construct(
        private AudioLibrary $library,
        private SoundLibraryImporter $importer,
        private LibraryClipProcessor $processor,
        private SoundVerifier $verifier,
        private SyntheticSound $synthesizer,
        private SoundCategorizer $categorizer,
        private QueryLadder $ladder,
        private Filesystem $files,
        private LoggerInterface $logger,
        Repository $config,
    ) {
        $this->cacheThreshold = (float) $config->get('stories.audio.cache_match_threshold', 0.6);
        $this->searchCandidates = max(1, (int) $config->get('stories.audio.resolve.search_candidates', 8));
        $this->verifyAttempts = max(1, (int) $config->get('stories.audio.resolve.verify_attempts', self::MAX_ATTEMPTS_PER_LEVEL));
        $this->signalBudgetSeconds = max(0.0, (float) $config->get('stories.audio.resolve_budget_seconds', 20));
        $this->storyBudgetSeconds = max(0.0, (float) $config->get('stories.audio.resolve_total_budget_seconds', 600));
    }

    /**
     * @return list<array{type: string, query: string, tags: list<string>, role: string, sceneOrder?: int}>
     */
    public function signalsFor(Story $story): array
    {
        $signals = [];
        $ambience = $this->ambienceQuery($story);
        $ambienceTags = $this->storyTags($story);

        if ($ambience !== '') {
            $signals[] = [
                'type' => 'ambience',
                'query' => $ambience,
                'tags' => $ambienceTags !== [] ? $ambienceTags : SoundLibraryImporter::tagsFromQuery($ambience),
                'role' => 'bed',
            ];
        }

        foreach ($story->scenes as $scene) {
            foreach ($scene->soundEffectSpecs() as $effect) {
                $signals[] = [
                    'type' => 'sfx',
                    'query' => $effect->query,
                    'tags' => $effect->tags !== [] ? $effect->tags : SoundLibraryImporter::tagsFromQuery($effect->query),
                    'role' => 'scene',
                    'sceneOrder' => $scene->order,
                ];
            }
        }

        return $signals;
    }

    /**
     * @param  list<string>  $tags
     * @param  list<string>  $exclude
     */
    public function resolve(array $tags, string $query, string $type, float $minDuration = 0, array $exclude = []): ResolvedSound
    {
        $this->beginStoryClock();
        $this->signalStartedAt = microtime(true);
        $this->signalAttempts = 0;

        $type = strtolower(trim($type));
        $query = trim($query);
        $tags = $this->normalizeTags($tags);
        $exclude = array_values(array_filter(array_map(static fn (mixed $item): string => trim((string) $item), $exclude)));

        if ($tags === []) {
            $tags = SoundLibraryImporter::tagsFromQuery($query);
        }

        try {
            AudioDuration::range($type);

            $category = $this->categorizer->categorize($tags, $query);
            $fromCache = $this->fromCache($tags, $type, $minDuration, $exclude);

            if ($fromCache instanceof ResolvedSound) {
                return $fromCache;
            }

            if ($this->canUseNetwork()) {
                foreach ($this->ladder->levels($query, $tags, $category) as $step) {
                    if (! $this->canUseNetwork()) {
                        break;
                    }

                    $fromDownload = $this->fromDownload($tags, $step['query'], $type, $minDuration, $exclude, $step['level']);

                    if ($fromDownload instanceof ResolvedSound) {
                        return $fromDownload;
                    }
                }
            }

            return $this->finishLocally($tags, $query, $type, $minDuration, $exclude);
        } catch (Throwable $exception) {
            $this->logger->warning('La resolución de audio continuó tras un error.', [
                'type' => $type,
                'query' => $query,
                'error' => $exception->getMessage(),
            ]);

            return $this->finishLocally($tags, $query, $type, $minDuration, $exclude);
        }
    }

    /**
     * @param  list<string>  $tags
     * @param  list<string>  $exclude
     */
    private function fromCache(array $tags, string $type, float $minDuration, array $exclude): ?ResolvedSound
    {
        $ranked = $this->rankLibrary($tags, $type, $minDuration, $exclude, $this->cacheThreshold);

        foreach ($ranked as $item) {
            if ($this->accepted($item['path'], $type, $minDuration)) {
                return $this->fromClip($item['clip'], ResolvedSound::SOURCE_CACHE, $item['score']);
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $tags
     * @param  list<string>  $exclude
     */
    private function fromDownload(array $tags, string $query, string $type, float $minDuration, array $exclude, int $ladderLevel): ?ResolvedSound
    {
        if (! $this->canUseNetwork()) {
            return null;
        }

        try {
            $candidates = $this->importer->search($type, $query, $this->searchCandidates);
            $this->recordProviderSuccess();
        } catch (Throwable $exception) {
            $this->recordProviderFailure($exception);
            $this->logger->warning('Freesound no disponible al resolver audio.', [
                'type' => $type,
                'query' => $query,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }

        if ($candidates === []) {
            return null;
        }

        $maxDownloads = 0;

        foreach ($candidates as $sound) {
            $maxDownloads = max($maxDownloads, (int) ($sound['downloads'] ?? 0));
        }

        $scored = [];

        foreach ($candidates as $sound) {
            $scored[] = [
                'sound' => $sound,
                'score' => $this->candidateScore($sound, $tags, $type, $maxDownloads),
            ];
        }

        usort(
            $scored,
            static fn (array $left, array $right): int => $right['score'] <=> $left['score'],
        );

        $levelAttempts = 0;
        $perLevel = min($this->verifyAttempts, self::MAX_ATTEMPTS_PER_LEVEL);

        foreach ($scored as $item) {
            if ($levelAttempts >= $perLevel || ! $this->canUseNetwork()) {
                break;
            }

            /** @var array{id: int, name: string, author: string, license: string, duration: float, rating: float, downloads?: int, tags: list<string>, previewUrl: string, sourceUrl: string} $sound */
            $sound = $item['sound'];
            $existing = $this->library->findBySourceId((int) $sound['id']);

            if (is_array($existing) && $this->excluded($existing, $exclude)) {
                continue;
            }

            $extraTags = array_values(array_unique([...$tags, ...SoundLibraryImporter::tagsFromQuery($query)]));
            $this->signalAttempts++;
            $levelAttempts++;

            try {
                $result = $this->importer->ingest($sound, $type, $extraTags);
                $this->recordProviderSuccess();
            } catch (Throwable $exception) {
                $this->recordProviderFailure($exception);
                $this->logger->warning('Fallo al descargar un candidato de Freesound.', [
                    'id' => $sound['id'],
                    'error' => $exception->getMessage(),
                ]);

                continue;
            }

            $clip = match ($result['status']) {
                'added' => is_array($result['clip'] ?? null) ? $result['clip'] : null,
                'skipped' => $this->library->findBySourceId((int) $sound['id']),
                default => null,
            };

            if (! is_array($clip) || $this->excluded($clip, $exclude)) {
                continue;
            }

            $path = $this->library->absolutePath((string) ($clip['file'] ?? ''));

            if (! $this->accepted($path, $type, $minDuration)) {
                continue;
            }

            return $this->fromClip($clip, ResolvedSound::SOURCE_DOWNLOAD, (float) $item['score'], ladderLevel: $ladderLevel);
        }

        return null;
    }

    /**
     * @param  list<string>  $tags
     * @param  list<string>  $exclude
     */
    private function fromFallback(array $tags, string $type, float $minDuration, array $exclude): ?ResolvedSound
    {
        $ranked = $this->rankLibrary($tags, $type, $minDuration, $exclude, 0.0001);

        foreach ($ranked as $item) {
            if ($this->accepted($item['path'], $type, $minDuration)) {
                return $this->fromClip($item['clip'], ResolvedSound::SOURCE_FALLBACK, $item['score']);
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $tags
     * @param  list<string>  $exclude
     */
    private function finishLocally(array $tags, string $query, string $type, float $minDuration, array $exclude): ResolvedSound
    {
        try {
            $fromFallback = $this->fromFallback($tags, $type, $minDuration, $exclude);

            if ($fromFallback instanceof ResolvedSound) {
                return $fromFallback;
            }

            return $this->fromSynth($tags, $query, $type, $minDuration);
        } catch (Throwable $exception) {
            $this->logger->warning('El respaldo local de audio también falló.', [
                'type' => $type,
                'query' => $query,
                'error' => $exception->getMessage(),
            ]);

            return $this->emptySynth();
        }
    }

    private function beginStoryClock(): void
    {
        if ($this->storyStartedAt <= 0.0) {
            $this->storyStartedAt = microtime(true);
        }
    }

    private function canUseNetwork(): bool
    {
        if ($this->circuitOpen) {
            return false;
        }

        if ($this->storyBudgetExceeded()) {
            $this->warnStoryBudgetOnce();

            return false;
        }

        if ($this->signalBudgetExceeded()) {
            return false;
        }

        return $this->signalAttempts < self::MAX_ATTEMPTS_PER_SIGNAL;
    }

    private function storyBudgetExceeded(): bool
    {
        if ($this->storyStartedAt <= 0.0) {
            return false;
        }

        return (microtime(true) - $this->storyStartedAt) >= $this->storyBudgetSeconds;
    }

    private function signalBudgetExceeded(): bool
    {
        if ($this->signalStartedAt <= 0.0) {
            return false;
        }

        return (microtime(true) - $this->signalStartedAt) >= $this->signalBudgetSeconds;
    }

    private function warnStoryBudgetOnce(): void
    {
        if ($this->storyBudgetWarned) {
            return;
        }

        $this->storyBudgetWarned = true;
        $this->logger->warning('Presupuesto de resolución de la historia agotado; el resto se resuelve sin red.');
    }

    private function recordProviderSuccess(): void
    {
        $this->consecutiveProviderFailures = 0;
    }

    private function recordProviderFailure(Throwable $exception): void
    {
        if (! $this->isProviderFailure($exception)) {
            return;
        }

        $this->consecutiveProviderFailures++;

        if ($this->circuitOpen || $this->consecutiveProviderFailures < self::CIRCUIT_FAILURE_THRESHOLD) {
            return;
        }

        $this->circuitOpen = true;
        $this->logger->warning('Freesound marcado como caído tras tres fallos consecutivos; el resto de la ejecución no tocará la red.');
    }

    private function isProviderFailure(Throwable $exception): bool
    {
        $current = $exception;

        while ($current instanceof Throwable) {
            if ($current instanceof ConnectionException) {
                return true;
            }

            if ($current instanceof RequestException) {
                $status = $current->response->status();

                return $status >= 500 || $status === 408;
            }

            $current = $current->getPrevious();
        }

        if ($exception instanceof FreesoundException) {
            $message = $exception->getMessage();

            if (preg_match('/HTTP 5\d\d/', $message) === 1) {
                return true;
            }

            return str_contains(mb_strtolower($message), 'conectar')
                || str_contains(mb_strtolower($message), 'timeout')
                || str_contains(mb_strtolower($message), 'timed out');
        }

        return false;
    }

    /**
     * @param  list<string>  $tags
     */
    private function fromSynth(array $tags, string $query, string $type, float $minDuration): ResolvedSound
    {
        $category = $this->categorizer->categorize($tags, $query);
        $profile = $this->synthProfileFor($category, $query, $tags, $type);

        if ($type === 'sfx' && ! $this->synthesizer->isCredibleEffect($profile)) {
            $reason = $this->omittedEffectReason($category, $profile);
            $this->logger->warning($reason, [
                'query' => $query,
                'tags' => $tags,
                'category' => $category,
                'profile' => $profile,
            ]);

            return $this->emptySynth($reason);
        }

        if ($type !== 'sfx') {
            $profile = $this->synthesizer->ambienceProfile($profile);
        }

        try {
            $seed = $this->nextSynthSeed($query, $tags);
            $duration = $this->synthesizer->isCredibleEffect($profile)
                ? $this->effectDuration($profile)
                : max($minDuration, 30.0);
            $generated = $this->synthesizer->generate($profile, $duration, $seed);
            $clip = $this->indexSynth($type, $query, $tags, $generated, $seed);
            $path = $this->library->absolutePath((string) $clip['file']);

            return $this->fromClip($clip, ResolvedSound::SOURCE_SYNTH, 0.0, $path);
        } catch (Throwable $exception) {
            $this->logger->warning('No se pudo sintetizar el audio.', [
                'type' => $type,
                'query' => $query,
                'profile' => $profile,
                'error' => $exception->getMessage(),
            ]);

            return $this->emptySynth();
        }
    }

    /**
     * @param  list<string>  $tags
     */
    private function synthProfileFor(?string $categorySlug, string $query, array $tags, string $type): string
    {
        if ($categorySlug !== null) {
            $category = $this->categorizer->find($categorySlug);

            if ($category !== null) {
                return $this->synthesizer->normalizeProfile($category['synthProfile']);
            }
        }

        $inferred = $this->synthesizer->inferProfile($query, $tags);

        if ($type === 'sfx' && ! $this->synthesizer->isCredibleEffect($inferred)) {
            return SyntheticSound::PROFILE_NONE;
        }

        return $inferred;
    }

    private function omittedEffectReason(?string $category, string $profile): string
    {
        if ($profile === SyntheticSound::PROFILE_NONE && $category !== null) {
            return "Efecto omitido: la categoría {$category} tiene synthProfile=none; un sonido genérico sacaría al espectador de la historia.";
        }

        if ($profile === SyntheticSound::PROFILE_NONE) {
            return 'Efecto omitido: no hay síntesis creíble (impact/friction) para esta señal.';
        }

        return "Efecto omitido: el perfil sintético '{$profile}' no es creíble para un efecto.";
    }

    private function effectDuration(string $profile): float
    {
        return $profile === SyntheticSound::PROFILE_FRICTION ? 0.95 : 0.55;
    }

    /**
     * @param  list<string>  $tags
     */
    private function nextSynthSeed(string $query, array $tags): int
    {
        $this->synthSequence++;

        return (int) sprintf('%u', crc32($this->synthSequence.':'.$query.':'.implode(',', $tags)));
    }

    private function emptySynth(?string $omitReason = null): ResolvedSound
    {
        return new ResolvedSound(
            path: '',
            source: ResolvedSound::SOURCE_SYNTH,
            lufs: 0.0,
            attributionRequired: false,
            author: null,
            license: null,
            sourceUrl: null,
            score: 0.0,
            ladderLevel: null,
            omitReason: $omitReason,
        );
    }

    private function accepted(string $path, string $type, float $minDuration): bool
    {
        try {
            $result = $this->verifier->verify($path, $type, $minDuration);
        } catch (Throwable $exception) {
            $this->logger->info('Audio descartado.', [
                'path' => $path,
                'failures' => [$exception->getMessage()],
            ]);

            return false;
        }

        if ($result->passed) {
            return true;
        }

        $this->logger->info('Audio descartado.', [
            'path' => $path,
            'failures' => $result->failures,
        ]);

        return false;
    }

    /**
     * @param  list<string>  $tags
     * @param  list<string>  $exclude
     * @return list<array{clip: array<string, mixed>, path: string, score: float}>
     */
    private function rankLibrary(array $tags, string $type, float $minDuration, array $exclude, float $minScore): array
    {
        $ranked = [];

        foreach ($this->library->filter($type) as $clip) {
            if ($type === 'sfx' && $this->isSynthClip($clip)) {
                continue;
            }

            if ($this->excluded($clip, $exclude)) {
                continue;
            }

            $duration = (float) ($clip['duration'] ?? 0);

            if ($duration < $minDuration) {
                continue;
            }

            $path = $this->library->absolutePath((string) ($clip['file'] ?? ''));
            $score = $this->tagOverlap($tags, is_array($clip['tags'] ?? null) ? $clip['tags'] : []);

            if ($score < $minScore) {
                continue;
            }

            $ranked[] = [
                'clip' => $clip,
                'path' => $path,
                'score' => $score,
            ];
        }

        usort(
            $ranked,
            static fn (array $left, array $right): int => $right['score'] <=> $left['score'],
        );

        return $ranked;
    }

    /**
     * @param  array{id: int, name: string, author: string, license: string, duration: float, rating: float, downloads?: int, tags: list<string>}  $sound
     * @param  list<string>  $tags
     */
    private function candidateScore(array $sound, array $tags, string $type, int $maxDownloads): float
    {
        $overlap = $this->tagOverlap($tags, $sound['tags']);
        $duration = $this->durationFit($type, (float) $sound['duration']);
        $rating = min(1.0, max(0.0, (float) $sound['rating'] / 5.0));
        $downloads = $maxDownloads > 0
            ? log(1.0 + (int) ($sound['downloads'] ?? 0)) / log(1.0 + $maxDownloads)
            : 0.0;

        return round(0.4 * $overlap + 0.25 * $duration + 0.2 * $rating + 0.15 * $downloads, 4);
    }

    private function durationFit(string $type, float $duration): float
    {
        if ($duration <= 0) {
            return 0.0;
        }

        if ($type === 'ambience') {
            return min(1.0, $duration / 120.0);
        }

        if ($type === 'sfx') {
            if ($duration <= 15.0) {
                return 1.0;
            }

            return max(0.0, 1.0 - (($duration - 15.0) / 15.0));
        }

        return min(1.0, $duration / 180.0);
    }

    /**
     * @param  list<string>  $requested
     * @param  list<mixed>  $available
     */
    private function tagOverlap(array $requested, array $available): float
    {
        $requested = $this->normalizeTags($requested);
        $available = $this->normalizeTags($available);

        if ($requested === []) {
            return 0.0;
        }

        return count(array_intersect($requested, $available)) / count($requested);
    }

    /**
     * @param  array<string, mixed>  $clip
     * @param  list<string>  $exclude
     */
    private function excluded(array $clip, array $exclude): bool
    {
        if ($exclude === []) {
            return false;
        }

        $file = (string) ($clip['file'] ?? '');
        $path = $this->library->absolutePath($file);
        $haystack = [$file, $path, basename($file), (string) ($clip['source_id'] ?? '')];

        foreach ($exclude as $item) {
            if (in_array($item, $haystack, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $clip
     */
    private function isSynthClip(array $clip): bool
    {
        $file = basename((string) ($clip['file'] ?? ''));
        $sourceId = (string) ($clip['source_id'] ?? '');

        return str_starts_with($file, 'synth-') || str_starts_with($sourceId, 'synth-');
    }

    /**
     * @param  array<string, mixed>  $clip
     */
    private function fromClip(array $clip, string $source, float $score, ?string $path = null, ?int $ladderLevel = null): ResolvedSound
    {
        $file = (string) ($clip['file'] ?? '');
        $author = trim((string) ($clip['author'] ?? ''));
        $license = trim((string) ($clip['license'] ?? ''));
        $sourceUrl = trim((string) ($clip['source_url'] ?? ''));

        return new ResolvedSound(
            path: $path ?? $this->library->absolutePath($file),
            source: $source,
            lufs: (float) ($clip['lufs'] ?? 0),
            attributionRequired: (bool) ($clip['attribution_required'] ?? false),
            author: $author !== '' ? $author : null,
            license: $license !== '' ? $license : null,
            sourceUrl: $sourceUrl !== '' ? $sourceUrl : null,
            score: $score,
            ladderLevel: $ladderLevel,
        );
    }

    /**
     * @param  list<string>  $tags
     * @return array<string, mixed>
     */
    private function indexSynth(string $type, string $query, array $tags, string $generated, int $seed): array
    {
        $this->processor->assertAudio($generated);

        $hash = sha1($type.':'.mb_strtolower(trim($query)).':'.$seed);
        $filename = 'synth-'.$hash.'.wav';
        $relative = $type.'/'.$filename;
        $destination = $this->library->directoryFor($type).DIRECTORY_SEPARATOR.$filename;

        $this->files->ensureDirectoryExists(dirname($destination));

        $this->files->copy($generated, $destination);

        $sha1 = sha1_file($destination);

        if (is_string($sha1) && $sha1 !== '') {
            foreach ($this->library->filter($type) as $clip) {
                if (($clip['sha1'] ?? '') === $sha1) {
                    return $clip;
                }
            }
        }

        $duration = $this->processor->duration($destination);
        $lufs = $this->processor->integratedLufs($destination);
        $clip = [
            'file' => $relative,
            'type' => $type,
            'tags' => $this->normalizeTags([...$tags, ...SoundLibraryImporter::tagsFromQuery($query)]),
            'duration' => $duration,
            'loopable' => $this->processor->isLoopable($destination, $duration),
            'source_id' => 'synth-'.$hash,
            'source_url' => 'internal://synth/'.$hash,
            'author' => 'horror-studio',
            'license' => 'internal',
            'attribution_required' => false,
            'lufs' => $lufs,
            'sha1' => is_string($sha1) ? $sha1 : str_repeat('0', 40),
        ];

        $this->library->add($clip);

        return $clip;
    }

    /**
     * @param  list<mixed>  $tags
     * @return list<string>
     */
    private function normalizeTags(array $tags): array
    {
        $normalized = [];

        foreach ($tags as $tag) {
            $value = mb_strtolower(trim((string) $tag));

            if ($value !== '' && ! in_array($value, $normalized, true)) {
                $normalized[] = $value;
            }
        }

        return $normalized;
    }

    /**
     * @return list<string>
     */
    private function storyTags(Story $story): array
    {
        $parts = $story->tags;
        $bible = $story->visualBible;

        if ($bible !== null) {
            $parts[] = $bible->weather;
            $parts[] = $bible->timeOfDay;
            $parts[] = $bible->setting;
        }

        $tags = [];

        foreach ($parts as $part) {
            foreach (SoundLibraryImporter::tagsFromQuery((string) $part) as $tag) {
                if (! in_array($tag, $tags, true)) {
                    $tags[] = $tag;
                }
            }
        }

        return $tags;
    }

    private function ambienceQuery(Story $story): string
    {
        $tags = $this->storyTags($story);

        if ($tags !== []) {
            return implode(' ', $tags);
        }

        return 'wind howling night';
    }
}
