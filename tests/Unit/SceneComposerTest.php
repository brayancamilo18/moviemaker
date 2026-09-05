<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\DataObjects\Shot;
use App\Services\Video\SceneComposer;
use Tests\TestCase;

final class SceneComposerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['stories.video.transition_duration' => 0.5]);
    }

    public function test_three_equal_shots_produce_expected_xfade_offsets(): void
    {
        $plan = $this->composer()->calculateOffsets([
            $this->shot(1, 0.0, 5.0, 'zoom_in'),
            $this->shot(2, 5.0, 10.0, 'pan_left'),
            $this->shot(3, 10.0, 15.0, 'zoom_out'),
        ]);

        $this->assertSame([5.0, 10.0], $plan['offsets']);
        $this->assertEqualsWithDelta(15.0, $plan['duration'], 0.001);
    }

    public function test_unequal_shot_durations_accumulate_offsets(): void
    {
        $plan = $this->composer()->calculateOffsets([
            $this->shot(1, 0.0, 3.0, 'zoom_in'),
            $this->shot(2, 3.0, 10.0, 'pan_right'),
            $this->shot(3, 10.0, 12.0, 'zoom_out'),
        ]);

        $this->assertSame([3.0, 10.0], $plan['offsets']);
        $this->assertEqualsWithDelta(12.0, $plan['duration'], 0.001);
    }

    public function test_static_shot_skips_xfade_and_recalculates_later_offsets(): void
    {
        $plan = $this->composer()->calculateOffsets([
            $this->shot(1, 0.0, 5.0, 'zoom_in'),
            $this->shot(2, 5.0, 10.0, 'static'),
            $this->shot(3, 10.0, 15.0, 'pan_left'),
        ]);

        $this->assertNull($plan['offsets'][0]);
        $this->assertEqualsWithDelta(10.0, $plan['offsets'][1], 0.001);
        $this->assertEqualsWithDelta(15.0, $plan['duration'], 0.001);
    }

    public function test_a_hard_cut_before_a_fade_leaves_both_xfade_inputs_on_one_timebase(): void
    {
        // concat devuelve 1/1000000 y los clips llegan en 1/15360: sin igualar la
        // base de tiempo al entrar, el xfade que sigue a un corte seco aborta el
        // render con "input link timebases do not match".
        $graph = $this->filterGraph([null, 10.0]);

        $this->assertStringContainsString('[0]settb=AVTB[s0]', $graph);
        $this->assertStringContainsString('[1]settb=AVTB[s1]', $graph);
        $this->assertStringContainsString('[2]settb=AVTB[s2]', $graph);
        $this->assertStringContainsString('[s0][s1]concat=n=2:v=1:a=0[v1]', $graph);
        $this->assertStringContainsString('[v1][s2]xfade=', $graph);
        $this->assertStringContainsString('[out]', $graph);
    }

    public function test_every_input_is_normalised_however_the_cuts_fall(): void
    {
        foreach ([[0.5], [null], [null, null], [5.0, null, 10.0]] as $offsets) {
            $graph = $this->filterGraph($offsets);

            for ($input = 0; $input <= count($offsets); $input++) {
                $this->assertStringContainsString(
                    sprintf('[%d]settb=AVTB[s%d]', $input, $input),
                    $graph,
                    'Falta normalizar la entrada '.$input,
                );
            }
        }
    }

    /**
     * @param  list<float|null>  $offsets
     */
    private function filterGraph(array $offsets): string
    {
        $method = new \ReflectionMethod(SceneComposer::class, 'filterGraph');

        return (string) $method->invoke($this->composer(), $offsets);
    }

    public function test_total_duration_equals_sum_of_real_shot_durations(): void
    {
        $shots = [
            $this->shot(1, 0.0, 5.0, 'zoom_in'),
            $this->shot(2, 5.0, 10.0, 'pan_left'),
            $this->shot(3, 10.0, 15.0, 'zoom_out'),
        ];
        $plan = $this->composer()->calculateOffsets($shots);
        $realSum = 15.0;

        $this->assertEqualsWithDelta($realSum, $plan['duration'], 0.001);

        $withCut = $this->composer()->calculateOffsets([
            $this->shot(1, 0.0, 5.0, 'zoom_in'),
            $this->shot(2, 5.0, 10.0, 'static'),
            $this->shot(3, 10.0, 15.0, 'pan_left'),
        ]);

        $this->assertEqualsWithDelta($realSum, $withCut['duration'], 0.001);
    }

    private function composer(): SceneComposer
    {
        return $this->app->make(SceneComposer::class);
    }

    private function shot(int $order, float $start, float $end, string $motion): Shot
    {
        return new Shot(
            order: $order,
            sceneOrder: 1,
            start: $start,
            end: $end,
            sourceText: 'The door closed.',
            framing: 'wide',
            motion: $motion,
            subject: 'door',
            threatStage: null,
            imagePath: null,
        );
    }
}
