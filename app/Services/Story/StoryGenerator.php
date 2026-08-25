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

    private readonly string $defaultMode;

    public function __construct(
        private StoryPromptBuilder $promptBuilder,
        private GeminiClient $gemini,
        Repository $config,
    ) {
        $this->minScenes = (int) $config->get('stories.story.min_scenes');
        $this->maxScenes = (int) $config->get('stories.story.max_scenes');
        $this->targetWords = (int) $config->get('stories.story.target_words');
        $this->defaultMode = (string) $config->get('stories.story.default_mode');
    }

    public function generate(string $premise = '', ?string $mode = null, ?string $loreSlug = null): Story
    {
        $mode = strtolower(trim($mode ?? $this->defaultMode));

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

        $data['scenes'] = array_values($scenes);

        return Story::fromArray($data);
    }

    private function validate(Story $story): void
    {
        $errors = [];
        $sceneCount = count($story->scenes);

        if ($sceneCount < $this->minScenes || $sceneCount > $this->maxScenes) {
            $errors[] = "La historia tiene {$sceneCount} escenas; debe haber entre {$this->minScenes} y {$this->maxScenes}.";
        }

        foreach ($story->scenes as $index => $scene) {
            $expectedOrder = $index + 1;

            if ($scene->order !== $expectedOrder) {
                $errors[] = "El order de las escenas no es correlativo empezando en 1 (se esperaba {$expectedOrder}, llegó {$scene->order}).";
                break;
            }
        }

        foreach ($story->scenes as $scene) {
            $wordCount = $this->narrationWordCount($scene);

            if ($wordCount === 0) {
                $errors[] = "La escena {$scene->order} tiene la narración vacía.";
            } elseif ($wordCount < 30) {
                $errors[] = "La escena {$scene->order} tiene {$wordCount} palabras; el mínimo es 30.";
            }
        }

        $totalWords = $story->wordCount();
        $minWords = max(1, (int) round($this->targetWords * 0.6) - 50);
        $maxWords = (int) round($this->targetWords * 1.4);

        if ($totalWords < $minWords || $totalWords > $maxWords) {
            $errors[] = "El guion tiene {$totalWords} palabras; el objetivo es {$this->targetWords} (mínimo {$minWords}, máximo {$maxWords}).";
        }

        $titleLength = mb_strlen($story->title);

        if ($titleLength > 70) {
            $errors[] = "El título tiene {$titleLength} caracteres; el máximo es 70.";
        }

        if ($errors !== []) {
            throw new InvalidStoryException(implode(' ', $errors));
        }
    }

    private function narrationWordCount(StoryScene $scene): int
    {
        $words = preg_split('/\s+/u', trim($scene->narration), -1, PREG_SPLIT_NO_EMPTY);

        return $words === false ? 0 : count($words);
    }
}
