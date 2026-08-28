<?php

declare(strict_types=1);

namespace App\Services\Audio;

use App\DataObjects\DirectedSfx;
use App\DataObjects\NarrationWord;
use App\DataObjects\ResolvedSound;
use App\DataObjects\Shot;
use App\DataObjects\SoundCredit;
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
        private SfxAnchor $anchor,
        private Filesystem $files,
        private LoggerInterface $logger,
        Repository $config,
    ) {
        $this->lead = (float) $config->get('stories.audio.sfx.lead_seconds', 0.15);
        $this->minGap = (float) $config->get('stories.audio.sfx.min_gap_seconds', 4.0);
    }

    /**
     * `credits` lleva la procedencia de los clips resueltos aquí, en tiempo de mezcla: no están en
     * sounds.json, así que sin esto acabarían en el vídeo sin crédito.
     *
     * @param  list<Shot>  $shots
     * @param  list<DirectedSfx>  $effects
     * @param  array<string, array{path: string, gainDb?: float}>  $resolved
     * @param  list<NarrationWord>  $words  Palabras del máster con su ventana real. Sin ellas ningún
     *                                      golpe tiene ancla y no se coloca ninguno.
     * @return array{
     *     tracks: list<AudioTrack>,
     *     skipped: list<array<string, mixed>>,
     *     credits: array<string, SoundCredit>
     * }
     */
    public function place(array $shots, array $effects, array $resolved = [], array $words = []): array
    {
        $plan = $this->plan($shots, $effects, $words);
        $placements = $this->thin($plan['placements']);
        $tracks = [];
        $credits = [];
        $used = [];

        foreach ($placements as $placement) {
            $effect = $placement['effect'];
            $override = $resolved[$placement['cueId']] ?? null;
            $overridePath = is_array($override) ? (string) ($override['path'] ?? '') : '';
            $found = null;

            if ($overridePath !== '' && $this->files->isFile($overridePath)) {
                // El gainDb del override ya salió de LibraryClipProcessor::sfxGainDb() al escribir
                // sounds.json: recalcularlo aquí desharía cualquier ajuste manual del cue.
                $path = $overridePath;
                $gainDb = (float) ($override['gainDb'] ?? 0.0);
            } else {
                $found = $this->resolver->resolve(
                    $effect->tags,
                    $effect->query,
                    'sfx',
                    0.0,
                    $used,
                );
                $path = $found->path;
                $gainDb = null;
            }

            if ($path === '' || ! $this->files->isFile($path)) {
                $this->logger->warning('SFX sin archivo usable; no se puede colocar.', [
                    'shot' => $effect->shotIndex,
                    'query' => $effect->query,
                ]);

                continue;
            }

            $audible = $this->verifier->verify($path, 'sfx', 0.0);

            if (! $audible->passed) {
                $this->logger->warning('SFX inaudible; se omite el golpe.', [
                    'shot' => $effect->shotIndex,
                    'query' => $effect->query,
                    'path' => $path,
                    'failures' => $audible->failures,
                ]);

                continue;
            }

            $duration = $this->processor->duration($path);
            // startAt es el instante en el que se quiere oír el golpe; el fichero tiene que entrar
            // antes, tanto como dure su cabeza muerta, para que el golpe caiga donde toca.
            $onset = $this->processor->onsetSeconds($path);
            $startAt = round(max(0.0, $placement['startAt'] - $onset), 3);
            $endAt = round($startAt + $duration, 3);

            if ($endAt <= $startAt) {
                $this->logger->warning('SFX con duración nula; no se puede colocar.', [
                    'shot' => $effect->shotIndex,
                    'query' => $effect->query,
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
                gainDb: $gainDb ?? $this->processor->sfxGainDb($path),
                duckable: false,
                fadeIn: 0.0,
                fadeOut: 0.0,
            );

            if ($found instanceof ResolvedSound) {
                $credits[$path] = SoundCredit::fromResolved($placement['cueId'], 'sfx', 'scene', $found, $path);
            }
        }

        return [
            'tracks' => $tracks,
            'skipped' => $plan['skipped'],
            'credits' => $credits,
        ];
    }

    /**
     * @param  list<Shot>  $shots
     * @param  list<DirectedSfx>  $effects
     * @param  list<NarrationWord>  $words
     * @return array{
     *     placements: list<array{cueId: string, shotIndex: int, effect: DirectedSfx, startAt: float}>,
     *     skipped: list<array<string, mixed>>
     * }
     */
    private function plan(array $shots, array $effects, array $words): array
    {
        $byOrder = [];

        foreach ($shots as $shot) {
            $byOrder[$shot->order] = $shot;
        }

        $placements = [];
        $skipped = [];
        $indexByShot = [];

        foreach ($effects as $effect) {
            $shot = $byOrder[$effect->shotIndex] ?? null;
            $indexByShot[$effect->shotIndex] = ($indexByShot[$effect->shotIndex] ?? 0) + 1;

            if (! $shot instanceof Shot) {
                $skipped[] = [
                    'shot' => $effect->shotIndex,
                    'query' => $effect->query,
                    'reason' => 'shot_not_found',
                ];

                continue;
            }

            // Sin la palabra que lo nombra el golpe no se coloca. Estimarlo dentro del plano lo deja
            // a uno o dos segundos de donde la voz lo anuncia, y un transitorio a esa distancia no
            // suena a ambiente: suena a error. Vale más el silencio.
            $anchorAt = $this->anchor->resolve($shot, $effect, $words);

            if ($anchorAt === null) {
                $skipped[] = [
                    'shot' => $effect->shotIndex,
                    'query' => $effect->query,
                    'anchorWord' => $effect->anchorWord,
                    'reason' => $effect->anchorWord === '' ? 'anchor_missing' : 'anchor_not_found',
                ];

                continue;
            }

            $startAt = round(max(0.0, $anchorAt - $this->lead), 3);

            $placements[] = [
                'cueId' => 'sfx.'.$effect->shotIndex.'.'.$indexByShot[$effect->shotIndex],
                'shotIndex' => $effect->shotIndex,
                'effect' => $effect,
                'startAt' => $startAt,
            ];
        }

        return [
            'placements' => $placements,
            'skipped' => $skipped,
        ];
    }

    /**
     * Un golpe cada 4 s. Al recortar caen primero los texture; los key se conservan.
     *
     * @param  list<array{cueId: string, shotIndex: int, effect: DirectedSfx, startAt: float}>  $placements
     * @return list<array{cueId: string, shotIndex: int, effect: DirectedSfx, startAt: float}>
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

                $leftKey = $left['effect']->importance === DirectedSfx::IMPORTANCE_KEY ? 0 : 1;
                $rightKey = $right['effect']->importance === DirectedSfx::IMPORTANCE_KEY ? 0 : 1;

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

            if ($candidate['effect']->importance === DirectedSfx::IMPORTANCE_KEY) {
                foreach (array_reverse($conflicts) as $index) {
                    if ($kept[$index]['effect']->importance === DirectedSfx::IMPORTANCE_TEXTURE) {
                        $this->logger->info('SFX texture recortado por densidad (un efecto cada 4 s).', [
                            'query' => $kept[$index]['effect']->query,
                            'startAt' => $kept[$index]['startAt'],
                            'keptQuery' => $candidate['effect']->query,
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
                'query' => $candidate['effect']->query,
                'kind' => $candidate['effect']->importance,
                'startAt' => $candidate['startAt'],
            ]);
        }

        usort(
            $kept,
            static fn (array $left, array $right): int => $left['startAt'] <=> $right['startAt'],
        );

        return $kept;
    }
}
