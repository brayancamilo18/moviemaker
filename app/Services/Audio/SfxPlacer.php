<?php

declare(strict_types=1);

namespace App\Services\Audio;

use App\DataObjects\SceneSoundEffect;
use App\DataObjects\Story;
use App\DataObjects\StoryScene;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use Psr\Log\LoggerInterface;

final class SfxPlacer
{
    private readonly float $lead;

    private readonly float $minGap;

    public function __construct(
        private SoundResolver $resolver,
        private LibraryClipProcessor $processor,
        private SoundVerifier $verifier,
        private Filesystem $files,
        private LoggerInterface $logger,
        Repository $config,
    ) {
        $this->lead = (float) $config->get('stories.audio.sfx.lead_seconds', 0.15);
        $this->minGap = (float) $config->get('stories.audio.sfx.min_gap_seconds', 4.0);
    }

    /**
     * @param  array{sentences?: list<array<string, mixed>>, scenes?: list<array<string, mixed>>}  $timings
     * @param  array<string, array{path: string, gainDb?: float}>  $resolved
     * @return list<AudioTrack>
     */
    public function place(Story $story, array $timings, array $resolved = []): array
    {
        $placements = $this->thin($this->placements($story, $timings));
        $tracks = [];
        $used = [];

        foreach ($placements as $placement) {
            $spec = $placement['spec'];
            $override = $resolved[$placement['cueId']] ?? null;
            $overridePath = is_array($override) ? (string) ($override['path'] ?? '') : '';

            if ($overridePath !== '' && $this->files->isFile($overridePath)) {
                $path = $overridePath;
                $gainDb = (float) ($override['gainDb'] ?? 0.0);
            } else {
                $found = $this->resolver->resolve(
                    $spec->tags,
                    $spec->query,
                    'sfx',
                    0.0,
                    $used,
                );
                $path = $found->path;
                $gainDb = 0.0;
            }

            if ($path === '' || ! $this->files->isFile($path)) {
                $this->logger->warning('SFX sin archivo usable; no se puede colocar.', [
                    'scene' => $placement['sceneOrder'],
                    'query' => $spec->query,
                ]);

                continue;
            }

            $audible = $this->verifier->verify($path, 'sfx', 0.0);

            if (! $audible->passed) {
                $this->logger->warning('SFX inaudible; se omite el golpe.', [
                    'scene' => $placement['sceneOrder'],
                    'query' => $spec->query,
                    'path' => $path,
                    'failures' => $audible->failures,
                ]);

                continue;
            }

            $duration = $this->processor->duration($path);
            $startAt = $placement['startAt'];
            $endAt = round($startAt + $duration, 3);

            if ($endAt <= $startAt) {
                $this->logger->warning('SFX con duración nula; no se puede colocar.', [
                    'scene' => $placement['sceneOrder'],
                    'query' => $spec->query,
                    'path' => $path,
                ]);

                continue;
            }

            $used[] = $path;
            $tracks[] = new AudioTrack(
                path: $path,
                role: AudioTrack::ROLE_SFX,
                startAt: $startAt,
                endAt: $endAt,
                gainDb: $gainDb,
                duckable: false,
                fadeIn: 0.0,
                fadeOut: 0.0,
            );
        }

        return $tracks;
    }

    /**
     * @param  array{sentences?: list<array<string, mixed>>, scenes?: list<array<string, mixed>>}  $timings
     * @return list<array{cueId: string, sceneOrder: int, spec: SceneSoundEffect, startAt: float}>
     */
    private function placements(Story $story, array $timings): array
    {
        $placements = [];

        foreach ($story->scenes as $scene) {
            $sceneStart = $this->sceneStart($timings, $scene->order);

            foreach ($scene->soundEffectSpecs() as $index => $spec) {
                $anchor = $this->locate($spec->anchorText, $scene, $timings);

                if ($spec->anchorText !== '' && ! $anchor['found']) {
                    $this->logger->warning(
                        'Ancla de SFX no encontrada; se coloca al inicio de la escena.',
                        [
                            'scene' => $scene->order,
                            'anchor' => $spec->anchorText,
                            'query' => $spec->query,
                        ],
                    );
                }

                $startAt = $anchor['found']
                    ? round(max(0.0, $anchor['start'] - $this->lead), 3)
                    : $sceneStart;

                $placements[] = [
                    'cueId' => 'sfx.'.$scene->order.'.'.($index + 1),
                    'sceneOrder' => $scene->order,
                    'spec' => $spec,
                    'startAt' => $startAt,
                ];
            }
        }

        return $placements;
    }

