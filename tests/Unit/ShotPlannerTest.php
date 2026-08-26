<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\DataObjects\Shot;
use App\DataObjects\Story;
use App\DataObjects\VisualBible;
use App\Services\Image\ShotPlanner;
use App\Services\Image\ShotPromptBuilder;
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
                    $max + 3.0 + 0.001,
                    $shot->end - $shot->start,
                    "El plano {$shot->order} de {$name} supera max_duration + 3 s.",
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
            $this->assertLessThanOrEqual($max + 3.0 + 0.001, $shot->end - $shot->start);
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

                    $closing = $shot === $plan['shots'][array_key_last($plan['shots'])]
                        && $shot->motion === 'static'
                        && $shot->subject === 'environment';

                    if (! $closing) {
                        $this->assertNotSame(
                            $previous->motion,
                            $shot->motion,
                            "{$name}: motion repetido entre planos {$previous->order} y {$shot->order}.",
                        );
                    }
                }

                $previous = $shot;
            }
        }
    }

    public function test_split_beat_keeps_original_subject_then_alternates_detail_and_environment(): void
    {
        $planner = $this->app->make(ShotPlanner::class);
        $timings = $this->fixtures()['longSentence'];
        $story = $this->storyWithScenes(1, [[
            'description' => 'A hunched figure seen from behind in fog',
            'subject' => 'protagonist',
            'threatStage' => null,
        ]]);

        $shots = $planner->plan($timings, $story, $this->audioEnd($timings));

        $this->assertGreaterThan(1, count($shots));
        $this->assertSame('protagonist', $shots[0]->subject);
        $this->assertNull($shots[0]->threatStage);

        $expected = ['detail', 'environment'];

        foreach (array_slice($shots, 1) as $index => $shot) {
            $this->assertSame(
                $expected[$index % 2],
                $shot->subject,
                "El plano {$shot->order} debería alternar a {$expected[$index % 2]}.",
            );
            $this->assertNull($shot->threatStage);
        }
    }

    public function test_long_beat_split_into_three_shots_does_not_repeat_the_same_subject(): void
    {
        $planner = $this->app->make(ShotPlanner::class);
        $timings = [
            'sentences' => [[
                'order' => 1,
                'sceneOrder' => 1,
                'text' => 'The road went on through the fog without a single pause in the walking',
                'start' => 0.0,
                'end' => 24.0,
                'pauseAfter' => 0.0,
                'alignment' => 'text',
            ]],
            'scenes' => [[
                'order' => 1,
                'start' => 0.0,
                'end' => 24.0,
                'duration' => 24.0,
                'sentenceCount' => 1,
            ]],
        ];
        $story = $this->storyWithScenes(1, [[
            'description' => 'A hunched figure seen from behind in fog',
            'subject' => 'protagonist',
            'threatStage' => null,
        ]]);

        $shots = $planner->plan($timings, $story, $this->audioEnd($timings));

        $this->assertCount(3, $shots);
        $this->assertSame('protagonist', $shots[0]->subject);
        $this->assertNotSame($shots[0]->subject, $shots[1]->subject);
        $this->assertNotSame($shots[0]->subject, $shots[2]->subject);
        $this->assertNotSame($shots[1]->subject, $shots[2]->subject);
    }

    public function test_threat_prompt_uses_the_matching_stage_descriptor(): void
    {
        $builder = $this->app->make(ShotPromptBuilder::class);
        $bible = $this->bible();
        $story = $this->storyWithScenes(1, [[
            'description' => 'A blurred shape among the distant trees',
            'subject' => 'threat',
            'threatStage' => 'presence',
        ]])->withVisualBible($bible);

        $descriptors = [];

        foreach ($bible->threat['stages'] as $stage) {
            $descriptors[$stage['stage']] = $stage['descriptor'];
        }

        foreach (['hint', 'presence', 'reveal'] as $stage) {
            $prompt = $builder->build(
                $this->shot(subject: 'threat', threatStage: $stage),
                $bible,
                $story,
            );

            $this->assertStringContainsString($descriptors[$stage], $prompt, "Falta el descriptor de {$stage}.");

            foreach ($descriptors as $otherStage => $descriptor) {
                if ($otherStage === $stage) {
                    continue;
                }

                $this->assertStringNotContainsString(
                    $descriptor,
                    $prompt,
                    "El prompt de {$stage} no debe incluir el descriptor de {$otherStage}.",
                );
            }
        }
    }

    public function test_generated_prompts_do_not_contain_no_faces(): void
    {
        $builder = $this->app->make(ShotPromptBuilder::class);
        $bible = $this->bible();
        $story = $this->storyWithScenes(3, [
            [
                'description' => 'A hunched figure seen from behind in fog',
                'subject' => 'protagonist',
                'threatStage' => null,
            ],
            [
                'description' => 'A blurred shape among the distant trees',
                'subject' => 'threat',
                'threatStage' => 'hint',
            ],
            [
                'description' => 'Fog hanging over an empty field at dusk',
                'subject' => 'environment',
                'threatStage' => null,
            ],
        ])->withVisualBible($bible);

        $shots = [
            $this->shot(order: 1, sceneOrder: 1, subject: 'protagonist', threatStage: null),
            $this->shot(order: 2, sceneOrder: 2, subject: 'threat', threatStage: 'hint'),
            $this->shot(order: 3, sceneOrder: 3, subject: 'environment', threatStage: null),
        ];

        foreach ($builder->previewAll($shots, $bible, $story) as $index => $prompt) {
            $this->assertDoesNotMatchRegularExpression(
                '/no faces/i',
                $prompt,
                "El prompt del plano {$shots[$index]->order} contiene 'no faces'.",
            );
            $this->assertStringContainsString('no clear facial features', $prompt);
        }
    }

    public function test_threat_hint_shots_use_only_distant_framing(): void
    {
        $planner = $this->app->make(ShotPlanner::class);
        $timings = $this->fixtures()['singleShotScenes'];
        $hintBeat = [
            'description' => 'A blurred shape among the distant trees',
            'subject' => 'threat',
            'threatStage' => 'hint',
        ];
        $story = $this->storyWithScenes(3, [$hintBeat, $hintBeat, $hintBeat]);

        $shots = $planner->plan($timings, $story, $this->audioEnd($timings));

        $this->assertCount(3, $shots);

        foreach ($shots as $shot) {
            $this->assertSame('threat', $shot->subject);
            $this->assertSame('hint', $shot->threatStage);
            $this->assertContains($shot->framing, ['wide establishing', 'medium shot']);
        }
    }

    public function test_reveal_shots_may_use_close_detail_or_low_angle(): void
    {
        $planner = $this->app->make(ShotPlanner::class);
        $timings = $this->fixtures()['singleShotScenes'];
        $revealBeat = [
            'description' => 'A covered figure filling the center of the frame',
            'subject' => 'threat',
            'threatStage' => 'reveal',
        ];
        $story = $this->storyWithScenes(3, [$revealBeat, $revealBeat, $revealBeat]);

        $shots = $planner->plan($timings, $story, $this->audioEnd($timings));
        $framings = array_map(static fn (Shot $shot): string => $shot->framing, $shots);

        $this->assertCount(3, $shots);
        $this->assertNotEmpty(array_intersect($framings, ['close detail', 'low angle']));

        foreach ($shots as $shot) {
            $this->assertSame('reveal', $shot->threatStage);
            $this->assertContains(
                $shot->framing,
                ['close detail', 'low angle', 'extreme close up', 'over the shoulder'],
            );
        }
    }

    public function test_stats_include_subject_and_threat_stage_counts(): void
    {
        $planner = $this->app->make(ShotPlanner::class);
        $timings = $this->fixtures()['singleShotScenes'];
        $story = $this->storyWithScenes(3, [
            [
                'description' => 'A hunched figure seen from behind in fog',
                'subject' => 'protagonist',
                'threatStage' => null,
            ],
            [
                'description' => 'A blurred shape among the distant trees',
                'subject' => 'threat',
                'threatStage' => 'hint',
            ],
            [
                'description' => 'A covered figure filling the center of the frame',
                'subject' => 'threat',
                'threatStage' => 'reveal',
            ],
        ]);

        $stats = $planner->stats($planner->plan($timings, $story, $this->audioEnd($timings)));

        $this->assertSame(1, $stats['subject']['protagonist']);
        $this->assertSame(2, $stats['subject']['threat']);
        $this->assertSame(1, $stats['threatStage']['hint']);
        $this->assertSame(1, $stats['threatStage']['reveal']);
        $this->assertSame(0, $stats['threatStage']['presence']);
    }

    public function test_shot_durations_cover_the_audio_without_gaps_or_overlaps(): void
    {
        $planner = $this->app->make(ShotPlanner::class);
        $timings = $this->fixtures()['coverage'];
        $speech = 0.0;

        foreach ($timings['sentences'] as $sentence) {
            $speech += (float) $sentence['end'] - (float) $sentence['start'];
        }

        $sceneEnd = $this->audioEnd($timings);
        $audioDuration = $sceneEnd + 11.32;

        $this->assertGreaterThan($speech, $audioDuration, 'El máster de prueba debe ser más largo que las ventanas de frase.');
        $this->assertGreaterThan($sceneEnd, $audioDuration);

        $shots = $planner->plan($timings, $this->storyWithScenes(count($timings['scenes'])), $audioDuration);

        $this->assertCoverage($shots, $audioDuration);
        $last = $shots[array_key_last($shots)];
        $this->assertSame('static', $last->motion);
        $this->assertSame('environment', $last->subject);
        $this->assertEqualsWithDelta(11.32, $last->end - $last->start, 0.01);
        $this->assertEqualsWithDelta(
            $audioDuration,
            array_sum(array_map(static fn (Shot $shot): float => $shot->end - $shot->start, $shots)),
            0.01,
        );
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
     * @param  array{sentences: list<array<string, mixed>>, scenes: list<array<string, mixed>>}  $timings
     */
    private function audioEnd(array $timings): float
    {
        $scenes = $timings['scenes'];

        return (float) $scenes[array_key_last($scenes)]['end'];
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
            $shots = $planner->plan($timings, $this->storyWithScenes($sceneCount), $this->audioEnd($timings));
            $plans[$name] = [
                'timings' => $timings,
                'shots' => $shots,
            ];
        }

        return $plans;
    }

    /**
     * @param  list<array{description: string, subject: string, threatStage: ?string}>  $beatsByScene
     */
    private function storyWithScenes(int $count, array $beatsByScene = []): Story
    {
        $scenes = [];

        for ($order = 1; $order <= $count; $order++) {
            $beat = $beatsByScene[$order - 1] ?? [
                'description' => 'A dim hallway vanishing into fog',
                'subject' => 'environment',
                'threatStage' => null,
            ];

            $scenes[] = [
                'order' => $order,
                'narration' => 'The door closed behind me in the empty hall and I kept walking.',
                'imagePrompt' => 'A dim hallway in fog',
                'soundEffect' => null,
                'visualBeats' => [$beat],
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

    private function bible(): VisualBible
    {
        return VisualBible::fromArray([
            'setting' => 'Abandoned ranch on open grassland under a low sky',
            'era' => '1990s rural',
            'timeOfDay' => 'overcast dusk',
            'weather' => 'heavy fog',
            'palette' => ['rust brown', 'bone white', 'charcoal'],
            'characters' => [[
                'slug' => 'the-walker',
                'bodyDescriptor' => 'tall thin man, worn olive raincoat, hunched shoulders, short dark hair, muddy boots',
                'framingOptions' => ['seen from behind', 'silhouette against light'],
            ]],
            'recurringObjects' => [],
            'avoid' => ['neon signs'],
            'threat' => [
                'nature' => 'a tall whistle-thin silhouette in the grass',
                'stages' => [
                    ['stage' => 'hint', 'descriptor' => 'a maybe-shape among the distant trees'],
                    ['stage' => 'presence', 'descriptor' => 'a hand on the doorframe just outside the light'],
                    ['stage' => 'reveal', 'descriptor' => 'a backlit covered figure filling the frame'],
                ],
            ],
        ]);
    }

    private function shot(
        int $order = 1,
        int $sceneOrder = 1,
        string $subject = 'environment',
        ?string $threatStage = null,
    ): Shot {
        return new Shot(
            order: $order,
            sceneOrder: $sceneOrder,
            start: 0.0,
            end: 4.0,
            sourceText: 'The door closed behind me in the empty hall.',
            framing: 'wide establishing',
            motion: 'static',
            subject: $subject,
            threatStage: $threatStage,
            imagePath: null,
        );
    }
}
