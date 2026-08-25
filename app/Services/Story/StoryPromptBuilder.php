<?php

declare(strict_types=1);

namespace App\Services\Story;

use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

final class StoryPromptBuilder
{
    private readonly int $minScenes;

    private readonly int $maxScenes;

    private readonly int $targetWords;

    private readonly string $imageStyleSuffix;

    private readonly string $accent;

    private readonly string $lorePath;

    public function __construct(Repository $config)
    {
        $this->minScenes = (int) $config->get('stories.story.min_scenes');
        $this->maxScenes = (int) $config->get('stories.story.max_scenes');
        $this->targetWords = (int) $config->get('stories.story.target_words');
        $this->imageStyleSuffix = (string) $config->get('stories.image_style_suffix');
        $this->accent = (string) $config->get('stories.story.accent');
        $this->lorePath = resource_path('lore/folklore.json');
    }

    public function systemInstruction(): string
    {
        $accent = $this->accentLabel();
        $minWords = (int) round($this->targetWords * 0.6);
        $maxWords = (int) round($this->targetWords * 1.4);
        $minSceneWords = (int) ceil($minWords / $this->minScenes);

        return <<<INSTRUCTION
You are a horror scriptwriter for spoken audio narration, specialized in psychological and atmospheric horror.

OUTPUT LANGUAGE: English. Every field you write is in English: title, hook, description, tags, and every scene narration. Neutral American English ({$accent}). Spanish appears only as proper names of folklore beings, people, or places that belong in the story. Those names stay in Spanish in the narration and must be listed in pronunciations. Do not write the script, the hook, the title, or the tags in Spanish.

The user prompt will declare a mode: folklore or original. Follow the matching mode rules.

Common writing rules:
- First person, past tense.
- Neutral American English, consistent spelling and vocabulary ({$accent}). No regional eye dialect. No unusual contractions. Do not write speech in phonetic spelling.
- The first fifteen seconds are the hook: open on the most unsettling image. Never open with introductions, backstory, or scene-setting preamble.
- Suggested terror only. No gore. No graphic violence.
- Short sentences. This text will be read aloud by a TTS engine. No long parenthetical asides. No stacked subordinate clauses.
- Avoid ambiguous homographs that a TTS engine often mispronounces unless the surrounding wording makes the intended reading unmistakable. Do not rely on these words without a clear recast: read, live, lead, tear, wind, bow, close, wound.
- Write every number, acronym, and symbol in words.
- End on an image or a revelation. Never a moral. Never "and then I woke up".
- Wholly original: no brands, no real people, no copyrighted works.

Folklore mode:
- The story is original fiction set inside a living tradition, not a retelling of the legend.
- The creature or phenomenon appears, but the characters, the specific place, and the events are invented.
- Keep the central rules of the tradition. Do not change how the creature works. Do not invent powers that do not belong to it. If El Silbón is heard nearby when he is far away, that rule stays.
- Do not treat the culture as exotic decoration. Characters are ordinary people, not rural stereotypes.
- Contemporary setting, or the last few decades. Not a period-folklore costume drama.

Original mode:
- The story is one hundred percent invented. No traditional creature or named legend.
- Explicitly forbidden tropes: possessed dolls, ouija boards, mirrors as portals, generic haunted houses, clown dolls, ghost children in hallways.

Structure:
- Between {$this->minScenes} and {$this->maxScenes} scenes. Prefer twelve to sixteen so the length lands on target.
- Each scene is {$minSceneWords} to 150 words. Do not write short scenes.
- The full narration MUST be between {$minWords} and {$maxWords} words. Aim for {$this->targetWords}. Scripts under {$minWords} words are rejected.

imagePrompt rules:
- English.
- A static shot. No recognizable faces.
- Visual continuity across scenes: same place, same palette, same time of day, unless the story itself changes them.
- Always end the prompt with this style suffix: {$this->imageStyleSuffix}

visualBeats rules:
- Each scene must include visualBeats: four to eight short visual stills, in the order the camera would see them.
- Each beat is 8 to 14 words of English. A static image. No recognizable faces. No proper names. No gore.
- These strings will be copied verbatim into later image prompts. They must be self-contained. No pronouns. No "the same" or "as before".
- Suggested terror through light, weather, and objects. Write blood as dark stain. Write a corpse as still figure.

Title rules:
- Maximum seventy characters.
- No all-caps clickbait.
- Hint without spoiling.

Pronunciations:
- Fill pronunciations with every Spanish term that appears in the narration, including person names and place names.
- term is the exact spelling as it appears in the text.
- phonetic is a hyphenated approximation a US English reader can pronounce, with stress in capitals. Example: Sacamantecas → sah-kah-mahn-TEH-kahs.
- Do not add ordinary English words. If there are no Spanish terms, return an empty array.
INSTRUCTION;
    }

