<?php

declare(strict_types=1);

namespace App\Services\Audio;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class CoreKitInstaller
{
    private readonly int $searchCandidates;

    public function __construct(
        private SoundCategorizer $categorizer,
        private FreesoundClient $freesound,
        private AudioLibrary $library,
        private LibraryClipProcessor $processor,
        private SoundVerifier $verifier,
        private Filesystem $files,
        Repository $config,
    ) {
        $this->searchCandidates = max(1, (int) $config->get('stories.audio.core_search_candidates', 12));
    }

    /**
     * @param  list<string>  $only
     * @return list<array{slug: string, status: string, file: string, reason: string}>
     */
    public function install(array $only = [], bool $force = false): array
    {
        $results = [];

        foreach ($this->selected($only) as $category) {
            $results[] = $this->installCategory($category, $force);
        }

        return $results;
    }

    /**
     * @param  list<string>  $only
     * @return list<array{slug: string, passed: bool, file: string, reason: string}>
     */
    public function verify(array $only = []): array
    {
        $results = [];

        foreach ($this->selected($only) as $category) {
            $relative = $this->relativeFile($category);
            $path = $this->library->absolutePath($relative);
            $minDuration = AudioDuration::range($category['type'])['min'];
            $check = $this->verifier->verify($path, $category['type'], $minDuration);

            $results[] = [
                'slug' => $category['slug'],
                'passed' => $check->passed,
                'file' => $relative,
                'reason' => $check->passed ? '' : implode(' ', $check->failures),
            ];
        }

        return $results;
    }

    /**
     * @param  list<string>  $only
     * @return list<array{slug: string, keywords: list<string>, type: string, curatedQuery: string, coreFile: string, synthProfile: string}>
     */
    private function selected(array $only): array
    {
        if ($only === []) {
            return $this->categorizer->all();
        }

        $selected = [];

        foreach ($only as $slug) {
            $category = $this->categorizer->find($slug);

            if ($category === null) {
                throw new InvalidArgumentException("No existe la categoría '{$slug}'.");
            }

            $selected[] = $category;
        }

        return $selected;
    }

    /**
     * @param  array{slug: string, keywords: list<string>, type: string, curatedQuery: string, coreFile: string, synthProfile: string}  $category
     * @return array{slug: string, status: string, file: string, reason: string}
     */
    private function installCategory(array $category, bool $force): array
    {
        $relative = $this->relativeFile($category);
        $destination = $this->library->absolutePath($relative);

        if (! $force && $this->files->isFile($destination) && $this->files->size($destination) > 0) {
            $this->indexExisting($category, $destination);

            return [
                'slug' => $category['slug'],
                'status' => 'kept',
                'file' => $relative,
                'reason' => 'ya estaba en disco',
            ];
        }

        try {
            $candidates = $this->freesound->search(
                $category['curatedQuery'],
                $category['type'],
                $this->searchCandidates,
            );
        } catch (Throwable $exception) {
            return $this->failed($category, $exception->getMessage());
        }

        if ($candidates === []) {
            return $this->failed($category, 'Freesound no devolvió candidatos CC0/Attribution.');
        }

        usort(
            $candidates,
            function (array $left, array $right) use ($category): int {
                $relevance = $this->relevance($right, $category) <=> $this->relevance($left, $category);

                if ($relevance !== 0) {
                    return $relevance;
                }

                $rating = ((float) $right['rating']) <=> ((float) $left['rating']);

                if ($rating !== 0) {
                    return $rating;
                }

                return ((int) ($right['downloads'] ?? 0)) <=> ((int) ($left['downloads'] ?? 0));
            },
        );

        $workDir = storage_path('app/tmp/audio-core-'.bin2hex(random_bytes(6)));
        $this->files->ensureDirectoryExists($workDir);

        try {
            foreach ($candidates as $sound) {
                $chosen = $this->tryCandidate($sound, $category, $workDir, $destination);

                if ($chosen !== null) {
                    return $chosen;
                }
            }
        } finally {
            $this->files->deleteDirectory($workDir);
        }

        return $this->failed($category, 'Ningún candidato pasó el verificador.');
    }

