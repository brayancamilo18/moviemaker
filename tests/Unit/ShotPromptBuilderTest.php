<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\DataObjects\Shot;
use App\DataObjects\VisualBible;
use App\Services\Image\ShotPromptBuilder;
use Tests\TestCase;

final class ShotPromptBuilderTest extends TestCase
{
    public function test_a_realistic_bible_keeps_the_negatives_and_the_style_suffix(): void
    {
        $prompt = $this->app->make(ShotPromptBuilder::class)->build(
            $this->shot(subject: 'threat', threatStage: 'reveal'),
            $this->richBible(),
        );

        $this->assertStringContainsString('no clear facial features', $prompt);
        $this->assertStringContainsString('no direct eye contact', $prompt);
        $this->assertStringContainsString('no crowds of people', $prompt);
        $this->assertStringContainsString($this->styleSuffixTail(), $prompt);
        $this->assertStringContainsString($this->styleSuffix(), $prompt);
    }

    public function test_the_descriptive_part_stays_within_the_configured_word_cap(): void
    {
        $builder = $this->app->make(ShotPromptBuilder::class);
        $max = (int) config('stories.images.max_prompt_words');
        $bible = $this->richBible();

        $shots = [
            $this->shot(order: 1, subject: 'threat', threatStage: 'reveal', journeyLeg: 'hallway', lightStage: 'single-bulb'),
            $this->shot(order: 2, subject: 'detail', journeyLeg: 'threshold', lightStage: 'failing-dusk'),
            $this->shot(order: 3, subject: 'environment', journeyLeg: 'roadside', lightStage: 'grey-overcast'),
        ];

        foreach ($builder->previewAll($shots, $bible) as $index => $prompt) {
            $this->assertLessThanOrEqual(
                $max,
                count(preg_split('/\s+/u', $this->descriptivePart($prompt), -1, PREG_SPLIT_NO_EMPTY) ?: []),
                "La parte descriptiva del plano {$shots[$index]->order} pasa del tope de palabras.",
            );
            // El tope no gobierna a estos dos: por apretado que vaya el plano, siguen enteros.
            $this->assertStringContainsString($this->styleSuffix(), $prompt);
            $this->assertStringContainsString('no crowds of people', $prompt);
        }
    }

    public function test_the_journey_leg_puts_the_camera_where_the_walk_is(): void
    {
        $prompt = $this->builder()->build(
            $this->shot(journeyLeg: 'threshold'),
            $this->leanBible(),
        );

        $this->assertStringContainsString('a doorway standing open onto an unlit passage', $prompt);
        // El ancla sigue viajando al lado del tramo: es el hilo que hace que cien planos sean el
        // mismo sitio y no siete sitios distintos.
        $this->assertStringContainsString('abandoned ranch on open grassland', $prompt);
        $this->assertStringNotContainsString('a two-lane road with no markings', $prompt);
    }

    public function test_a_shot_without_a_leg_falls_back_to_the_setting_alone(): void
    {
        $prompt = $this->builder()->build($this->shot(), $this->leanBible());

        $this->assertStringContainsString('abandoned ranch on open grassland', $prompt);
        $this->assertStringNotContainsString('a doorway standing open onto an unlit passage', $prompt);
        $this->assertStringNotContainsString('a two-lane road with no markings', $prompt);
    }

    public function test_the_light_stage_replaces_the_locked_time_of_day(): void
    {
        $prompt = $this->builder()->build(
            $this->shot(lightStage: 'single-bulb'),
            $this->leanBible(),
        );

        $this->assertStringContainsString('one bare bulb burning at the end of the passage', $prompt);
        $this->assertStringNotContainsString('overcast dusk', $prompt);
    }

    public function test_a_shot_without_a_light_stage_keeps_the_locked_time_of_day(): void
    {
        // Un shots.json dirigido antes de que existieran las etapas no se queda sin luz.
        $prompt = $this->builder()->build($this->shot(), $this->leanBible());

        $this->assertStringContainsString('overcast dusk', $prompt);
    }

