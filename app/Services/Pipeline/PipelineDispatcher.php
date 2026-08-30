<?php

declare(strict_types=1);

namespace App\Services\Pipeline;

use App\Enums\StoryStatus;
use App\Jobs\RunPipelineStep;
use App\Models\Story;
use Illuminate\Contracts\Bus\Dispatcher;
use InvalidArgumentException;

final class PipelineDispatcher
{
    /**
     * @var list<string>
     */
    public const STEPS = ['script', 'narration', 'images', 'sound', 'render'];

    public function __construct(
        private Dispatcher $bus,
        private PipelineProgress $progress,
    ) {}

    public function advance(Story $story, bool $chain = true): void
    {
        $step = match ($story->status) {
            StoryStatus::Draft => 'script',
            StoryStatus::ScriptReady => 'narration',
            StoryStatus::Narrated => 'images',
            StoryStatus::ImagesReady => 'sound',
            StoryStatus::Mixed => 'render',
            default => null,
        };

        if ($step === null) {
            return;
        }

        $this->queue($story, $step, $chain);
    }

    public function runFrom(Story $story, string $step, bool $chain = true): void
    {
        if (! in_array($step, self::STEPS, true)) {
            throw new InvalidArgumentException("Paso de pipeline desconocido: {$step}.");
        }

        $this->queue($story, $step, $chain);
    }

    private function queue(Story $story, string $step, bool $chain): void
    {
        $this->progress->put($story->id, $step, $step, 0, 1);
        $this->bus->dispatch(new RunPipelineStep($story->id, $step, $chain));
    }
}