    /**
     * @param  array{id: int, name: string, author: string, license: string, duration: float, rating: float, downloads?: int, tags: list<string>, previewUrl: string, sourceUrl: string}  $sound
     * @param  array{slug: string, keywords: list<string>, type: string, curatedQuery: string, coreFile: string, synthProfile: string}  $category
     * @return array{slug: string, status: string, file: string, reason: string}|null
     */
    private function tryCandidate(array $sound, array $category, string $workDir, string $destination): ?array
    {
        $previewPath = $workDir.DIRECTORY_SEPARATOR.'preview-'.$sound['id'].'.mp3';
        $converted = $workDir.DIRECTORY_SEPARATOR.'converted-'.$sound['id'].'.wav';

        try {
            $this->files->put($previewPath, $this->freesound->downloadPreview($sound['previewUrl']));
            $this->processor->assertAudio($previewPath);
            $this->processor->convertToLibraryWav($previewPath, $converted);

            $minDuration = AudioDuration::range($category['type'])['min'];
            $check = $this->verifier->verify($converted, $category['type'], $minDuration);

            if (! $check->passed) {
                return null;
            }

            $this->files->ensureDirectoryExists(dirname($destination));
            $this->files->delete($destination);
            $this->files->copy($converted, $destination);
            $this->indexClip($category, $sound, $destination);

            return [
                'slug' => $category['slug'],
                'status' => 'downloaded',
                'file' => $this->relativeFile($category),
                'reason' => $sound['name'].' (#'.$sound['id'].')',
            ];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array{slug: string, keywords: list<string>, type: string, curatedQuery: string, coreFile: string, synthProfile: string}  $category
     * @param  array{id: int, name: string, author: string, license: string, duration: float, rating: float, downloads?: int, tags: list<string>, previewUrl: string, sourceUrl: string}  $sound
     */
    private function indexClip(array $category, array $sound, string $path): void
    {
        $sha1 = sha1_file($path);

        if (! is_string($sha1) || $sha1 === '') {
            throw new RuntimeException('No se pudo calcular el sha1 de '.$path.'.');
        }

        $duration = $this->processor->duration($path);
        $lufs = $this->processor->integratedLufs($path);
        $tags = $this->mergeTags([
            ...$category['keywords'],
            $category['slug'],
            ...$sound['tags'],
            ...SoundLibraryImporter::tagsFromQuery($category['curatedQuery']),
        ]);

        $this->library->add([
            'file' => $this->relativeFile($category),
            'type' => $category['type'],
            'tags' => $tags,
            'duration' => $duration,
            'loopable' => $this->processor->isLoopable($path, $duration),
            'source_id' => (string) $sound['id'],
            'source_url' => $sound['sourceUrl'],
            'author' => $sound['author'],
            'license' => $sound['license'],
            'attribution_required' => $sound['license'] === FreesoundClient::LICENSE_ATTRIBUTION,
            'lufs' => $lufs,
            'sha1' => $sha1,
            'is_core' => true,
        ]);
    }

    /**
     * @param  array{slug: string, keywords: list<string>, type: string, curatedQuery: string, coreFile: string, synthProfile: string}  $category
     */
    private function indexExisting(array $category, string $path): void
    {
        foreach ($this->library->clips() as $clip) {
            if ((string) ($clip['file'] ?? '') === $this->relativeFile($category)) {
                return;
            }
        }

        $sha1 = sha1_file($path);

        if (! is_string($sha1) || $sha1 === '') {
            throw new RuntimeException('No se pudo calcular el sha1 de '.$path.'.');
        }

        $duration = $this->processor->duration($path);
        $lufs = $this->processor->integratedLufs($path);

        $this->library->add([
            'file' => $this->relativeFile($category),
            'type' => $category['type'],
            'tags' => $this->mergeTags([...$category['keywords'], $category['slug']]),
            'duration' => $duration,
            'loopable' => $this->processor->isLoopable($path, $duration),
            'source_id' => 'core-'.$category['slug'],
            'source_url' => 'internal://core/'.$category['slug'],
            'author' => 'horror-studio',
            'license' => 'internal',
            'attribution_required' => false,
            'lufs' => $lufs,
            'sha1' => $sha1,
            'is_core' => true,
        ]);
    }

    /**
     * @param  array{slug: string, coreFile: string}  $category
     * @return array{slug: string, status: string, file: string, reason: string}
     */
    private function failed(array $category, string $reason): array
    {
        return [
            'slug' => $category['slug'],
            'status' => 'failed',
            'file' => $this->relativeFile($category),
            'reason' => $reason,
        ];
    }

    /**
     * @param  array{name: string, tags: list<string>}  $sound
     * @param  array{keywords: list<string>}  $category
     */
    private function relevance(array $sound, array $category): int
    {
        $haystack = mb_strtolower($sound['name'].' '.implode(' ', $sound['tags']));
        $hits = 0;

        foreach ($category['keywords'] as $keyword) {
            if ($keyword !== '' && str_contains($haystack, $keyword)) {
                $hits++;
            }
        }

        return $hits;
    }

    /**
     * @param  array{coreFile: string}  $category
     */
    private function relativeFile(array $category): string
    {
        return 'core/'.$category['coreFile'];
    }

    /**
     * @param  list<mixed>  $tags
     * @return list<string>
     */
    private function mergeTags(array $tags): array
    {
        $merged = [];

        foreach ($tags as $tag) {
            $value = mb_strtolower(trim((string) $tag));

            if ($value !== '' && ! in_array($value, $merged, true)) {
                $merged[] = $value;
            }
        }

        return $merged;
    }
}