    public function test_a_threat_shot_always_gets_an_occlusion(): void
    {
        // El ente es la única figura que puede salir en cuadro, y sale ocultado sin excepción: un
        // plano con alguien dentro y sin encoding de ocultación es el que devuelve media cara.
        $prompt = $this->builder()->build(
            $this->shot(order: 2, subject: 'threat', threatStage: 'hint'),
            $this->leanBible(),
        );

        $this->assertStringContainsString('backlit figure', $prompt);
        $this->assertStringContainsString('a maybe-shape among the distant trees', $prompt);
    }

    public function test_the_occlusion_rotates_with_the_shot_order(): void
    {
        $builder = $this->builder();
        $bible = $this->leanBible();
        $seen = [];

        foreach ([1, 2, 3] as $order) {
            $seen[] = $builder->build(
                $this->shot(order: $order, subject: 'threat', threatStage: 'hint'),
                $bible,
            );
        }

        $this->assertStringContainsString('silhouette against a light source', $seen[0]);
        $this->assertStringContainsString('backlit figure', $seen[1]);
        $this->assertStringContainsString('features lost in shadow', $seen[2]);
    }

    public function test_a_shot_without_the_threat_subject_gets_no_occlusion(): void
    {
        $prompt = $this->builder()->build(
            $this->shot(order: 2, subject: 'environment'),
            $this->leanBible(),
        );

        foreach (['backlit figure', 'seen from behind', 'silhouette against a light source'] as $occlusion) {
            $this->assertStringNotContainsString($occlusion, $prompt);
        }
    }

    public function test_the_threat_outranks_the_journey_when_words_run_out(): void
    {
        config(['stories.images.max_prompt_words' => 26]);

        $prompt = $this->builder()->build(
            $this->shot(subject: 'threat', threatStage: 'reveal', journeyLeg: 'hallway'),
            $this->richBible(),
        );

        $this->assertStringContainsString('a backlit covered figure filling the frame', $prompt);
        $this->assertStringContainsString('silhouette against a light source', $prompt);
        $this->assertStringNotContainsString('broken fence line', $prompt);
        $this->assertStringNotContainsString('rust brown', $prompt);
    }

    public function test_a_block_that_arrives_twice_is_written_once(): void
    {
        $bible = VisualBible::fromArray([
            ...$this->leanBible()->toArray(),
            // La biblia bloquea hora y clima por separado y el LLM puede repetir la misma cadena.
            'timeOfDay' => 'thin drizzle',
            'weather' => 'thin drizzle',
        ]);

        $prompt = $this->builder()->build($this->shot(), $bible);

        $this->assertSame(1, substr_count($prompt, 'thin drizzle'));
    }

    public function test_a_block_dropped_for_space_does_not_let_cheaper_blocks_in(): void
    {
        // Con este tope entran la description, el ente y el tramo, pero no el setting de 20
        // palabras: nada de menor prioridad que el setting puede aprovechar el hueco que deja.
        config(['stories.images.max_prompt_words' => 40]);

        $prompt = $this->builder()->build(
            $this->shot(subject: 'threat', threatStage: 'reveal', journeyLeg: 'hallway', lightStage: 'single-bulb'),
            $this->richBible(),
        );

        $this->assertStringContainsString('a dim hallway vanishing into fog at dusk', $prompt);
        $this->assertStringContainsString('a backlit covered figure filling the frame', $prompt);
        $this->assertStringContainsString('a narrow passage with doors on one side only', $prompt);
        $this->assertStringNotContainsString('broken fence line', $prompt);
        $this->assertStringNotContainsString('wide establishing', $prompt);
        $this->assertStringNotContainsString('rust brown', $prompt);
    }

    private function builder(): ShotPromptBuilder
    {
        $this->app->forgetInstance(ShotPromptBuilder::class);

        return $this->app->make(ShotPromptBuilder::class);
    }

    /**
     * Lo que va antes del sufijo de estilo, que es donde empieza lo que no entra en el presupuesto.
     */
    private function descriptivePart(string $prompt): string
    {
        return trim(explode($this->styleSuffix(), $prompt)[0], " ,\t\n");
    }

