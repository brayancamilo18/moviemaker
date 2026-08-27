<?php

declare(strict_types=1);

namespace App\Services\Image;

use App\DataObjects\Shot;
use App\DataObjects\VisualBible;
use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;

final class ShotPromptBuilder
{
    /**
     * @var list<string>
     */
    private const FIGURE_SUBJECTS = ['protagonist', 'threat', 'both'];

    // Prioridad de la parte descriptiva: cuando no cabe todo, se cae primero el rango más alto.
    private const RANK_DESCRIPTION = 1;

    private const RANK_CHARACTERS = 2;

    private const RANK_SETTING = 3;

    private const RANK_REST = 4;

    /**
     * @var array<string, string>
     */
    private const EUPHEMISMS = [
        'blood-soaked' => 'dark stained',
        'bloodstained' => 'dark stained',
        'bloody' => 'stained',
        'blood' => 'dark stain',
        'corpses' => 'still figures',
        'corpse' => 'still figure',
        'dead bodies' => 'still figures',
        'dead body' => 'still figure',
        'skeleton' => 'bone figure',
        'skulls' => 'bones',
        'skull' => 'bone',
        'gore' => 'dark stain',
        'gory' => 'stained',
    ];

    private readonly string $styleSuffix;

    private readonly int $maxWords;

    private readonly int $minDescriptiveWords;

    public function __construct(Repository $config)
    {
        $this->styleSuffix = trim((string) $config->get('stories.image_style_suffix'));
        $this->maxWords = (int) $config->get('stories.images.max_prompt_words');
        $this->minDescriptiveWords = (int) $config->get('stories.images.min_prompt_description_words');
    }

    public function build(Shot $shot, VisualBible $bible): string
    {
        $description = trim($shot->description);

        if ($description === '') {
            throw new InvalidArgumentException("El plano {$shot->order} no tiene description.");
        }

        $parts = [];

        if (in_array($shot->subject, self::FIGURE_SUBJECTS, true)) {
            foreach ($shot->characterSlugs as $slug) {
                $character = $this->characterBySlug($bible, $slug);

                if ($character === null) {
                    continue;
                }

                $parts[] = $this->part(self::RANK_CHARACTERS, $this->sanitize($character['bodyDescriptor']));
                $options = $character['framingOptions'];

                if ($options !== []) {
                    $parts[] = $this->part(self::RANK_CHARACTERS, $this->sanitize($options[$shot->order % count($options)]));
                }
            }
        }

        if (in_array($shot->subject, ['threat', 'both'], true)) {
            $parts[] = $this->part(self::RANK_REST, $this->sanitize($this->threatStageDescriptor($bible, $shot->threatStage)));
        }

        $parts[] = $this->part(self::RANK_DESCRIPTION, $this->sanitize($description));
        $parts[] = $this->part(self::RANK_REST, $this->sanitize($shot->framing));
        $parts[] = $this->part(self::RANK_SETTING, $this->sanitize($bible->setting));
        $parts[] = $this->part(self::RANK_REST, $this->sanitize($bible->timeOfDay));
        $parts[] = $this->part(self::RANK_REST, $this->sanitize($bible->weather));
        $parts[] = $this->part(self::RANK_REST, $this->sanitize(implode(' ', $bible->palette)));

        $negatives = $this->negatives($bible);
        $suffix = $this->styleSuffix;
        $budget = $this->maxWords - $this->wordCount($negatives) - $this->wordCount($suffix);

        // Los negativos son la única defensa contra caras resueltas y marcas de agua: si el
        // presupuesto descriptivo se queda sin aire, el que cede el sitio es el sufijo de estilo.
        if ($budget < $this->minDescriptiveWords) {
            $suffix = '';
            $budget = $this->maxWords - $this->wordCount($negatives);
        }

        $prompt = array_values(array_filter(
            [$this->descriptive($parts, $budget), $suffix, $negatives],
            static fn (string $part): bool => $part !== '',
        ));

        return implode(', ', $prompt);
    }

    /**
     * @param  list<Shot>  $shots
     * @return list<string>
     */
    public function previewAll(array $shots, VisualBible $bible): array
    {
        $prompts = [];

        foreach ($shots as $shot) {
            $prompts[] = $this->build($shot, $bible);
        }

        return $prompts;
    }

