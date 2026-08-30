<?php

declare(strict_types=1);

namespace App\Jobs;

use App\DataObjects\Story as StoryScript;
use App\Models\Story;
use App\Services\Story\StoryReviewer;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Queue\Queueable;
use JsonException;
use RuntimeException;
use Throwable;

final class ReviewStory implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(
        public int $storyId,
    ) {}

    public function handle(StoryReviewer $reviewer, Filesystem $files, Repository $config): void
    {
        $story = Story::query()->findOrFail($this->storyId);
        $path = storage_path('app/'.$config->get('stories.output_path')).DIRECTORY_SEPARATOR.$story->slug.'.json';

        if (! $files->isFile($path)) {
            throw new RuntimeException('No hay un JSON de guion en disco.');
        }

        try {
            $decoded = json_decode($files->get($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('El JSON del guion no es válido.', previous: $exception);
        }

        if (! is_array($decoded) || ! isset($decoded['scenes']) || ! is_array($decoded['scenes'])) {
            throw new RuntimeException('El JSON no contiene un guion de historia.');
        }

        /** @var array<string, mixed> $decoded */
        $review = $reviewer->review(StoryScript::fromArray($decoded));
        $decoded['review'] = $review->toArray();

        $json = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        if ($json === false) {
            throw new RuntimeException('No se pudo serializar la historia a JSON.');
        }

        $files->put($path, $json);

        $story->update([
            'verdict' => $review->verdict,
            'score' => $review->score,
        ]);
    }

    public function failed(?Throwable $e): void
    {
        $story = Story::query()->find($this->storyId);

        if (! $story instanceof Story) {
            return;
        }

        $message = $e?->getMessage() ?? '';

        $story->events()->create([
            'type' => 'review_failed',
            'note' => $message !== '' ? $message : null,
        ]);
    }
}
