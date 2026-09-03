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

    private readonly int $minWords;

    private readonly int $maxWords;

    public function __construct(
        private StoryPromptBuilder $promptBuilder,
        private JsonLlm $llm,
        Repository $config,
    ) {
        $this->minScenes = (int) $config->get('stories.story.min_scenes');
        $this->maxScenes = (int) $config->get('stories.story.max_scenes');
        $this->targetWords = (int) $config->get('stories.story.target_words');
        $this->minWords = (int) round(
            $this->targetWords * (float) $config->get('stories.story.word_tolerance.min_ratio'),
        );
        $this->maxWords = (int) round(
            $this->targetWords * (float) $config->get('stories.story.word_tolerance.max_ratio'),
        );
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

        if ($totalWords < $this->minWords || $totalWords > $this->maxWords) {
            $errors[] = "El guion tiene {$totalWords} palabras; el objetivo es {$this->targetWords} (mínimo {$this->minWords}, máximo {$this->maxWords}).";
        }

        $titleLength = mb_strlen($story->title);

        if ($titleLength > 70) {
            $errors[] = "El título tiene {$titleLength} caracteres; el máximo es 70.";
        }

        $unphonetic = $this->namesWithoutPhonetics($story);

        if ($unphonetic !== []) {
            $errors[] = 'Estos nombres en español no están en pronunciations: '
                .implode(', ', $unphonetic).'.';
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

    /**
     * El prompt y el schema ya piden fonética para todo término español, pero el modelo se salta
     * alguno, y el que se salta cuesta dos veces: un lector inglés lo pronuncia a su manera
     * («Tomás» sale «Thomas») y encima whisper transcribe lo que ha oído, no lo que pone el
     * guion, así que la frase entera se queda sin alineación por texto y sin `words`.
     *
     * Se mira el texto ya sustituido: si después de aplicar pronunciations sigue habiendo una
     * tilde o una eñe, ese término se quedó fuera. Solo en palabras que empiezan por mayúscula,
     * para no confundir un préstamo del inglés como «café» con un nombre propio.
     *
     * @return list<string>
     */
    private function namesWithoutPhonetics(Story $story): array
    {
        $found = [];

        foreach ($this->narratedTexts($story) as $text) {
            preg_match_all('/\p{Lu}\p{L}*/u', $story->textForTts($text), $matches);

            foreach ($matches[0] as $word) {
                if (preg_match('/[áéíóúüñ]/iu', $word) === 1) {
                    $found[$word] = true;
                }
            }
        }

        return array_keys($found);
    }

    /**
     * Todo lo que se va a narrar y lo ha escrito el modelo: las escenas, el cold open y el gancho
     * de la careta. El texto fijo del canal no se mira, porque no sale de aquí.
     *
     * @return list<string>
     */
    private function narratedTexts(Story $story): array
    {
        $texts = [$story->hookLine];

        if ($story->coldOpen instanceof StoryScene) {
            $texts[] = $story->coldOpen->narration;
        }

        foreach ($story->scenes as $scene) {
            $texts[] = $scene->narration;
        }

        return $texts;
    }
}
