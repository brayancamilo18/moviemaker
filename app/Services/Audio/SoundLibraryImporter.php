<?php

declare(strict_types=1);

namespace App\Services\Audio;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class SoundLibraryImporter
{
    public function __construct(
        private FreesoundClient $freesound,
        private AudioLibrary $library,
        private LibraryClipProcessor $processor,
        private Filesystem $files,
    ) {}

    /**
     * @return list<string>
     */
    public static function tagsFromQuery(string $query): array
    {
        $parts = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($query), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $tags = [];

        foreach ($parts as $part) {
            if (mb_strlen($part) >= 3 && ! in_array($part, $tags, true)) {
                $tags[] = $part;
            }
        }

        return $tags;
    }

    /**
     * @return list<array{id: int, name: string, author: string, license: string, duration: float, rating: float, downloads?: int, tags: list<string>, previewUrl: string, sourceUrl: string}>
     */
    public function search(string $type, string $query, int $limit): array
    {
        return $this->freesound->search($query, $type, $limit);
    }

    /**
     * @param  array{id: int, name: string, author: string, license: string, duration: float, rating: float, downloads?: int, tags: list<string>, previewUrl: string, sourceUrl: string}  $sound
     * @param  list<string>  $extraTags
     * @return array{status: string, clip?: array<string, mixed>, reason?: string}
     */
    public function ingest(array $sound, string $type, array $extraTags = []): array
    {
        if ($this->library->hasSourceId($sound['id'])) {
            return [
                'status' => 'skipped',
                'reason' => 'source_id duplicado',
            ];
        }

        if (! $this->freesound->licenseAllowed($sound['license'])) {
            return [
                'status' => 'skipped',
                'reason' => 'licencia no permitida',
            ];
        }

        if ($sound['author'] === '' || $sound['license'] === '' || $sound['sourceUrl'] === '') {
            return [
                'status' => 'skipped',
                'reason' => 'faltan autor, licencia o URL de origen',
            ];
        }

        $workDir = storage_path('app/tmp/audio-fetch-'.bin2hex(random_bytes(6)));
        $this->files->ensureDirectoryExists($workDir);

        try {
            $previewPath = $workDir.DIRECTORY_SEPARATOR.'preview.mp3';
            $this->files->put($previewPath, $this->freesound->downloadPreview($sound['previewUrl']));
            $this->processor->assertAudio($previewPath);

            $filename = $this->filename($sound, $type);
            $relative = $type.'/'.$filename;
            $destination = $this->library->directoryFor($type).DIRECTORY_SEPARATOR.$filename;

            $this->processor->convertToLibraryWav($previewPath, $destination);
            $this->processor->assertAudio($destination);

            $sha1 = sha1_file($destination);

            if (! is_string($sha1) || $sha1 === '') {
                throw new RuntimeException('No se pudo calcular el sha1 de '.$destination.'.');
            }

            if ($this->library->hasSha1($sha1)) {
                $this->files->delete($destination);

                return [
                    'status' => 'skipped',
                    'reason' => 'sha1 duplicado',
                ];
            }

            $duration = $this->processor->duration($destination);
            $lufs = $this->processor->integratedLufs($destination);
            $clip = [
                'file' => $relative,
                'type' => $type,
                'tags' => $this->mergeTags($sound['tags'], $extraTags),
                'duration' => $duration,
                'loopable' => $this->processor->isLoopable($destination, $duration),
                'source_id' => (string) $sound['id'],
                'source_url' => $sound['sourceUrl'],
                'author' => $sound['author'],
                'license' => $sound['license'],
                'attribution_required' => $sound['license'] === 'Attribution',
                'lufs' => $lufs,
                'sha1' => $sha1,
            ];

            $this->library->add($clip);

            return [
                'status' => 'added',
                'clip' => $clip,
            ];
        } catch (Throwable $exception) {
            return [
                'status' => 'failed',
                'reason' => $exception->getMessage(),
            ];
        } finally {
            $this->files->deleteDirectory($workDir);
        }
    }

    /**
     * @param  array{id: int, name: string}  $sound
     */
    private function filename(array $sound, string $type): string
    {
        $slug = Str::slug($sound['name']);

        if ($slug === '') {
            $slug = $type;
        }

        return $slug.'-'.$sound['id'].'.wav';
    }

    /**
     * @param  list<string>  $tags
     * @param  list<string>  $extraTags
     * @return list<string>
     */
    private function mergeTags(array $tags, array $extraTags): array
    {
        $merged = [];

        foreach ([...$tags, ...$extraTags] as $tag) {
            $normalized = mb_strtolower(trim((string) $tag));

            if ($normalized !== '' && ! in_array($normalized, $merged, true)) {
                $merged[] = $normalized;
            }
        }

        return $merged;
    }
}
