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
    private const MAX_WORDS = 60;

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
        $parts = array_filter([
            $shot->framing,
            $this->visualBeat($shot, $story),
            $this->entityLine($shot, $bible),
            $this->sanitize($bible->setting),
            $this->sanitize($bible->timeOfDay),
            $this->sanitize($bible->weather),
            $this->sanitize($this->paletteLine($bible)),
            $this->styleSuffix,
            $this->negatives($bible),
        ], static fn (string $part): bool => trim($part) !== '');

        $prompt = $this->soften(implode(', ', $parts));

        return $this->limitWords($prompt);
    }

    private function visualBeat(Shot $shot, Story $story): string
    {
        $scene = $this->scene($story, $shot->sceneOrder);
        $beats = $scene?->visualBeats ?? [];

        if ($scene === null || $beats === []) {
            return $this->sanitize($scene?->imagePrompt ?? '');
        }

        $beat = $this->beatByNarrationPosition($shot->sourceText, $scene->narration, $beats)
            ?? $this->beatByOverlap($shot->sourceText, $beats)
            ?? $beats[0];

        return $this->sanitize($beat);
    }

    /**
     * @param  list<string>  $beats
     */
    private function beatByNarrationPosition(string $sourceText, string $narration, array $beats): ?string
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
     * @param  list<string>  $beats
     */
    private function beatByOverlap(string $sourceText, array $beats): ?string
    {
        $haystack = $this->fold($sourceText);
        $best = null;
        $bestScore = 0;

        foreach ($beats as $beat) {
            $score = $this->overlap($haystack, $this->fold($beat));

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $beat;
            }
        }

        return $best;
    }

    private function entityLine(Shot $shot, VisualBible $bible): string
    {
        $haystack = $this->fold($shot->sourceText);
        $chunks = [];

        foreach ($bible->characters as $character) {
            if (! $this->mentions($haystack, $character['slug'])) {
                continue;
            }

            $chunks[] = $this->sanitize(trim($character['descriptor'].', '.$character['framingRule']));
        }

        foreach ($bible->recurringObjects as $object) {
            if (! $this->mentions($haystack, $object['slug'])) {
                continue;
            }

            $chunks[] = $this->sanitize($object['descriptor']);
        }

        return implode(', ', array_filter($chunks));
    }

    private function paletteLine(VisualBible $bible): string
    {
        return implode(', ', $bible->palette);
    }

    private function negatives(VisualBible $bible): string
    {
        $items = ['no text', 'no watermark', 'no faces', 'no modern logos'];
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
