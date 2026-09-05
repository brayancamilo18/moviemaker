<?php

declare(strict_types=1);

namespace App\Services\Story;

use App\Enums\ReviewVerdict;
use App\Enums\StoryMode;
use App\Enums\StoryStatus;
use App\Models\Story;
use App\Services\Image\ShotImageCount;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use JsonException;

final class StoryImporter
{
    private readonly string $storiesDirectory;

    public function __construct(
        private Filesystem $files,
        private ShotImageCount $shotImages,
        Repository $config,
    ) {
        $this->storiesDirectory = storage_path('app/'.$config->get('stories.output_path'));
    }

    /**
     * @return array{rows: list<array{slug: string, title: string, mode: string, status: string, action: string}>, created: int, updated: int, omitted: int}
     */
    public function import(bool $dryRun): array
    {
        $rows = [];
        $created = 0;
        $updated = 0;
        $omitted = 0;

        foreach ($this->storyFiles() as $path) {
            $result = $this->importFile($path, $dryRun);
            $rows[] = $result['row'];

            match ($result['action']) {
                'crear' => $created++,
                'actualizar' => $updated++,
                default => $omitted++,
            };
        }

        return [
            'rows' => $rows,
            'created' => $created,
            'updated' => $updated,
            'omitted' => $omitted,
        ];
    }

    /**
     * @return list<string>
     */
    private function storyFiles(): array
    {
        $paths = $this->files->glob($this->storiesDirectory.DIRECTORY_SEPARATOR.'*.json');

        if ($paths === false) {
            return [];
        }

        sort($paths);

        return $paths;
    }