    public function userPrompt(string $premise = '', string $mode = 'folklore', ?string $loreSlug = null): string
    {
        $premise = trim($premise);
        $mode = strtolower(trim($mode));

        if (! in_array($mode, ['folklore', 'original'], true)) {
            throw new InvalidArgumentException("El modo '{$mode}' no es válido. Usa folklore u original.");
        }

        if ($mode === 'original') {
            return $this->originalUserPrompt($premise);
        }

        return $this->folkloreUserPrompt($premise, $loreSlug);
    }

    private function originalUserPrompt(string $premise): string
    {
        $premiseBlock = $premise === ''
            ? 'Invent the premise yourself.'
            : "Premise:\n{$premise}";

        return <<<PROMPT
MODE: original

Write a complete psychological horror script in English. Invented from nothing. No traditional creature. No named legend.

Forbidden tropes: possessed dolls, ouija boards, mirrors as portals, generic haunted houses, clown dolls, ghost children in hallways.

{$premiseBlock}

Follow every system rule for original mode. Title, hook, description, tags, and all narration must be in English.
PROMPT;
    }

    private function folkloreUserPrompt(string $premise, ?string $loreSlug): string
    {
        $entry = $this->selectLore($loreSlug);
        $motifs = implode(', ', $entry['motifs']);
        $premiseBlock = $premise === ''
            ? 'Invent characters, a specific contemporary place, and the events. Do not retell the legend.'
            : "Premise:\n{$premise}";

        return <<<PROMPT
MODE: folklore

Tradition card:
- Slug: {$entry['slug']}
- Name: {$entry['name']}
- Region: {$entry['region']}
- Summary: {$entry['summary']}
- Motifs: {$motifs}

Write a complete horror script in English as original fiction set in this tradition. The creature or phenomenon appears. Characters, the concrete place, and the plot are invented. Do not retell the legend. Do not change the creature's traditional rules. Do not invent powers that do not belong to it. Ordinary people, not rural stereotypes. Contemporary or recent-decades setting.

{$premiseBlock}

Follow every system rule for folklore mode. Title, hook, description, tags, and all narration must be in English. Keep Spanish only for proper names of the creature, people, or places, and list those in pronunciations.
PROMPT;
    }

    /**
     * @return list<array{slug: string, name: string, region: string, summary: string, motifs: list<string>}>
     */
    public function loreEntries(): array
    {
        return $this->loadLore();
    }

    /**
     * @return array{slug: string, name: string, region: string, summary: string, motifs: list<string>}
     */
    private function selectLore(?string $loreSlug): array
    {
        $entries = $this->loadLore();
        $slug = $loreSlug !== null ? trim($loreSlug) : '';

        if ($slug !== '') {
            foreach ($entries as $entry) {
                if ($entry['slug'] === $slug) {
                    return $entry;
                }
            }

            throw new InvalidArgumentException("No hay una ficha de folklore con el slug '{$slug}'.");
        }

        return $entries[array_rand($entries)];
    }

    /**
     * @return list<array{slug: string, name: string, region: string, summary: string, motifs: list<string>}>
     */
    private function loadLore(): array
    {
        if (! is_readable($this->lorePath)) {
            throw new RuntimeException('No se pudo leer el archivo de folklore.');
        }

        $json = file_get_contents($this->lorePath);

        if ($json === false) {
            throw new RuntimeException('No se pudo leer el archivo de folklore.');
        }

        try {
            /** @var list<array{slug: string, name: string, region: string, summary: string, motifs: list<string>}> $entries */
            $entries = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('El archivo de folklore no es un JSON válido.', previous: $exception);
        }

        if ($entries === []) {
            throw new RuntimeException('El archivo de folklore está vacío.');
        }

        return $entries;
    }

    private function accentLabel(): string
    {
        return match ($this->accent) {
            'neutral_american' => 'neutral American: color, center, toward; no British spelling',
            default => $this->accent,
        };
    }
}
