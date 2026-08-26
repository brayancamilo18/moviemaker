<?php

declare(strict_types=1);

namespace App\Services\Story;

use App\DataObjects\Story;
use App\DataObjects\StoryScene;
use App\Exceptions\InvalidStoryException;
use App\Services\Llm\GeminiClient;
use Illuminate\Contracts\Config\Repository;

final class StoryGenerator
{
    private readonly int $minScenes;

    private readonly int $maxScenes;

    private readonly int $targetWords;

    public function __construct(
        private StoryPromptBuilder $promptBuilder,
        private GeminiClient $gemini,
        Repository $config,
    ) {
        $this->minScenes = (int) $config->get('stories.story.min_scenes');
        $this->maxScenes = (int) $config->get('stories.story.max_scenes');
        $this->targetWords = (int) $config->get('stories.story.target_words');
    }

    public function generate(string $premise = '', string $mode = 'folklore', ?string $loreSlug = null): Story
    {
        $data = $this->gemini->generateJson(
            $this->promptBuilder->systemInstruction(),
            $this->promptBuilder->userPrompt($premise, $mode, $loreSlug),
            StorySchema::get(),
        );

        $story = $this->hydrate($data);
        $this->validate($story);

        return $story;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function hydrate(array $data): Story
    {
        $scenes = is_array($data['scenes'] ?? null) ? $data['scenes'] : [];

        usort(
            $scenes,
            static fn (array $left, array $right): int => ((int) ($left['order'] ?? 0)) <=> ((int) ($right['order'] ?? 0)),
        );

        $scenes = array_values($scenes);

        foreach ($scenes as $index => $scene) {
            $scenes[$index]['order'] = $index + 1;
        }

        $data['scenes'] = $scenes;

        return Story::fromArray($data);
    }

    private function validate(Story $story): void
    {
        $errors = [];
        $sceneCount = count($story->scenes);

        if ($sceneCount < $this->minScenes || $sceneCount > $this->maxScenes) {
            $errors[] = "La historia tiene {$sceneCount} escenas; debe haber entre {$this->minScenes} y {$this->maxScenes}.";
        }

        foreach ($story->scenes as $scene) {
            $wordCount = $this->narrationWordCount($scene);

            if ($wordCount === 0) {
                $errors[] = "La escena {$scene->order} tiene la narración vacía (0 palabras).";
            } elseif ($wordCount < 30) {
                $errors[] = "La escena {$scene->order} tiene {$wordCount} palabras; el mínimo es 30.";
            }
        }

        $totalWords = $story->wordCount();
        $minWords = (int) round($this->targetWords * 0.6);
        $maxWords = (int) round($this->targetWords * 1.4);

        if ($totalWords < $minWords || $totalWords > $maxWords) {
            $errors[] = "El guion tiene {$totalWords} palabras; el objetivo es {$this->targetWords} (±40%: mínimo {$minWords}, máximo {$maxWords}).";
        }

        $titleLength = mb_strlen($story->title);

        if ($titleLength > 70) {
            $errors[] = "El título tiene {$titleLength} caracteres; el máximo es 70.";
        }

        $errors = [...$errors, ...$this->validateVisualBeats($story)];

        if ($errors !== []) {
            throw new InvalidStoryException(implode(' ', $errors));
        }
    }

    /**
     * @return list<string>
     */
    private function validateVisualBeats(Story $story): array
    {
        $beats = $story->visualBeatsInOrder();
        $metrics = $story->visualBeatMetrics();
        $total = $metrics['total'];
        $ratioLabel = $this->figureRatioLabel($metrics);
        $errors = [];

        if ($total === 0 || $metrics['figureRatio'] < 0.55) {
            $errors[] = "El ratio de figuras es {$ratioLabel} ({$metrics['figureCount']} de {$total} beats); el mínimo es 55%.";
        }

        if ($total === 0) {
            return $errors;
        }

        foreach ($beats as $index => $beat) {
            if ($beat['threatStage'] !== 'reveal') {
                continue;
            }

            $progress = $index / $total;

            if ($progress < 0.7) {
                $percent = (int) round($progress * 100);
                $position = $index + 1;
                $errors[] = "Hay un reveal al {$percent}% de la historia (beat {$position} de {$total}); no puede aparecer antes del 70%. Ratio de figuras: {$ratioLabel}.";
                break;
            }
        }

        $streak = 0;
        $longest = 0;

        foreach ($beats as $beat) {
            if ($beat['subject'] === 'environment') {
                $streak++;
                $longest = max($longest, $streak);
            } else {
                $streak = 0;
            }
        }

        if ($longest > 2) {
            $errors[] = "Hay {$longest} beats de environment consecutivos; el máximo es 2. Ratio de figuras: {$ratioLabel}.";
        }

        return $errors;
    }

    /**
     * @param  array{total: int, figureCount: int, figureRatio: float, threatStages: array{hint: int, presence: int, reveal: int}}  $metrics
     */
    private function figureRatioLabel(array $metrics): string
    {
        return ((int) round($metrics['figureRatio'] * 100)).'%';
    }

    private function narrationWordCount(StoryScene $scene): int
    {
        $words = preg_split('/\s+/u', trim($scene->narration), -1, PREG_SPLIT_NO_EMPTY);

        return $words === false ? 0 : count($words);
    }
}
