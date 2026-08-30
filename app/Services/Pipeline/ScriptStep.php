<?php

declare(strict_types=1);

namespace App\Services\Pipeline;

use App\Contracts\JsonLlm;
use App\DataObjects\Story as StoryScript;
use App\DataObjects\StoryReview;
use App\Enums\StoryMode;
use App\Exceptions\InvalidStoryException;
use App\Models\Story;
use App\Services\Story\StoryGenerator;
use App\Services\Story\StoryReviewer;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class ScriptStep
{
    private const GENERATION_ATTEMPTS = 3;

    private readonly string $outputDirectory;

    private readonly bool $reviewEnabled;

    public function __construct(
        private StoryGenerator $generator,
        private StoryReviewer $reviewer,
        private JsonLlm $llm,
        private Filesystem $files,
        Repository $config,
    ) {
        $this->outputDirectory = storage_path('app/'.$config->get('stories.output_path'));
        $this->reviewEnabled = (bool) $config->get('stories.review.enabled');
    }

    /**
     * @param  (callable(string, int, int): void)|null  $onProgress
     * @param  array{skip_review?: bool, dry_run?: bool}  $options
     * @return array<string, mixed>
     */
    public function run(Story $story, ?callable $onProgress = null, array $options = []): array
    {
        $skipReview = (bool) ($options['skip_review'] ?? false);
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $premise = (string) ($story->premise ?? '');
        $mode = $story->mode === StoryMode::Original ? 'original' : 'folklore';
        $loreSlug = $story->lore_slug;
        $warnings = [];

        $this->progress($onProgress, $story->title !== '' ? $story->title : 'guion', 0, 1);

        try {
            $script = $this->generateWithRetries($premise, $mode, $loreSlug, $warnings);
            $review = $this->reviewStory($script, $skipReview);

            if (! $dryRun) {
                $slug = $this->writeStory($story, $script, $review);
            } else {
                $slug = $story->slug !== ''
                    ? $story->slug
                    : date('Y-m-d').'-'.Str::slug($script->title);
            }
        } catch (Throwable $exception) {
            return ['ok' => false, 'error' => $exception->getMessage(), 'exception' => $exception, 'warnings' => $warnings];
        }

        $this->progress($onProgress, $script->title, 1, 1);

        return [
            'ok' => true,
            'title' => $script->title,
            'slug' => $slug,
            'scene_count' => count($script->scenes),
            'sentence_count' => $script->wordCount() > 0 ? $script->wordCount() : count($script->scenes),
            'word_count' => $script->wordCount(),
            'estimated_seconds' => $script->estimatedDurationSeconds(),
            'verdict' => $review instanceof StoryReview ? $review->verdict : null,
            'score' => $review instanceof StoryReview ? $review->score : null,
            'used_fallback' => $this->llm->fallbackNotice() !== null,
            'llm_name' => $this->llm->name(),
            'fallback_notice' => $this->llm->fallbackNotice(),
            'warnings' => $warnings,
            'story' => $script,
            'review' => $review,
            'mode' => $mode,
            'lore_name' => $story->lore_name,
        ];
    }

    /**
     * @param  list<string>  $warnings
     */
    private function generateWithRetries(string $premise, string $mode, ?string $loreSlug, array &$warnings): StoryScript
    {
        $lastException = null;

        for ($attempt = 1; $attempt <= self::GENERATION_ATTEMPTS; $attempt++) {
            try {
                return $this->generator->generate($premise, $mode, $loreSlug);
            } catch (InvalidStoryException $exception) {
                $lastException = $exception;

                if ($attempt < self::GENERATION_ATTEMPTS) {
                    $warnings[] = "Intento {$attempt} rechazado: {$exception->getMessage()} Reintentando...";
                }
            }
        }

        throw $lastException ?? new RuntimeException('No se pudo generar la historia.');
    }

    private function reviewStory(StoryScript $story, bool $skipReview): ?StoryReview
    {
        if ($skipReview || ! $this->reviewEnabled) {
            return null;
        }

        return $this->reviewer->review($story);
    }

    private function writeStory(Story $record, StoryScript $story, ?StoryReview $review): string
    {
        $this->files->ensureDirectoryExists($this->outputDirectory);

        $useRecordSlug = $record->slug !== '' && ! str_starts_with($record->slug, 'draft-');

        $filename = $useRecordSlug
            ? $record->slug.'.json'
            : sprintf('%s-%s.json', date('Y-m-d'), Str::slug($story->title));

        $payload = $story->toArray();

        if ($review instanceof StoryReview) {
            $payload['review'] = $review->toArray();
        }

        $json = json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE,
        );

        if ($json === false) {
            throw new RuntimeException('No se pudo serializar la historia a JSON.');
        }

        $this->files->put($this->outputDirectory.DIRECTORY_SEPARATOR.$filename, $json);

        return pathinfo($filename, PATHINFO_FILENAME);
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
