<?php

declare(strict_types=1);

namespace App\Services\Audio;

use App\DataObjects\SceneAmbience;
use App\DataObjects\Story;
use App\DataObjects\StoryScene;
use Illuminate\Contracts\Config\Repository;

final class SoundCuePlanner
{
    public function __construct(
        private SoundResolver $resolver,
        Repository $config,
    ) {
        $this->intensityLufs = [
            SceneAmbience::INTENSITY_SUBTLE => (float) $config->get('stories.audio.ambience.intensity_lufs.subtle', -34.0),
            SceneAmbience::INTENSITY_MODERATE => (float) $config->get('stories.audio.ambience.intensity_lufs.moderate', -30.0),
            SceneAmbience::INTENSITY_HEAVY => (float) $config->get('stories.audio.ambience.intensity_lufs.heavy', -27.0),
        ];
        $this->musicLufs = (float) $config->get('stories.audio.mix.music_lufs', -30.0);
    }

    /**
     * @var array<string, float>
     */
    private readonly array $intensityLufs;

    private readonly float $musicLufs;

    /**
     * @param  array{scenes?: list<array<string, mixed>>}  $timings
     * @return list<array{id: string, type: string, role: string, sceneOrder: ?int, query: string, tags: list<string>, minDuration: float, intensity: ?string, kind: ?string}>
     */
    public function cues(Story $story, array $timings = []): array
    {
        $cues = [];
        $durations = $this->sceneDurations($timings);

        foreach ($story->scenes as $scene) {
            $spec = $this->ambienceSpec($story, $scene);
            $duration = $durations[$scene->order] ?? 0.0;
            $cues[] = [
                'id' => 'ambience.'.$scene->order,
                'type' => 'ambience',
                'role' => 'bed',
                'sceneOrder' => $scene->order,
                'query' => $spec->query,
                'tags' => $spec->tags,
                'minDuration' => $duration > 0 ? max(0.05, $duration / 4.0) : 0.0,
                'intensity' => $spec->intensity,
                'kind' => null,
            ];
        }

        foreach ($story->scenes as $scene) {
            foreach ($scene->soundEffectSpecs() as $index => $effect) {
                $cues[] = [
                    'id' => 'sfx.'.$scene->order.'.'.($index + 1),
                    'type' => 'sfx',
                    'role' => 'scene',
                    'sceneOrder' => $scene->order,
                    'query' => $effect->query,
                    'tags' => $effect->tags,
                    'minDuration' => 0.0,
                    'intensity' => null,
                    'kind' => $effect->kind,
                ];
            }
        }

        $musicQuery = $this->musicQuery($story);
        $musicTags = SoundLibraryImporter::tagsFromQuery($musicQuery);

        $cues[] = [
            'id' => 'music.hook',
            'type' => 'music',
            'role' => 'hook',
            'sceneOrder' => null,
            'query' => $musicQuery,
            'tags' => $musicTags,
            'minDuration' => 0.0,
            'intensity' => null,
            'kind' => null,
        ];
        $cues[] = [
            'id' => 'music.climax',
            'type' => 'music',
            'role' => 'climax',
            'sceneOrder' => null,
            'query' => $musicQuery,
            'tags' => $musicTags,
            'minDuration' => 0.0,
            'intensity' => null,
            'kind' => null,
        ];

        return $cues;
    }

    public function targetLufs(string $type, ?string $intensity): float
    {
        if ($type === 'music') {
            return $this->musicLufs;
        }

        if ($type === 'ambience') {
            return $this->intensityLufs[$intensity ?? SceneAmbience::INTENSITY_MODERATE]
                ?? $this->intensityLufs[SceneAmbience::INTENSITY_MODERATE];
        }

        return 0.0;
    }

    private function ambienceSpec(Story $story, StoryScene $scene): SceneAmbience
    {
        if ($scene->ambience instanceof SceneAmbience && $scene->ambience->query !== '') {
            return $scene->ambience;
        }

        foreach ($this->resolver->signalsFor($story) as $signal) {
            if (($signal['type'] ?? '') !== 'ambience') {
                continue;
            }

            return new SceneAmbience(
                query: $signal['query'],
                tags: $signal['tags'],
                intensity: $scene->ambience?->intensity ?? SceneAmbience::INTENSITY_MODERATE,
            );
        }

        return new SceneAmbience(
            query: 'low drone ominous',
            tags: ['drone', 'dread'],
            intensity: SceneAmbience::INTENSITY_MODERATE,
        );
    }

    private function musicQuery(Story $story): string
    {
        $query = trim(implode(' ', $story->tags));

        return $query !== '' ? $query : 'dark ambient drone';
    }

    /**
     * @param  array{scenes?: list<array<string, mixed>>}  $timings
     * @return array<int, float>
     */
    private function sceneDurations(array $timings): array
    {
        $durations = [];

        foreach ($timings['scenes'] ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }

            $order = (int) ($row['order'] ?? 0);
            $duration = (float) ($row['duration'] ?? 0);
            $start = (float) ($row['start'] ?? 0);
            $end = (float) ($row['end'] ?? 0);

            if ($duration <= 0 && $end > $start) {
                $duration = $end - $start;
            }

            if ($order > 0 && $duration > 0) {
                $durations[$order] = $duration;
            }
        }

        return $durations;
    }
}