    private function styleSuffix(): string
    {
        return trim((string) config('stories.image_style_suffix'));
    }

    private function styleSuffixTail(): string
    {
        $words = preg_split('/\s+/u', $this->styleSuffix(), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return implode(' ', array_slice($words, -4));
    }

    /**
     * Biblia del tamaño que pide de verdad VisualBibleGenerator: setting largo, siete tramos de
     * recorrido, cuatro etapas de luz y seis entradas en avoid.
     */
    private function richBible(): VisualBible
    {
        return VisualBible::fromArray([
            'setting' => 'abandoned ranch on open grassland under a low sky beyond a broken fence line and a dry riverbed at dusk',
            'era' => '1990s rural',
            'timeOfDay' => 'overcast dusk',
            'weather' => 'heavy fog',
            'palette' => ['rust brown', 'bone white', 'charcoal'],
            'journey' => $this->legs(),
            'light' => $this->lightStages(),
            'recurringObjects' => [],
            'avoid' => [
                'neon signs',
                'modern cars',
                'visible weapons',
                'legible signage',
                'clean bright interiors',
                'crowds of people',
            ],
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

    /**
     * Biblia corta: cabe entera en el presupuesto, así que sirve para comprobar lo que hace el
     * builder cuando no tiene que descartar nada.
     */
    private function leanBible(): VisualBible
    {
        return VisualBible::fromArray([
            'setting' => 'abandoned ranch on open grassland',
            'era' => '1990s rural',
            'timeOfDay' => 'overcast dusk',
            'weather' => 'heavy fog',
            'palette' => ['rust brown', 'charcoal'],
            'journey' => $this->legs(),
            'light' => $this->lightStages(),
            'recurringObjects' => [],
            'avoid' => [],
            'threat' => [
                'nature' => 'a tall whistle-thin silhouette in the grass',
                'stages' => [
                    ['stage' => 'hint', 'descriptor' => 'a maybe-shape among the distant trees'],
                    ['stage' => 'reveal', 'descriptor' => 'a backlit covered figure filling the frame'],
                ],
            ],
        ]);
    }

    /**
     * @return list<array{slug: string, descriptor: string}>
     */
    private function legs(): array
    {
        return [
            ['slug' => 'roadside', 'descriptor' => 'a two-lane road with no markings running into grassland'],
            ['slug' => 'fence-line', 'descriptor' => 'a sagging wire fence with a gap trodden through it'],
            ['slug' => 'yard', 'descriptor' => 'a bare yard of packed dirt and rusted drums'],
            ['slug' => 'threshold', 'descriptor' => 'a doorway standing open onto an unlit passage'],
            ['slug' => 'hallway', 'descriptor' => 'a narrow passage with doors on one side only'],
        ];
    }

    /**
     * @return list<array{slug: string, descriptor: string}>
     */
    private function lightStages(): array
    {
        return [
            ['slug' => 'grey-overcast', 'descriptor' => 'flat grey light with no shadow anywhere'],
            ['slug' => 'failing-dusk', 'descriptor' => 'the last light draining out of a low sky'],
            ['slug' => 'headlight-only', 'descriptor' => 'two cones of headlight and nothing beyond them'],
            ['slug' => 'single-bulb', 'descriptor' => 'one bare bulb burning at the end of the passage'],
        ];
    }

    private function shot(
        int $order = 1,
        string $subject = 'environment',
        ?string $threatStage = null,
        string $framing = 'wide establishing',
        ?string $journeyLeg = null,
        ?string $lightStage = null,
    ): Shot {
        return new Shot(
            order: $order,
            sceneOrder: 1,
            start: 0.0,
            end: 4.0,
            sourceText: 'The door closed behind me in the empty hall.',
            framing: $framing,
            motion: 'static',
            subject: $subject,
            threatStage: $threatStage,
            journeyLeg: $journeyLeg,
            lightStage: $lightStage,
            description: 'a dim hallway vanishing into fog at dusk',
            characterSlugs: [],
            imagePath: null,
        );
    }
}