    /**
     * @return array{action: string, row: array{slug: string, title: string, mode: string, status: string, action: string}}
     */
    private function importFile(string $path, bool $dryRun): array
    {
        $slug = pathinfo($path, PATHINFO_FILENAME);
        $status = $this->inferStatus($slug);
        $payload = $this->readPayload($path);

        if ($payload === null || $slug === '') {
            return $this->row($slug, '', StoryMode::Folklore, $status, 'omitir');
        }

        $attributes = $this->attributes($payload);
        $existing = Story::query()->where('slug', $slug)->first();
        $action = $existing instanceof Story ? 'actualizar' : 'crear';

        if (! $dryRun) {
            if ($existing instanceof Story) {
                $existing->fill($attributes);

                // Una historia que avanzó por fuera —un comando suelto, un script encadenado—
                // deja los artefactos en disco y la fila donde estaba. Sin esto, el panel
                // nunca se entera de que terminó: enseña "narrada" con el MP4 ya escrito.
                if ($this->advances($existing->status, $status)) {
                    $existing->status = $status;
                }

                $existing->save();
            } else {
                Story::query()->create([
                    ...$attributes,
                    'slug' => $slug,
                    'status' => $status,
                ]);
            }
        }

        return $this->row(
            $slug,
            (string) $attributes['title'],
            $attributes['mode'],
            $status,
            $action,
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readPayload(string $path): ?array
    {
        $contents = $this->files->get($path);

        try {
            $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Metadatos y métricas que sí se pueden pisar. Nunca incluye status, descarte ni publicación.
     *
     * @param  array<string, mixed>  $payload
     * @return array{title: string, mode: StoryMode, lore_slug?: string, lore_name?: string, verdict?: ReviewVerdict, score?: float, scene_count: int, sentence_count?: int, narration_seconds?: float}
     */
    private function attributes(array $payload): array
    {
        $title = trim((string) ($payload['title'] ?? ''));
        $lore = $this->lore($payload);
        $review = is_array($payload['review'] ?? null) ? $payload['review'] : [];
        $audio = is_array($payload['audio'] ?? null) ? $payload['audio'] : [];
        $verdict = ReviewVerdict::tryFrom(strtolower(trim((string) ($review['verdict'] ?? ''))));
        $scenes = is_array($payload['scenes'] ?? null) ? $payload['scenes'] : [];

        $attributes = [
            'title' => $title !== '' ? $title : '',
            'mode' => $this->mode($payload['mode'] ?? null),
            'scene_count' => count($scenes),
        ];

        if ($lore['slug'] !== null) {
            $attributes['lore_slug'] = $lore['slug'];
        }

        if ($lore['name'] !== null) {
            $attributes['lore_name'] = $lore['name'];
        }

        if ($verdict instanceof ReviewVerdict) {
            $attributes['verdict'] = $verdict;
        }

        if (array_key_exists('score', $review) && is_numeric($review['score'])) {
            $attributes['score'] = (float) $review['score'];
        }

        if (array_key_exists('durationSeconds', $audio) && is_numeric($audio['durationSeconds'])) {
            $attributes['narration_seconds'] = (float) $audio['durationSeconds'];
        }

        if (array_key_exists('sentenceCount', $audio) && is_numeric($audio['sentenceCount'])) {
            $attributes['sentence_count'] = (int) $audio['sentenceCount'];
        }

        return $attributes;
    }

    private function mode(mixed $value): StoryMode
    {
        $raw = is_string($value) ? strtolower(trim($value)) : '';

        return match ($raw) {
            'original' => StoryMode::Original,
            'folklore', 'folclore' => StoryMode::Folklore,
            default => StoryMode::Folklore,
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{slug: ?string, name: ?string}
     */
    private function lore(array $payload): array
    {
        $slug = $payload['lore_slug'] ?? null;
        $name = $payload['lore_name'] ?? null;
        $nested = $payload['lore'] ?? $payload['folklore'] ?? null;

        if (is_array($nested)) {
            $slug ??= $nested['slug'] ?? null;
            $name ??= $nested['name'] ?? null;
        } elseif (is_string($nested) && trim($nested) !== '') {
            $name ??= trim($nested);
        }

        return [
            'slug' => is_string($slug) && trim($slug) !== '' ? trim($slug) : null,
            'name' => is_string($name) && trim($name) !== '' ? trim($name) : null,
        ];
    }

    /**
     * Solo se adelanta, y solo entre estados que gana el pipeline por su cuenta. Retroceder
     * sería borrar trabajo hecho, y adelantar sobre una decisión humana —aprobada, publicada,
     * descartada, fallida— sería tomarla en su nombre.
     */
    private function advances(StoryStatus $current, StoryStatus $inferred): bool
    {
        $from = $current->pipelineRank();
        $to = $inferred->pipelineRank();

        return $from !== null && $to !== null && $to > $from;
    }

    private function inferStatus(string $slug): StoryStatus
    {
        $directory = $this->storiesDirectory.DIRECTORY_SEPARATOR.$slug;

        return match (true) {
            $this->files->isFile($directory.DIRECTORY_SEPARATOR.'video.mp4') => StoryStatus::PendingReview,
            $this->files->isFile($directory.DIRECTORY_SEPARATOR.'narration_mix.wav') => StoryStatus::Mixed,
            $this->imagesComplete($slug) => StoryStatus::ImagesReady,
            $this->files->isFile($directory.DIRECTORY_SEPARATOR.'narration.wav') => StoryStatus::Narrated,
            default => StoryStatus::ScriptReady,
        };
    }

    /**
     * Que exista shots.json no quiere decir que las imágenes estén: el fichero se escribe con
     * el plan y se va rellenando plano a plano durante media hora larga. Importar una historia
     * a medias como "imágenes listas" la deja a un clic de saltar a sonido con la mitad de los
     * planos sin pintar, y esa mitad ya no se recupera sola.
     *
     * Una historia ya depurada no pasa por aquí: story:prune solo suelta las imágenes cuando
     * hay vídeo, y el vídeo se comprueba antes.
     */
    private function imagesComplete(string $slug): bool
    {
        $images = $this->shotImages->get($slug);

        return $images !== null && $images['done'] >= $images['total'];
    }

    /**
     * @return array{action: string, row: array{slug: string, title: string, mode: string, status: string, action: string}}
     */
    private function row(string $slug, string $title, StoryMode $mode, StoryStatus $status, string $action): array
    {
        return [
            'action' => $action,
            'row' => [
                'slug' => $slug,
                'title' => $title,
                'mode' => $mode->value,
                'status' => $status->label(),
                'action' => $action,
            ],
        ];
    }
}
