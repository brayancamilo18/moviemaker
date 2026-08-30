<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\DataObjects\Shot;
use App\DataObjects\Story;
use App\Services\Image\ShotPlanner;
use Tests\TestCase;

final class ShotPlannerOutroTest extends TestCase
{
    public function test_a_twenty_two_second_outro_stays_one_static_environment_shot(): void
    {
        config(['stories.story.outro.enabled' => true]);
        $planner = $this->app->make(ShotPlanner::class);
        $timings = $this->timings();
        $audioDuration = 42.0;
        $maxDuration = (float) config('stories.shots.max_duration');

        $this->assertGreaterThan($maxDuration, 22.0);

        $shots = $planner->plan($timings, $this->storyWithScenes(2), $audioDuration);
        $outro = $this->outroShot($shots);

        $this->assertSame(1, count(array_filter(
            $shots,
            static fn (Shot $shot): bool => $shot->isOutro,
        )));
        $this->assertTrue($outro->isOutro);
        $this->assertSame((int) config('stories.story.outro.scene_order'), $outro->sceneOrder);
        $this->assertSame('static', $outro->motion);
        $this->assertSame('environment', $outro->subject);
        $this->assertNull($outro->threatStage);
        $this->assertEqualsWithDelta(22.0, $outro->end - $outro->start, 0.01);
    }

    public function test_stats_do_not_count_the_outro_shot(): void
    {
        config(['stories.story.outro.enabled' => true]);
        $planner = $this->app->make(ShotPlanner::class);
        $shots = $planner->plan($this->timings(), $this->storyWithScenes(2), 42.0);
        $storyShots = array_values(array_filter(
            $shots,
            static fn (Shot $shot): bool => ! $shot->isOutro,
        ));
        $stats = $planner->stats($shots);

        $this->assertNotEmpty($storyShots);
        $this->assertSame(count($storyShots), $stats['count']);
        $this->assertSame(
            count(array_filter(
                $storyShots,
                static fn (Shot $shot): bool => $shot->framing === 'wide establishing',
            )),
            $stats['framing']['wide establishing'],
        );
        $this->assertSame(
            count(array_filter(
                $storyShots,
                static fn (Shot $shot): bool => $shot->subject === 'environment',
            )),
            $stats['subject']['environment'],
        );
    }

    public function test_shot_durations_including_the_outro_still_cover_the_master(): void
    {
        config(['stories.story.outro.enabled' => true]);
        $planner = $this->app->make(ShotPlanner::class);
        $audioDuration = 42.0;
        $shots = $planner->plan($this->timings(), $this->storyWithScenes(2), $audioDuration);
        $sum = array_sum(array_map(
            static fn (Shot $shot): float => $shot->end - $shot->start,
            $shots,
        ));

        $this->assertNotEmpty(array_filter(
            $shots,
            static fn (Shot $shot): bool => $shot->isOutro,
        ));
        $this->assertEqualsWithDelta($audioDuration, $sum, 0.01);
    }

    /**
     * @param  list<Shot>  $shots
     */
    private function outroShot(array $shots): Shot
    {
        foreach ($shots as $shot) {
            if ($shot->isOutro) {
                return $shot;
            }
        }

        $this->fail('El planificador no generó el plano de outro.');
    }

    /**
     * @return array{sentences: list<array<string, mixed>>, scenes: list<array<string, mixed>>}
     */
    private function timings(): array
    {
        return [
            'sentences' => [
                [
                    'order' => 1,
                    'sceneOrder' => 1,
                    'text' => 'The door closed behind me in the empty hall and I kept walking.',
                    'start' => 0.0,
                    'end' => 7.5,
                    'pauseAfter' => 0.5,
                    'alignment' => 'text',
                ],
                [
                    'order' => 2,
                    'sceneOrder' => 2,
                    'text' => 'Then the whistle came closer along the empty road without a pause.',
                    'start' => 8.0,
                    'end' => 19.5,
                    'pauseAfter' => 0.5,
                    'alignment' => 'text',
                ],
                [
                    'order' => 3,
                    'sceneOrder' => 9000,
                    'text' => 'That was the story for tonight.',
                    'start' => 20.0,
                    'end' => 31.0,
                    'pauseAfter' => 0.45,
                    'alignment' => 'text',
                ],
                [
                    'order' => 4,
                    'sceneOrder' => 9000,
                    'text' => 'Sleep well, if you can.',
                    'start' => 31.0,
                    'end' => 41.5,
                    'pauseAfter' => 0.5,
                    'alignment' => 'text',
                ],
            ],
            'scenes' => [
                [
                    'order' => 1,
                    'start' => 0.0,
                    'end' => 8.0,
                    'duration' => 8.0,
                    'sentenceCount' => 1,
                ],
                [
                    'order' => 2,
                    'start' => 8.0,
                    'end' => 20.0,
                    'duration' => 12.0,
                    'sentenceCount' => 1,
                ],
                [
                    'order' => 9000,
                    'start' => 20.0,
                    'end' => 42.0,
                    'duration' => 22.0,
                    'sentenceCount' => 2,
                ],
            ],
        ];
    }

    private function storyWithScenes(int $count): Story
    {
        $scenes = [];

        for ($order = 1; $order <= $count; $order++) {
            $scenes[] = [
                'order' => $order,
                'narration' => 'The door closed behind me in the empty hall and I kept walking.',
                'imagePrompt' => 'A dim hallway in fog',
                'visualSummary' => 'A dim hallway vanishing into fog at dusk',
            ];
        }

        return Story::fromArray([
            'title' => 'Outro planner fixture',
            'hook' => 'The door closed.',
            'description' => 'A fixture used to test the outro shot plan.',
            'tags' => ['test'],
            'thumbnailPrompt' => 'An empty hallway',
            'scenes' => $scenes,
            'pronunciations' => [],
        ]);
    }
}
