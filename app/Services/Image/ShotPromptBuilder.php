<?php

declare(strict_types=1);

namespace App\Services\Image;

use App\DataObjects\Shot;
use App\DataObjects\VisualBible;
use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;

final class ShotPromptBuilder
{
    private const MAX_WORDS = 75;

    /**
     * @var list<string>
     */
    private const FIGURE_SUBJECTS = ['protagonist', 'threat', 'both'];

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

    public function __construct(Repository $config)
    {
        $this->styleSuffix = (string) $config->get('stories.image_style_suffix');
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

                $parts[] = $this->sanitize($character['bodyDescriptor']);
                $options = $character['framingOptions'];

                if ($options !== []) {
                    $parts[] = $this->sanitize($options[$shot->order % count($options)]);
                }
            }
        }

        if (in_array($shot->subject, ['threat', 'both'], true)) {
            $parts[] = $this->sanitize($this->threatStageDescriptor($bible, $shot->threatStage));
        }

        $parts[] = $this->sanitize($description);
        $parts[] = $this->sanitize($shot->framing);
        $parts[] = $this->sanitize($bible->setting);
        $parts[] = $this->sanitize($bible->timeOfDay);
        $parts[] = $this->sanitize($bible->weather);
        $parts[] = $this->sanitize(implode(' ', $bible->palette));
        $parts[] = $this->styleSuffix;
        $parts[] = $this->negatives($bible);

        $parts = array_values(array_filter(
            $parts,
            static fn (string $part): bool => trim($part) !== '',
        ));

        return $this->limitWords($this->soften(implode(', ', $parts)));
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

    private function limitWords(string $prompt): string
    {
        $words = $this->words($prompt);

        if (count($words) <= self::MAX_WORDS) {
            return $prompt;
        }

        return implode(' ', array_slice($words, 0, self::MAX_WORDS));
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
