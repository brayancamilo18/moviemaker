<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\DataObjects\Shot;
use App\DataObjects\Story;
use App\Services\Image\ShotPlanner;
use Tests\TestCase;

final class ShotPlannerTest extends TestCase
{
    /**
     * @return array<string, array{sentences: list<array<string, mixed>>, scenes: list<array<string, mixed>>}>
     */
    private function fixtures(): array
    {
        $path = base_path('tests/Fixtures/shot-planner-timings.json');
        $decoded = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        $this->assertIsArray($decoded);

        /** @var array<string, array{sentences: list<array<string, mixed>>, scenes: list<array<string, mixed>>}> $decoded */
        return $decoded;
    }

    public function test_no_shot_exceeds_max_duration_in_any_fixture(): void
    {
        $max = (float) config('stories.shots.max_duration');

        foreach ($this->plans() as $name => $plan) {
            foreach ($plan['shots'] as $shot) {
                $this->assertLessThanOrEqual(
                    $max + 0.001,
                    $shot->end - $shot->start,
                    "El plano {$shot->order} de {$name} supera max_duration.",
                );
            }
        }
    }

    public function test_no_shot_crosses_a_scene_boundary(): void
    {
        foreach ($this->plans() as $name => $plan) {
            $scenes = [];

            foreach ($plan['timings']['scenes'] as $scene) {
                $scenes[(int) $scene['order']] = $scene;
            }

            foreach ($plan['shots'] as $shot) {
                $this->assertArrayHasKey($shot->sceneOrder, $scenes, "{$name} plano {$shot->order}");
                $scene = $scenes[$shot->sceneOrder];

                $this->assertGreaterThanOrEqual(
                    (float) $scene['start'] - 0.001,
                    $shot->start,
                    "{$name} plano {$shot->order} empieza antes de su escena.",
                );
                $this->assertLessThanOrEqual(
                    (float) $scene['end'] + 0.001,
                    $shot->end,
                    "{$name} plano {$shot->order} cruza el final de su escena.",
                );
            }
        }
    }

    public function test_a_twenty_five_second_sentence_splits_into_valid_shots_that_sum_exactly(): void
    {
        $plan = $this->plans()['longSentence'];
        $shots = $plan['shots'];
        $max = (float) config('stories.shots.max_duration');
        $audioEnd = (float) $plan['timings']['scenes'][0]['end'];

        $this->assertGreaterThan(1, count($shots));

        foreach ($shots as $shot) {
            $this->assertSame(1, $shot->sceneOrder);
            $this->assertLessThanOrEqual($max + 0.001, $shot->end - $shot->start);
        }

        $this->assertCoverage($shots, $audioEnd);
        $this->assertEqualsWithDelta(
            $audioEnd,
            array_sum(array_map(static fn (Shot $shot): float => $shot->end - $shot->start, $shots)),
            0.001,
        );
    }

    public function test_a_subsecond_sentence_is_merged_and_does_not_remain_a_lone_shot(): void
    {
        $min = (float) config('stories.shots.min_duration');
        $shots = $this->plans()['shortSentence']['shots'];

        $this->assertNotEmpty($shots);

        foreach ($shots as $shot) {
            $this->assertGreaterThanOrEqual(
                $min - 0.001,
                $shot->end - $shot->start,
                'Quedó un plano suelto más corto que min_duration.',
            );
        }
    }

    public function test_no_consecutive_shots_share_framing_or_motion(): void
    {
        foreach ($this->plans() as $name => $plan) {
            $previous = null;

            foreach ($plan['shots'] as $shot) {
                if ($previous instanceof Shot) {
                    $this->assertNotSame(
                        $previous->framing,
                        $shot->framing,
                        "{$name}: framing repetido entre planos {$previous->order} y {$shot->order}.",
                    );
                    $this->assertNotSame(
                        $previous->motion,
                        $shot->motion,
                        "{$name}: motion repetido entre planos {$previous->order} y {$shot->order}.",
                    );
                }

                $previous = $shot;
            }
        }
    }

    public function test_shot_durations_cover_the_audio_without_gaps_or_overlaps(): void
    {
        foreach ($this->plans() as $name => $plan) {
            $audioEnd = (float) $plan['timings']['scenes'][array_key_last($plan['timings']['scenes'])]['end'];
            $this->assertCoverage($plan['shots'], $audioEnd, $name);
        }
    }

    /**
     * @param  list<Shot>  $shots
     */
    private function assertCoverage(array $shots, float $audioEnd, string $name = ''): void
    {
        $prefix = $name === '' ? '' : "{$name}: ";

        $this->assertNotEmpty($shots, $prefix.'el plan está vacío.');
        $this->assertEqualsWithDelta(0.0, $shots[0]->start, 0.001, $prefix.'el primer plano no empieza en 0.');
        $this->assertEqualsWithDelta($audioEnd, $shots[array_key_last($shots)]->end, 0.001, $prefix.'el último plano no llega al final del audio.');

        $sum = 0.0;

        foreach ($shots as $index => $shot) {
            $duration = $shot->end - $shot->start;
            $sum += $duration;
            $this->assertGreaterThan(0.0, $duration, $prefix."plano {$shot->order} con duración 0.");

            if ($index === 0) {
                continue;
            }

            $previous = $shots[$index - 1];

            $this->assertEqualsWithDelta(
                $previous->end,
                $shot->start,
                0.001,
                $prefix.'hueco o solape de '.abs($shot->start - $previous->end)." s entre planos {$previous->order} y {$shot->order}.",
            );
        }

        $this->assertEqualsWithDelta($audioEnd - $shots[0]->start, $sum, 0.001, $prefix.'la suma de planos no cubre el audio.');
    }

    /**
     * @return array<string, array{timings: array{sentences: list<array<string, mixed>>, scenes: list<array<string, mixed>>}, shots: list<Shot>}>
     */
    private function plans(): array
    {
        $planner = $this->app->make(ShotPlanner::class);
        $plans = [];

        foreach ($this->fixtures() as $name => $timings) {
            $sceneCount = count($timings['scenes']);
            $shots = $planner->plan($timings, $this->storyWithScenes($sceneCount));
            $plans[$name] = [
                'timings' => $timings,
                'shots' => $shots,
            ];
        }

        return $plans;
    }

    private function storyWithScenes(int $count): Story
    {
        $scenes = [];

        for ($order = 1; $order <= $count; $order++) {
            $scenes[] = [
                'order' => $order,
                'narration' => 'The door closed behind me in the empty hall and I kept walking.',
                'imagePrompt' => 'A dim hallway in fog',
                'soundEffect' => null,
                'visualBeats' => ['A dim hallway vanishing into fog'],
            ];
        }

        return Story::fromArray([
            'title' => 'Planner fixture',
            'hook' => 'The door closed.',
            'description' => 'A fixture used to test shot planning.',
            'tags' => ['test'],
            'thumbnailPrompt' => 'An empty hallway',
            'scenes' => $scenes,
            'pronunciations' => [],
        ]);
    }
}