    /**
     * @return array{rank: int, text: string}
     */
    private function part(int $rank, string $text): array
    {
        return [
            'rank' => $rank,
            'text' => trim($text),
        ];
    }

    /**
     * Reparte el presupuesto de palabras entre los bloques descriptivos: cada bloque elige antes
     * que los de menor prioridad, y el que no quepa se descarta entero. Solo el primero (la
     * description del plano) se recorta, porque un prompt sin él no describe nada. El prompt se
     * devuelve en orden semántico, no en orden de prioridad.
     *
     * @param  list<array{rank: int, text: string}>  $parts
     */
    private function descriptive(array $parts, int $budget): string
    {
        if ($budget <= 0) {
            return '';
        }

        $order = array_keys($parts);
        usort(
            $order,
            static fn (int $left, int $right): int => [$parts[$left]['rank'], $left] <=> [$parts[$right]['rank'], $right],
        );

        $kept = [];
        $spent = 0;

        foreach ($order as $index) {
            $text = $parts[$index]['text'];

            if ($text === '') {
                continue;
            }

            $cost = $this->wordCount($text);

            if ($spent + $cost > $budget) {
                if ($kept !== []) {
                    continue;
                }

                $text = $this->limitWords($text, $budget);
                $cost = $budget;
            }

            $kept[$index] = $text;
            $spent += $cost;
        }

        ksort($kept);

        return implode(', ', $kept);
    }

    /**
     * @return array{slug: string, bodyDescriptor: string, framingOptions: list<string>}|null
     */
    private function characterBySlug(VisualBible $bible, string $slug): ?array
    {
        foreach ($bible->characters as $character) {
            if ($character['slug'] === $slug) {
                return $character;
            }
        }

        return null;
    }

    private function threatStageDescriptor(VisualBible $bible, ?string $stage): string
    {
        if ($stage === null || $stage === '') {
            return '';
        }

        foreach ($bible->threat['stages'] as $item) {
            if ($item['stage'] === $stage) {
                return $item['descriptor'];
            }
        }

        return '';
    }

    private function negatives(VisualBible $bible): string
    {
        $items = [
            'no text',
            'no watermark',
            'no logos',
            'no clear facial features',
            'no direct eye contact',
        ];
        $seen = array_map(static fn (string $item): string => mb_strtolower($item), $items);

        foreach ($bible->avoid as $item) {
            $item = $this->sanitize($item);

            if ($item === '') {
                continue;
            }

            $clause = str_starts_with(mb_strtolower($item), 'no ') ? $item : 'no '.$item;
            $key = mb_strtolower($clause);

            if (in_array($key, $seen, true)) {
                continue;
            }

            $seen[] = $key;
            $items[] = $clause;
        }

        return implode(', ', $items);
    }

    private function sanitize(string $text): string
    {
        return $this->stripProperNames($this->soften($text));
    }

    private function soften(string $text): string
    {
        $replacements = self::EUPHEMISMS;
        uksort($replacements, static fn (string $left, string $right): int => mb_strlen($right) <=> mb_strlen($left));

        foreach ($replacements as $from => $to) {
            $text = (string) preg_replace('/\b'.preg_quote($from, '/').'\b/iu', $to, $text);
        }

        return trim($text);
    }

    private function stripProperNames(string $text): string
    {
        $words = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $kept = [];

        foreach ($words as $index => $word) {
            $plain = trim($word, '.,;:!?');

            if ($index > 0 && preg_match('/^\p{Lu}\p{L}{2,}$/u', $plain) === 1) {
                continue;
            }

            $kept[] = $word;
        }

        return implode(' ', $kept);
    }

    private function limitWords(string $prompt, int $max): string
    {
        $words = $this->words($prompt);

        if (count($words) <= $max) {
            return $prompt;
        }

        return implode(' ', array_slice($words, 0, $max));
    }

    private function wordCount(string $text): int
    {
        return count($this->words($text));
    }

    /**
     * @return list<string>
     */
    private function words(string $text): array
    {
        $words = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY);

        return $words === false ? [] : $words;
    }
}
