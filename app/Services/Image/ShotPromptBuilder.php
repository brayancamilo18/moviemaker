<?php

declare(strict_types=1);

namespace App\Services\Image;

use App\DataObjects\Shot;
use App\DataObjects\Story;
use App\DataObjects\StoryScene;
use App\DataObjects\VisualBible;
use Illuminate\Contracts\Config\Repository;

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

    /**
     * @var list<string>
     */
    private const STOPWORDS = [
        'and', 'del', 'el', 'en', 'for', 'from', 'la', 'las', 'los', 'of',
        'the', 'this', 'that', 'un', 'una', 'uno', 'with',
    ];

    private readonly string $styleSuffix;

    public function __construct(Repository $config)
    {
        $this->styleSuffix = (string) $config->get('stories.image_style_suffix');
    }

    public function build(Shot $shot, VisualBible $bible, Story $story): string
    {
        $rotation = [];

        return $this->compose($shot, $bible, $story, $rotation);
    }

    /**
     * @param  list<Shot>  $shots
     * @return list<string>
     */
    public function previewAll(array $shots, VisualBible $bible, Story $story): array
    {
        $rotation = [];
        $prompts = [];

        foreach ($shots as $shot) {
            $prompts[] = $this->compose($shot, $bible, $story, $rotation);
        }

        return $prompts;
    }

    /**
     * @param  array<string, int>  $rotation
     */
    private function compose(Shot $shot, VisualBible $bible, Story $story, array &$rotation): string
    {
        $beat = $this->resolveBeat($shot, $story);

        $parts = array_filter([
            $this->figureLine($shot, $bible, $rotation),
            $this->sanitize($this->beatDescription($beat)),
            $this->sanitize($shot->framing),
            $this->sanitize($bible->setting),
            $this->sanitize($bible->timeOfDay),
            $this->sanitize($bible->weather),
            $this->sanitize($this->paletteLine($bible)),
            $this->styleSuffix,
            $this->negatives($bible),
        ], static fn (string $part): bool => trim($part) !== '');

        return $this->limitWords($this->soften(implode(', ', $parts)));
    }

    /**
     * @param  array<string, int>  $rotation
     */
    private function figureLine(Shot $shot, VisualBible $bible, array &$rotation): string
    {
        $subject = $shot->subject;
        $threatStage = $shot->threatStage;

        if (! in_array($subject, self::FIGURE_SUBJECTS, true)) {
            return '';
        }

        $parts = [];

        if (in_array($subject, ['protagonist', 'both'], true)) {
            $character = $this->resolveCharacter($shot, $bible);

            if ($character !== null) {
                $parts[] = $this->sanitize($character['bodyDescriptor']);
                $framing = $this->nextFraming($character, $rotation);

                if ($framing !== '') {
                    $parts[] = $this->sanitize($framing);
                }
            }
        }

        if (in_array($subject, ['threat', 'both'], true)) {
            $parts[] = $this->sanitize($bible->threat['nature']);
            $parts[] = $this->sanitize($this->threatStageDescriptor($bible, $threatStage));
        }

        return implode(', ', array_filter($parts));
    }

    /**
     * @param  array{slug: string, bodyDescriptor: string, framingOptions: list<string>}  $character
     * @param  array<string, int>  $rotation
     */
    private function nextFraming(array $character, array &$rotation): string
    {
        $options = $character['framingOptions'];

        if ($options === []) {
            return '';
        }

        $slug = $character['slug'];
        $index = $rotation[$slug] ?? 0;
        $rotation[$slug] = $index + 1;

        return $options[$index % count($options)];
    }

    /**
     * @return array{slug: string, bodyDescriptor: string, framingOptions: list<string>}|null
     */
    private function resolveCharacter(Shot $shot, VisualBible $bible): ?array
    {
        if ($bible->characters === []) {
            return null;
        }

        $haystack = $this->fold($shot->sourceText);

        foreach ($bible->characters as $character) {
            if ($this->mentions($haystack, $character['slug'])) {
                return $character;
            }
        }

        return $bible->characters[0];
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

    /**
     * @return array{description: string, subject: string, threatStage: ?string}|null
     */
    private function resolveBeat(Shot $shot, Story $story): ?array
    {
        $scene = $this->scene($story, $shot->sceneOrder);
        $beats = $scene?->visualBeats ?? [];

        if ($scene === null) {
            return null;
        }

        if ($beats === []) {
            $description = trim($scene->imagePrompt);

            if ($description === '') {
                return null;
            }

            return [
                'description' => $description,
                'subject' => 'environment',
                'threatStage' => null,
            ];
        }

        return $this->beatByNarrationPosition($shot->sourceText, $scene->narration, $beats)
            ?? $this->beatByOverlap($shot->sourceText, $beats)
            ?? $beats[0];
    }

    /**
     * @param  array{description: string, subject: string, threatStage: ?string}|null  $beat
     */
    private function beatDescription(?array $beat): string
    {
        return $beat['description'] ?? '';
    }

    /**
     * @param  list<array{description: string, subject: string, threatStage: ?string}>  $beats
     * @return array{description: string, subject: string, threatStage: ?string}|null
     */
    private function beatByNarrationPosition(string $sourceText, string $narration, array $beats): ?array
    {
        $haystack = $this->fold($narration);
        $needle = $this->fold($sourceText);

        if ($haystack === '' || $needle === '') {
            return null;
        }

        $pos = mb_strpos($haystack, $needle);

        if ($pos === false) {
            $snippet = mb_substr($needle, 0, 48);
            $pos = $snippet === '' ? false : mb_strpos($haystack, $snippet);
        }

        if ($pos === false) {
            return null;
        }

        $span = max(1, mb_strlen($haystack) - mb_strlen($needle));
        $ratio = min(1.0, $pos / $span);
        $index = (int) floor($ratio * count($beats));

        return $beats[min($index, count($beats) - 1)];
    }

    /**
     * @param  list<array{description: string, subject: string, threatStage: ?string}>  $beats
     * @return array{description: string, subject: string, threatStage: ?string}|null
     */
    private function beatByOverlap(string $sourceText, array $beats): ?array
    {
        $haystack = $this->fold($sourceText);
        $best = null;
        $bestScore = 0;

        foreach ($beats as $beat) {
            $score = $this->overlap($haystack, $this->fold($this->beatDescription($beat)));

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $beat;
            }
        }

        return $best;
    }

    private function paletteLine(VisualBible $bible): string
    {
        return implode(', ', $bible->palette);
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

    private function mentions(string $haystack, string $slug): bool
    {
        $tokens = preg_split('/[-_]+/', $this->fold($slug), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($tokens === []) {
            return false;
        }

        $phrase = implode(' ', $tokens);

        if (str_contains($haystack, $phrase)) {
            return true;
        }

        foreach ($tokens as $token) {
            if (mb_strlen($token) < 3 || in_array($token, self::STOPWORDS, true)) {
                continue;
            }

            if (preg_match('/\b'.preg_quote($token, '/').'\b/u', $haystack) === 1) {
                return true;
            }
        }

        return false;
    }

    private function overlap(string $haystack, string $needle): int
    {
        $score = 0;

        foreach ($this->words($needle) as $word) {
            if (mb_strlen($word) < 3 || in_array($word, self::STOPWORDS, true)) {
                continue;
            }

            if (preg_match('/\b'.preg_quote($word, '/').'\b/u', $haystack) === 1) {
                $score++;
            }
        }

        return $score;
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

    private function fold(string $text): string
    {
        $text = mb_strtolower($text);
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        $text = is_string($ascii) ? $ascii : $text;
        $text = preg_replace('/[^a-z0-9\s]+/u', ' ', $text) ?? $text;

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * @return list<string>
     */
    private function words(string $text): array
    {
        $words = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY);

        return $words === false ? [] : $words;
    }

    private function scene(Story $story, int $order): ?StoryScene
    {
        foreach ($story->scenes as $scene) {
            if ($scene->order === $order) {
                return $scene;
            }
        }

        return null;
    }
}
