<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Story;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\JsonResponse;
use JsonException;
use Throwable;

final class StoryInspectionController extends Controller
{
    private const WORDS_PER_MINUTE = 130;

    private readonly string $outputDirectory;

    public function __construct(
        private readonly Filesystem $files,
        Repository $config,
    ) {
        $this->outputDirectory = storage_path('app/'.$config->get('stories.output_path'));
    }

    public function script(Story $story): JsonResponse
    {
        $path = $this->outputDirectory.DIRECTORY_SEPARATOR.$story->slug.'.json';

        if (! $this->files->isFile($path)) {
            return $this->unavailable();
        }

        try {
            /** @var array<string, mixed> $payload */
            $payload = json_decode($this->files->get($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $this->unavailable();
        } catch (Throwable) {
            return $this->unavailable();
        }

        $scenes = $this->scenes($payload);
        $wordCount = $this->wordCount($scenes);

        return response()->json([
            'available' => true,
            'title' => (string) ($payload['title'] ?? ''),
            'hook' => (string) ($payload['hook'] ?? ''),
            'description' => (string) ($payload['description'] ?? ''),
            'tags' => $this->stringList($payload['tags'] ?? []),
            'scenes' => $scenes,
            'pronunciations' => $this->pronunciations($payload['pronunciations'] ?? []),
            'review' => $this->review($payload['review'] ?? null),
            'wordCount' => $wordCount,
            'estimatedSeconds' => (int) round($wordCount / self::WORDS_PER_MINUTE * 60),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array{order: int, narration: string, visualSummary: string}>
     */
    private function scenes(array $payload): array
    {
        $scenes = [];

        foreach ($payload['scenes'] ?? [] as $scene) {
            if (! is_array($scene)) {
                continue;
            }

            $scenes[] = [
                'order' => (int) ($scene['order'] ?? 0),
                'narration' => (string) ($scene['narration'] ?? ''),
                'visualSummary' => (string) ($scene['visualSummary'] ?? ''),
            ];
        }

        usort(
            $scenes,
            static fn (array $left, array $right): int => $left['order'] <=> $right['order'],
        );

        return $scenes;
    }

    /**
     * @param  list<array{order: int, narration: string, visualSummary: string}>  $scenes
     */
    private function wordCount(array $scenes): int
    {
        $narration = implode(' ', array_map(
            static fn (array $scene): string => $scene['narration'],
            $scenes,
        ));

        $words = preg_split('/\s+/u', trim($narration), -1, PREG_SPLIT_NO_EMPTY);

        return $words === false ? 0 : count($words);
    }

    /**
     * @return list<array{term: string, phonetic: string}>
     */
    private function pronunciations(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $items = [];

        foreach ($raw as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $term = trim((string) ($entry['term'] ?? ''));

            if ($term === '') {
                continue;
            }

            $items[] = [
                'term' => $term,
                'phonetic' => (string) ($entry['phonetic'] ?? ''),
            ];
        }

        return $items;
    }

    /**
     * @return array{verdict: mixed, score: mixed, nonNativePhrases: mixed, clichedElements: mixed, tensionDips: mixed, ttsRisks: mixed}|null
     */
    private function review(mixed $raw): ?array
    {
        if (! is_array($raw)) {
            return null;
        }

        return [
            'verdict' => $raw['verdict'] ?? null,
            'score' => $raw['score'] ?? null,
            'nonNativePhrases' => $raw['nonNativePhrases'] ?? [],
            'clichedElements' => $raw['clichedElements'] ?? [],
            'tensionDips' => $raw['tensionDips'] ?? [],
            'ttsRisks' => $raw['ttsRisks'] ?? [],
        ];
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $items = [];

        foreach ($raw as $value) {
            if (is_string($value) && $value !== '') {
                $items[] = $value;
            }
        }

        return $items;
    }

    private function unavailable(): JsonResponse
    {
        return response()->json([
            'available' => false,
            'reason' => 'El guion todavía no se ha generado.',
        ], 404);
    }
}
