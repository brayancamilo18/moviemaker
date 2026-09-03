<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\DataObjects\Shot;
use App\DataObjects\Story;
use App\Services\Image\ShotPlanner;
use Tests\TestCase;

final class ShotPlannerIntroTest extends TestCase
{
    public function test_a_fourteen_second_intro_stays_one_static_environment_shot(): void
    {
        config(['stories.story.intro.enabled' => true]);
        $planner = $this->app->make(ShotPlanner::class);
        $maxDuration = (float) config('stories.shots.max_duration');

        $this->assertGreaterThan($maxDuration, 14.0);

        $shots = $planner->plan($this->timings(), $this->story(), 66.0);
        $intro = $this->introShot($shots);

        $this->assertSame(1, count(array_filter(
            $shots,
            static fn (Shot $shot): bool => $shot->isIntro,
        )));
        $this->assertSame((int) config('stories.story.intro.scene_order'), $intro->sceneOrder);
        $this->assertFalse($intro->isOutro);
        $this->assertSame('static', $intro->motion);
        $this->assertSame('environment', $intro->subject);
        $this->assertNull($intro->threatStage);
        $this->assertEqualsWithDelta(14.0, $intro->end - $intro->start, 0.01);
    }

    public function test_the_video_opens_with_the_cold_open_and_then_the_intro(): void
    {
        config(['stories.story.cold_open.enabled' => true]);
        $planner = $this->app->make(ShotPlanner::class);
        $shots = $planner->plan($this->timings(), $this->story(), 66.0);

        $this->assertSame(0.0, $shots[0]->start);
        $this->assertSame((int) config('stories.story.cold_open.scene_order'), $shots[0]->sceneOrder);
        $this->assertLessThan(
            $this->introShot($shots)->start,
            $shots[0]->start,
        );
    }

    public function test_the_cold_open_is_planned_like_any_other_scene(): void
    {
        config(['stories.story.cold_open.enabled' => true]);
        $planner = $this->app->make(ShotPlanner::class);
        $coldOpenOrder = (int) config('stories.story.cold_open.scene_order');
        $shots = $planner->plan($this->timings(), $this->story(), 66.0);
        $coldOpen = array_values(array_filter(
            $shots,
            static fn (Shot $shot): bool => $shot->sceneOrder === $coldOpenOrder,
        ));

        $this->assertNotEmpty($coldOpen);

        foreach ($coldOpen as $shot) {
            $this->assertFalse($shot->isIntro);
            $this->assertFalse($shot->isOutro);
            $this->assertSame('The worst night of my life, seen from the road', $shot->description);
        }
    }

    public function test_stats_skip_the_intro_but_count_the_cold_open(): void
    {
        config(['stories.story.intro.enabled' => true]);
        $planner = $this->app->make(ShotPlanner::class);
        $coldOpenOrder = (int) config('stories.story.cold_open.scene_order');
        $shots = $planner->plan($this->timings(), $this->story(), 66.0);
        $counted = array_values(array_filter(
            $shots,
            static fn (Shot $shot): bool => ! $shot->isIntro && ! $shot->isOutro,
        ));
        $stats = $planner->stats($shots);

        $this->assertNotEmpty(array_filter(
            $counted,
            static fn (Shot $shot): bool => $shot->sceneOrder === $coldOpenOrder,
        ));
        $this->assertSame(count($counted), $stats['count']);
    }

    public function test_shot_durations_including_the_intro_still_cover_the_master(): void
    {
        config(['stories.story.intro.enabled' => true]);
        $planner = $this->app->make(ShotPlanner::class);
        $audioDuration = 66.0;
        $shots = $planner->plan($this->timings(), $this->story(), $audioDuration);
        $sum = array_sum(array_map(
            static fn (Shot $shot): float => $shot->end - $shot->start,
            $shots,
        ));

        $this->assertEqualsWithDelta($audioDuration, $sum, 0.01);
    }

    /**
     * @param  list<Shot>  $shots
     */
    private function introShot(array $shots): Shot
    {
        foreach ($shots as $shot) {
            if ($shot->isIntro) {
                return $shot;
            }
        }

        $this->fail('El planificador no generó el plano de careta.');
    }

    /**
     * @return array{sentences: list<array<string, mixed>>, scenes: list<array<string, mixed>>}
     */
    private function timings(): array
    {
        $coldOpen = (int) config('stories.story.cold_open.scene_order');
        $intro = (int) config('stories.story.intro.scene_order');
        $outro = (int) config('stories.story.outro.scene_order');

        return [
            'sentences' => [
                $this->sentence(1, $coldOpen, 'I heard it walk past the door and I did not move.', 0.0, 7.5),
                $this->sentence(2, $intro, 'You are listening to a story someone swore was true.', 10.0, 16.0),
                $this->sentence(3, $intro, 'How long would you have stayed in that room?', 16.5, 23.5),
                $this->sentence(4, 1, 'The door closed behind me in the empty hall and I kept walking.', 24.0, 31.5),
                $this->sentence(5, 2, 'Then the whistle came closer along the empty road without a pause.', 32.0, 43.5),
                $this->sentence(6, $outro, 'That was the story for tonight.', 44.0, 55.0),
                $this->sentence(7, $outro, 'Sleep well, if you can.', 55.0, 65.5),
            ],
            'scenes' => [
                $this->scene($coldOpen, 0.0, 10.0, 1),
                $this->scene($intro, 10.0, 24.0, 2),
                $this->scene(1, 24.0, 32.0, 1),
                $this->scene(2, 32.0, 44.0, 1),
                $this->scene($outro, 44.0, 66.0, 2),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sentence(int $order, int $sceneOrder, string $text, float $start, float $end): array
    {
        return [
            'order' => $order,
            'sceneOrder' => $sceneOrder,
            'text' => $text,
            'start' => $start,
            'end' => $end,
            'pauseAfter' => 0.5,
            'alignment' => 'text',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function scene(int $order, float $start, float $end, int $sentenceCount): array
    {
        return [
            'order' => $order,
            'start' => $start,
            'end' => $end,
            'duration' => round($end - $start, 3),
            'sentenceCount' => $sentenceCount,
        ];
    }

    private function story(): Story
    {
        return Story::fromArray([
            'title' => 'Intro planner fixture',
            'hook' => 'The door closed.',
            'description' => 'A fixture used to test the opening shot plan.',
            'tags' => ['test'],
            'thumbnailPrompt' => 'An empty hallway',
            'coldOpen' => [
                'narration' => 'I heard it walk past the door and I did not move.',
                'visualSummary' => 'The worst night of my life, seen from the road',
            ],
            'hookLine' => 'How long would you have stayed in that room?',
            'scenes' => [
                [
                    'order' => 1,
                    'narration' => 'The door closed behind me in the empty hall and I kept walking.',
                    'imagePrompt' => 'A dim hallway in fog',
                    'visualSummary' => 'A dim hallway vanishing into fog at dusk',
                ],
                [
                    'order' => 2,
                    'narration' => 'Then the whistle came closer along the empty road without a pause.',
                    'imagePrompt' => 'An empty road in fog',
                    'visualSummary' => 'An empty road swallowed by fog at dusk',
                ],
            ],
            'pronunciations' => [],
        ]);
    }
}