    /**
     * Un golpe cada 4 s. Al recortar caen primero los texture; los key se conservan.
     *
     * @param  list<array{sceneOrder: int, spec: SceneSoundEffect, startAt: float}>  $placements
     * @return list<array{cueId: string, sceneOrder: int, spec: SceneSoundEffect, startAt: float}>
     */
    private function thin(array $placements): array
    {
        usort(
            $placements,
            static function (array $left, array $right): int {
                $byTime = $left['startAt'] <=> $right['startAt'];

                if ($byTime !== 0) {
                    return $byTime;
                }

                $leftKey = $left['spec']->kind === SceneSoundEffect::KIND_KEY ? 0 : 1;
                $rightKey = $right['spec']->kind === SceneSoundEffect::KIND_KEY ? 0 : 1;

                return $leftKey <=> $rightKey;
            },
        );

        $kept = [];

        foreach ($placements as $candidate) {
            $conflicts = [];

            foreach ($kept as $index => $existing) {
                if (abs($existing['startAt'] - $candidate['startAt']) < $this->minGap) {
                    $conflicts[] = $index;
                }
            }

            if ($conflicts === []) {
                $kept[] = $candidate;

                continue;
            }

            if ($candidate['spec']->kind === SceneSoundEffect::KIND_KEY) {
                foreach (array_reverse($conflicts) as $index) {
                    if ($kept[$index]['spec']->kind === SceneSoundEffect::KIND_TEXTURE) {
                        $this->logger->info('SFX texture recortado por densidad (un efecto cada 4 s).', [
                            'query' => $kept[$index]['spec']->query,
                            'startAt' => $kept[$index]['startAt'],
                            'keptQuery' => $candidate['spec']->query,
                        ]);
                        unset($kept[$index]);
                    }
                }

                $kept = array_values($kept);
                $still = false;

                foreach ($kept as $existing) {
                    if (abs($existing['startAt'] - $candidate['startAt']) < $this->minGap) {
                        $still = true;
                        break;
                    }
                }

                if (! $still) {
                    $kept[] = $candidate;

                    continue;
                }
            }

            $this->logger->info('SFX recortado por densidad (un efecto cada 4 s).', [
                'query' => $candidate['spec']->query,
                'kind' => $candidate['spec']->kind,
                'startAt' => $candidate['startAt'],
            ]);
        }

        usort(
            $kept,
            static fn (array $left, array $right): int => $left['startAt'] <=> $right['startAt'],
        );

        return $kept;
    }

    /**
     * @param  array{sentences?: list<array<string, mixed>>, scenes?: list<array<string, mixed>>}  $timings
     * @return array{start: float, found: bool}
     */
    private function locate(string $anchorText, StoryScene $scene, array $timings): array
    {
        $needle = $this->normalize($anchorText);
        $sceneStart = $this->sceneStart($timings, $scene->order);

        if ($needle === '') {
            return ['start' => $sceneStart, 'found' => false];
        }

        $sceneMatch = null;
        $anyMatch = null;

        foreach ($timings['sentences'] ?? [] as $sentence) {
            if (! is_array($sentence)) {
                continue;
            }

            $haystack = $this->normalize((string) ($sentence['text'] ?? ''));

            if ($haystack === '' || ! $this->textMatches($haystack, $needle)) {
                continue;
            }

            $start = round((float) ($sentence['start'] ?? 0), 3);
            $order = (int) ($sentence['sceneOrder'] ?? 0);

            if ($order === $scene->order && $sceneMatch === null) {
                $sceneMatch = $start;
            }

            if ($anyMatch === null) {
                $anyMatch = $start;
            }
        }

        if ($sceneMatch !== null) {
            return ['start' => $sceneMatch, 'found' => true];
        }

        if ($anyMatch !== null) {
            return ['start' => $anyMatch, 'found' => true];
        }

        return ['start' => $sceneStart, 'found' => false];
    }

    /**
     * @param  array{scenes?: list<array<string, mixed>>, sentences?: list<array<string, mixed>>}  $timings
     */
    private function sceneStart(array $timings, int $order): float
    {
        foreach ($timings['scenes'] ?? [] as $row) {
            if (is_array($row) && (int) ($row['order'] ?? 0) === $order) {
                return round((float) ($row['start'] ?? 0), 3);
            }
        }

        foreach ($timings['sentences'] ?? [] as $sentence) {
            if (is_array($sentence) && (int) ($sentence['sceneOrder'] ?? 0) === $order) {
                return round((float) ($sentence['start'] ?? 0), 3);
            }
        }

        return 0.0;
    }

    private function textMatches(string $haystack, string $needle): bool
    {
        return $haystack === $needle
            || str_contains($haystack, $needle)
            || str_contains($needle, $haystack);
    }

    private function normalize(string $text): string
    {
        $text = mb_strtolower($text);
        $text = str_replace(["'", '’', '‘'], '', $text);
        $text = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }
}
