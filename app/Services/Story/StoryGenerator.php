<?php

declare(strict_types=1);

namespace App\Services\Story;

use App\Contracts\JsonLlm;
use App\DataObjects\Story;
use App\DataObjects\StoryScene;
use App\Exceptions\InvalidStoryException;
use App\Services\Llm\LlmTask;
use Illuminate\Contracts\Config\Repository;

final class StoryGenerator
{
    private readonly int $minScenes;

    private readonly int $maxScenes;

    private readonly int $targetWords;

    public function __construct(
        private StoryPromptBuilder $promptBuilder,
        private JsonLlm $llm,
        Repository $config,
    ) {
        $this->minScenes = (int) $config->get('stories.story.min_scenes');
        $this->maxScenes = (int) $config->get('stories.story.max_scenes');
        $this->targetWords = (int) $config->get('stories.story.target_words');
    }

    public function generate(string $premise = '', string $mode = 'folklore', ?string $loreSlug = null): Story
    {
        $data = $this->llm->generateJson(
            $this->promptBuilder->systemInstruction(),
            $this->promptBuilder->userPrompt($premise, $mode, $loreSlug),
            StorySchema::get(),
            task: LlmTask::Script,
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
