<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\StoryStatus;
use App\Models\Story;
use App\Services\Pipeline\ImagesStep;
use App\Services\Pipeline\NarrationStep;
use App\Services\Pipeline\PipelineDispatcher;
use App\Services\Pipeline\PipelineProgress;
use App\Services\Pipeline\RenderStep;
use App\Services\Pipeline\ScriptStep;
use App\Services\Pipeline\SoundStep;
use Illuminate\Container\Container;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

final class RunPipelineStep implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 3600;

    /**
     * @var list<string>
     */
    private const METRIC_KEYS = [
        'slug',
        'title',
        'verdict',
        'score',
        'narration_seconds',
        'master_seconds',
        'video_seconds',
        'scene_count',
        'sentence_count',
        'shot_count',
        'effect_count',
        'lufs',
        'true_peak',
        'figure_ratio',
        'detail_ratio',
        'used_fallback',
    ];

    public function __construct(
        public int $storyId,
        public string $step,
        public bool $chain = true,
    ) {}

    public function handle(
        ScriptStep $script,
        NarrationStep $narration,
        ImagesStep $images,
        SoundStep $sound,
        RenderStep $render,
        PipelineProgress $progress,
        PipelineDispatcher $dispatcher,
    ): void {
        $story = Story::query()->findOrFail($this->storyId);

        $onProgress = function (string $label, int $done, int $total) use ($progress): void {
            $progress->put($this->storyId, $this->step, $label, $done, $total);
        };

        $progress->put($this->storyId, $this->step, $this->step, 0, 1);

        $result = match ($this->step) {
            'script' => $script->run($story, $onProgress),
            'narration' => $narration->run($story, $onProgress),
            'images' => $images->run($story, $onProgress),
            'sound' => $this->runSound($sound, $story, $onProgress),
            'render' => $render->run($story, $onProgress),
            default => throw new InvalidArgumentException("Paso de pipeline desconocido: {$this->step}."),
        };

        if (($result['ok'] ?? true) === false) {
            $previous = $result['exception'] ?? null;

            throw new RuntimeException(
                (string) ($result['error'] ?? 'El paso del pipeline falló.'),
                previous: $previous instanceof Throwable ? $previous : null,
            );
        }

        $this->applyMetrics($story, $result);
        $this->recordWarnings($story, $result);
        $this->transitionAfterStep($story);
        $progress->clear($this->storyId);

        if ($this->chain) {
            $dispatcher->advance($story->fresh() ?? $story, $this->chain);
        }
    }

    public function failed(?Throwable $e): void
    {
        $story = Story::query()->find($this->storyId);

        if (! $story instanceof Story) {
            return;
        }

        $max = (int) Container::getInstance()->make('config')->get('stories.pipeline.failed_message_max');
        $message = $this->failedMessage($e, $max);
        $previous = $e?->getPrevious();

        Container::getInstance()->make(LoggerInterface::class)->error('El paso del pipeline falló.', [
            'step' => $this->step,
            'previous' => $previous instanceof Throwable ? $previous::class : null,
        ]);
        $from = $story->status;

        if ($from !== StoryStatus::Failed && $from->canTransitionTo(StoryStatus::Failed)) {
            $story->transitionTo(StoryStatus::Failed, $message, ['step' => $this->step]);
        }

        $story->update([
            'failed_step' => mb_substr($this->step, 0, 40),
            'failed_message' => $message !== '' ? $message : null,
        ]);

        $story->events()->create([
            'type' => 'step_failed',
            'from_status' => $from->value,
            'to_status' => StoryStatus::Failed->value,
            'note' => $message !== '' ? $message : null,
            'payload' => ['step' => $this->step],
        ]);

        Container::getInstance()->make(PipelineProgress::class)->clear($this->storyId);
    }

    private function failedMessage(?Throwable $e, int $max): string
    {
        $message = $e?->getMessage() ?? '';
        $previous = $e?->getPrevious();

        if ($previous instanceof Throwable) {
            $message .= "\n\nCausa: ".$previous::class.': '.$previous->getMessage();
        }

        return mb_substr($message, 0, $max);
    }

    /**
     * @param  (callable(string, int, int): void)  $onProgress
     * @return array<string, mixed>
     */
    private function runSound(SoundStep $sound, Story $story, callable $onProgress): array
    {
        $resolved = $sound->run($story, $onProgress);

        if (($resolved['ok'] ?? true) === false) {
            return $resolved;
        }

        $mixed = $sound->run($story, $onProgress, ['mix' => true]);

        return $mixed + $resolved;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function recordWarnings(Story $story, array $result): void
    {
        $warnings = $result['warnings'] ?? [];

        if (! is_array($warnings) || $warnings === []) {
            return;
        }

        $messages = [];

        foreach ($warnings as $warning) {
            if (is_string($warning) && $warning !== '') {
                $messages[] = $warning;
            }
        }

        if ($messages === []) {
            return;
        }

        $story->events()->create([
            'type' => 'step_warning',
            'payload' => [
                'step' => $this->step,
                'messages' => $messages,
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function applyMetrics(Story $story, array $result): void
    {
        $updates = [];

        foreach (self::METRIC_KEYS as $key) {
            if (! array_key_exists($key, $result) || $result[$key] === null) {
                continue;
            }

            if ($key === 'slug') {
                if (! $this->slugCanBeReplaced((string) $story->slug)) {
                    continue;
                }

                $updates[$key] = $this->allocateSlug($story, (string) $result[$key]);
                continue;
            }

            $updates[$key] = $result[$key];
        }

        if ($updates !== []) {
            $story->update($updates);
        }
    }

    private function slugCanBeReplaced(string $slug): bool
    {
        $slug = trim($slug);

        return $slug === '' || str_starts_with($slug, 'draft-');
    }

    private function allocateSlug(Story $story, string $wanted): string
    {
        $base = $wanted;
        $candidate = $base;
        $suffix = 2;

        while (
            Story::query()
                ->where('slug', $candidate)
                ->where('id', '!=', $story->id)
                ->exists()
        ) {
            $candidate = $base.'-'.$suffix;
            $suffix++;
        }

        if ($candidate !== $base) {
            $story->events()->create([
                'type' => 'slug_collision',
                'payload' => [
                    'requested' => $base,
                    'assigned' => $candidate,
                ],
            ]);
        }

        return $candidate;
    }

    private function transitionAfterStep(Story $story): void
    {
        $next = match ($this->step) {
            'script' => StoryStatus::ScriptReady,
            'narration' => StoryStatus::Narrated,
            'images' => StoryStatus::ImagesReady,
            'sound' => StoryStatus::Mixed,
            'render' => StoryStatus::Rendered,
            default => throw new InvalidArgumentException("Paso de pipeline desconocido: {$this->step}."),
        };

        $story->refresh();

        if ($story->status === $next) {
            return;
        }

        $story->transitionTo($next);
    }
}
