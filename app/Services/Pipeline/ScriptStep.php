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

    /** Con qué se desempata una puntuación repetida. Más alto gana. */
    private const VERDICT_RANK = ['publish' => 3, 'revise' => 2, 'discard' => 1];

    private readonly string $outputDirectory;

    private readonly bool $reviewEnabled;

    private readonly int $candidates;

    public function __construct(
        private StoryGenerator $generator,
        private StoryReviewer $reviewer,
        private JsonLlm $llm,
        private Filesystem $files,
        Repository $config,
    ) {
        $this->outputDirectory = storage_path('app/'.$config->get('stories.output_path'));
        $this->reviewEnabled = (bool) $config->get('stories.review.enabled');
        $this->candidates = max(1, (int) $config->get('stories.story.candidates', 1));
    }

    /**
     * @param  (callable(string, int, int, ?string): void)|null  $onProgress
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

        $wanted = $this->candidateCount($skipReview);
        $this->progress($onProgress, $story->title !== '' ? $story->title : 'guion', 0, $wanted, 'generate');

        $candidates = [];
        $failure = null;

        for ($candidate = 1; $candidate <= $wanted; $candidate++) {
            try {
                $script = $this->generateWithRetries($premise, $mode, $loreSlug, $warnings);
            } catch (Throwable $exception) {
                $failure = $exception;

                continue;
            }

            $review = null;

            try {
                $review = $this->reviewStory($script, $skipReview);
            } catch (Throwable $exception) {
                $warnings[] = 'Revisión automática fallida: '.$exception->getMessage();
            }

            $candidates[] = ['script' => $script, 'review' => $review];
            $this->progress($onProgress, $script->title, count($candidates), $wanted, 'generate');
        }

        if ($candidates === []) {
            $failure ??= new RuntimeException('No se pudo generar la historia.');

            return ['ok' => false, 'error' => $failure->getMessage(), 'exception' => $failure, 'warnings' => $warnings];
        }

        $winner = $this->best($candidates);
        $script = $winner['script'];
        $review = $winner['review'];

        if (! $dryRun) {
            // Solo se escribe el ganador. Un candidato descartado nunca llega al disco, así que no
            // hay nada que barrer después ni un JSON huérfano que confunda al elegir historia.
            $slug = $this->writeStory($story, $script, $review);
        } else {
            $slug = $story->slug !== ''
                ? $story->slug
                : date('Y-m-d').'-'.Str::slug($script->title);
        }

        $willReview = ! $skipReview && $this->reviewEnabled;

        if ($willReview) {
            $this->progress($onProgress, $script->title, 1, 1, 'review');
        }

        return [
            'ok' => true,
            'title' => $script->title,
            'slug' => $slug,
            'candidates' => count($candidates),
            'discarded' => $this->discarded($candidates, $winner),
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
     * Sin revisión no hay puntuación con la que comparar, así que generar varios candidatos sería
     * gastar el triple para elegir al azar.
     */
    private function candidateCount(bool $skipReview): int
    {
        if ($skipReview || ! $this->reviewEnabled) {
            return 1;
        }

        return $this->candidates;
    }

    /**
     * Gana la puntuación más alta; a puntuación igual, el veredicto mejor; si tampoco, el primero
     * que se generó. Un candidato cuya revisión falló no tiene nota y solo gana si no hay otro.
     *
     * @param  non-empty-list<array{script: StoryScript, review: ?StoryReview}>  $candidates
     * @return array{script: StoryScript, review: ?StoryReview}
     */
    private function best(array $candidates): array
    {
        $best = $candidates[0];

        foreach ($candidates as $candidate) {
            if ($this->rank($candidate['review']) > $this->rank($best['review'])) {
                $best = $candidate;
            }
        }

        return $best;
    }

    /**
     * @return array{int, int}
     */
    private function rank(?StoryReview $review): array
    {
        if (! $review instanceof StoryReview) {
            return [-1, 0];
        }

        return [$review->score, self::VERDICT_RANK[$review->verdict] ?? 0];
    }

    /**
     * Lo que se tiró, para que el resumen diga contra qué se eligió.
     *
     * @param  list<array{script: StoryScript, review: ?StoryReview}>  $candidates
     * @param  array{script: StoryScript, review: ?StoryReview}  $winner
     * @return list<array{title: string, score: ?int, verdict: ?string}>
     */
    private function discarded(array $candidates, array $winner): array
    {
        $discarded = [];

        foreach ($candidates as $candidate) {
            if ($candidate['script'] === $winner['script']) {
                continue;
            }

            $discarded[] = [
                'title' => $candidate['script']->title,
                'score' => $candidate['review'] instanceof StoryReview ? $candidate['review']->score : null,
                'verdict' => $candidate['review'] instanceof StoryReview ? $candidate['review']->verdict : null,
            ];
        }

        return $discarded;
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

    private function writeStory(Story $record, StoryScript $story, ?StoryReview $review, ?string $forcedSlug = null): string
    {
        $this->files->ensureDirectoryExists($this->outputDirectory);

        $useRecordSlug = $record->slug !== '' && ! str_starts_with($record->slug, 'draft-');

        $filename = $forcedSlug !== null && $forcedSlug !== ''
            ? $forcedSlug.'.json'
            : ($useRecordSlug
                ? $record->slug.'.json'
                : sprintf('%s-%s.json', date('Y-m-d'), Str::slug($story->title)));

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
     * @param  (callable(string, int, int, ?string): void)|null  $onProgress
     */
    private function progress(?callable $onProgress, string $label, int $done, int $total, ?string $stage = null): void
    {
        if ($onProgress !== null) {
            $onProgress($label, $done, $total, $stage);
        }
    }
}
