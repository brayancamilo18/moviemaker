<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Contracts\JsonLlm;
use App\DataObjects\Shot;
use App\DataObjects\Story;
use App\DataObjects\VisualBible;
use App\Exceptions\LlmGenerationException;
use App\Services\Image\ShotDirector;
use App\Services\Llm\LlmTask;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class ShotDirectorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    public function test_an_extreme_close_up_on_the_threat_becomes_a_low_angle(): void
    {
        $directed = $this->direct([1 => ['subject' => 'threat', 'framing' => 'extreme close up']]);

        $this->assertSame('low angle', $directed[0]->framing);
    }

    public function test_an_extreme_close_up_on_an_object_is_left_alone(): void
    {
        // El primer plano es justo lo que el proveedor gratuito hace bien cuando lo que se acerca
        // es un objeto, así que el remapeo solo toca al ente.
        $directed = $this->direct([1 => ['subject' => 'detail', 'framing' => 'extreme close up']]);

        $this->assertSame('extreme close up', $directed[0]->framing);
    }

    public function test_a_wide_shot_on_the_threat_is_left_alone(): void
    {
        $directed = $this->direct([1 => ['subject' => 'threat', 'framing' => 'wide establishing']]);

        $this->assertSame('wide establishing', $directed[0]->framing);
    }

    public function test_the_narrator_subject_is_no_longer_a_valid_answer(): void
    {
        // La cámara es el oyente: si el modelo devuelve al narrador como sujeto, la escena se
        // reintenta y termina en excepción en vez de colar un cuerpo en cuadro.
        $this->expectException(LlmGenerationException::class);
        $this->expectExceptionMessage("Subject no válido en el plano 1: 'protagonist'.");

        $this->direct([1 => ['subject' => 'protagonist']]);
    }

    public function test_the_instruction_carries_the_configured_threat_quota(): void
    {
        // La cuota la cumple el LLM, no el PHP: lo que aquí se comprueba es que los valores de
        // config llegan al prompt en vez de quedarse en un número escrito a mano.
        config([
            'stories.images.direction.threat_ratio_min' => 0.12,
            'stories.images.direction.threat_ratio_max' => 0.25,
            'stories.images.direction.detail_ratio_max' => 0.35,
        ]);

        $llm = $this->llm();
        $this->director($llm)->direct([$this->shot()], $this->story(), $this->bible());

        $this->assertStringContainsString('Between 12 and 25 percent may be threat', $llm->systemInstruction);
        $this->assertStringContainsString('Up to 35 percent may be detail', $llm->systemInstruction);
    }

    public function test_close_scales_are_reserved_for_one_thing_filling_the_frame(): void
    {
        $llm = $this->llm();
        $this->director($llm)->direct([$this->shot()], $this->story(), $this->bible());

        $this->assertStringContainsString(
            'close detail and extreme close up are for one thing filling the frame, and never for the threat',
            $llm->systemInstruction,
        );
    }

    public function test_the_instruction_keeps_the_narrator_out_of_frame_and_the_rest_as_traces(): void
    {
        $llm = $this->llm();
        $this->director($llm)->direct([$this->shot()], $this->story(), $this->bible());

        $this->assertStringContainsString('THE CAMERA IS THE LISTENER', $llm->systemInstruction);
        $this->assertStringContainsString('The narrator is never in frame', $llm->systemInstruction);
        $this->assertStringContainsString('Traces, not people', $llm->systemInstruction);
    }

    public function test_the_journey_advances_when_the_director_asks_for_a_later_leg(): void
    {
        $directed = $this->direct(
            [
                1 => ['journeyLeg' => 'roadside'],
                2 => ['journeyLeg' => 'yard'],
            ],
            scenes: 2,
        );

        $this->assertSame('roadside', $directed[0]->journeyLeg);
        $this->assertSame('yard', $directed[1]->journeyLeg);
    }

    public function test_the_journey_never_walks_back(): void
    {
        // El recorrido es de ida: un tramo que ya se dejó atrás se corrige al último pisado.
        $directed = $this->direct(
            [
                1 => ['journeyLeg' => 'yard'],
                2 => ['journeyLeg' => 'roadside'],
            ],
            scenes: 2,
        );

        $this->assertSame('yard', $directed[0]->journeyLeg);
        $this->assertSame('yard', $directed[1]->journeyLeg);
    }

    public function test_the_light_never_reopens(): void
    {
        $directed = $this->direct(
            [
                1 => ['lightStage' => 'failing-dusk'],
                2 => ['lightStage' => 'grey-overcast'],
            ],
            scenes: 2,
        );

        $this->assertSame('failing-dusk', $directed[0]->lightStage);
        $this->assertSame('failing-dusk', $directed[1]->lightStage);
    }

    public function test_a_description_naming_a_face_is_thrown_back_at_the_model(): void
    {
        $llm = $this->llm(
            [1 => ['description' => 'A face tilted upward, eyes wide and staring, lit from below']],
            [1 => ['description' => 'Candlelight on a stone wall with a shadow crossing it']],
        );

        $directed = $this->director($llm)->direct([$this->shot()], $this->story(), $this->bible());

        $this->assertSame(2, $llm->calls);
        $this->assertSame('Candlelight on a stone wall with a shadow crossing it', $directed[0]->description);
        $this->assertSame('environment', $directed[0]->subject);
        $this->assertStringContainsString("plano 1 nombra anatomía humana ('face')", $llm->userPrompts[1]);
    }

    public function test_a_description_that_keeps_the_face_degrades_to_the_place(): void
    {
        // El modelo insiste las dos veces: se cambia por el sitio en vez de tirar la escena.
        $llm = $this->llm([1 => ['description' => 'His face grew paler with every number he whispered']]);

        $directed = $this->director($llm)->direct([$this->shot()], $this->story(), $this->bible());

        $this->assertSame(2, $llm->calls);
        $this->assertSame('a two-lane road with no markings running into grassland', $directed[0]->description);
        $this->assertSame('environment', $directed[0]->subject);
        $this->assertNull($directed[0]->threatStage);
    }

    public function test_every_part_the_provider_cannot_draw_is_caught(): void
    {
        foreach (['hands', 'a single finger', 'bare skin', 'his wrist', 'her mouth', 'the teeth', 'one eye'] as $part) {
            $llm = $this->llm([1 => ['description' => "Something with {$part} in the fog"]]);
            $directed = $this->director($llm)->direct([$this->shot()], $this->story(), $this->bible());

            $this->assertSame(
                'a two-lane road with no markings running into grassland',
                $directed[0]->description,
                "'{$part}' debería haber caído.",
            );
        }
    }

    public function test_a_face_that_fills_the_frame_is_allowed(): void
    {
        // El criterio es la escala, no la presencia: llenando el cuadro hay píxeles para
        // resolverla, y una cara nítida no es el problema. Lo era la cara derretida a media
        // distancia.
        $description = 'A face tilted upward, eyes wide and staring, lit from below';

        foreach (['extreme close up', 'close detail'] as $framing) {
            $llm = $this->llm([1 => ['description' => $description, 'framing' => $framing]]);
            $directed = $this->director($llm)->direct([$this->shot()], $this->story(), $this->bible());

            $this->assertSame(1, $llm->calls, "'{$framing}' no debería haber reintentado.");
            $this->assertSame($description, $directed[0]->description);
            $this->assertSame($framing, $directed[0]->framing);
        }
    }

    public function test_a_face_at_walking_distance_is_still_refused(): void
    {
        foreach (['wide establishing', 'medium shot', 'low angle'] as $framing) {
            $llm = $this->llm([1 => ['description' => 'A pale face in the mist', 'framing' => $framing]]);
            $directed = $this->director($llm)->direct([$this->shot()], $this->story(), $this->bible());

            $this->assertSame(2, $llm->calls, "'{$framing}' debería haber reintentado.");
            $this->assertSame(
                'a two-lane road with no markings running into grassland',
                $directed[0]->description,
                "'{$framing}' debería haber degradado.",
            );
        }
    }

    public function test_the_refusal_tells_the_model_both_ways_out(): void
    {
        $llm = $this->llm([1 => ['description' => 'A pale face in the mist', 'framing' => 'medium shot']]);
        $this->director($llm)->direct([$this->shot()], $this->story(), $this->bible());

        $this->assertStringContainsString('demasiado pequeña para resolverla', $llm->userPrompts[1]);
        $this->assertStringContainsString('extreme close up', $llm->userPrompts[1]);
    }

    public function test_a_handle_is_not_a_hand(): void
    {
        // Con límites de palabra: si no, media descripción de objetos se cae por un falso positivo.
        $clean = 'A brass handle on a skinny post beside the faceted glass of a lamp';
        $llm = $this->llm([1 => ['description' => $clean]]);

        $directed = $this->director($llm)->direct([$this->shot()], $this->story(), $this->bible());

        $this->assertSame(1, $llm->calls);
        $this->assertSame($clean, $directed[0]->description);
    }

    public function test_the_threat_is_the_one_figure_that_may_still_be_described(): void
    {
        $description = 'A covered shape at the treeline, hands lost inside the linen';
        $llm = $this->llm([1 => ['description' => $description, 'subject' => 'threat', 'threatStage' => 'hint']]);

        $directed = $this->director($llm)->direct([$this->shot()], $this->story(), $this->bible());

        $this->assertSame(1, $llm->calls);
        $this->assertSame($description, $directed[0]->description);
        $this->assertSame('threat', $directed[0]->subject);
    }

    public function test_the_instruction_makes_the_face_a_matter_of_scale(): void
    {
        $llm = $this->llm();
        $this->director($llm)->direct([$this->shot()], $this->story(), $this->bible());

        $this->assertStringContainsString('A HUMAN FACE OR HAND IS A MATTER OF SCALE', $llm->systemInstruction);
        $this->assertStringContainsString('framing must be extreme close up or close detail', $llm->systemInstruction);
    }

    public function test_the_walk_cannot_run_ahead_of_the_story(): void
    {
        // El fallo que dejaba el 68% del vídeo plantado en el último tramo: una escena temprana
        // pedía el final del recorrido y el suelo lo congelaba ahí para siempre. Cinco tramos y
        // cuatro escenas, así que en la primera solo se puede haber llegado al segundo.
        $directed = $this->direct(
            [
                1 => ['journeyLeg' => 'hallway'],
                2 => ['journeyLeg' => 'threshold'],
                3 => ['journeyLeg' => 'hallway'],
                4 => ['journeyLeg' => 'hallway'],
            ],
            scenes: 4,
        );

        $this->assertSame(
            ['fence-line', 'yard', 'threshold', 'hallway'],
            array_map(static fn (Shot $shot): ?string => $shot->journeyLeg, $directed),
        );
    }

    public function test_the_light_cannot_close_faster_than_the_story(): void
    {
        $directed = $this->direct(
            [
                1 => ['lightStage' => 'single-bulb'],
                2 => ['lightStage' => 'single-bulb'],
            ],
            scenes: 4,
        );

        // Cuatro etapas y cuatro escenas: en la primera solo se ha ganado la más abierta.
        $this->assertSame('grey-overcast', $directed[0]->lightStage);
        $this->assertSame('failing-dusk', $directed[1]->lightStage);
    }

    public function test_a_leg_the_bible_does_not_have_lands_on_the_current_one(): void
    {
        $directed = $this->direct(
            [
                1 => ['journeyLeg' => 'fence-line'],
                2 => ['journeyLeg' => 'the-moon'],
            ],
            scenes: 2,
        );

        $this->assertSame('fence-line', $directed[0]->journeyLeg);
        $this->assertSame('fence-line', $directed[1]->journeyLeg);
    }

    public function test_the_schema_only_offers_the_window_between_the_walk_and_the_story(): void
    {
        $llm = $this->llm([
            1 => ['journeyLeg' => 'yard', 'lightStage' => 'failing-dusk'],
            2 => ['journeyLeg' => 'yard'],
        ]);

        $this->director($llm)->direct(
            [$this->shot(order: 1, sceneOrder: 1), $this->shot(order: 2, sceneOrder: 2)],
            $this->story(2),
            $this->bible(),
        );

        // Cinco tramos y dos escenas: en la primera solo se ofrece hasta el tercero.
        $this->assertSame(
            ['roadside', 'fence-line', 'yard'],
            $this->enumOf($llm->schemas[0], 'journeyLeg'),
        );
        $this->assertSame(
            ['yard', 'threshold', 'hallway'],
            $this->enumOf($llm->schemas[1], 'journeyLeg'),
        );
        $this->assertSame(
            ['failing-dusk', 'headlight-only', 'single-bulb'],
            $this->enumOf($llm->schemas[1], 'lightStage'),
        );
    }

    public function test_the_prompt_suggests_where_a_constant_paced_walk_would_be(): void
    {
        $llm = $this->llm();

        $this->director($llm)->direct(
            [$this->shot(order: 1, sceneOrder: 1), $this->shot(order: 2, sceneOrder: 2)],
            $this->story(2),
            $this->bible(),
        );

        // Cinco tramos y dos escenas: storyProgress vale 0,5 y 1, así que la primera cae en el
        // tramo del medio y la última en el final del recorrido.
        $this->assertSame('yard', $this->promptValue($llm->userPrompts[0], 'suggestedJourneyLeg'));
        $this->assertSame('hallway', $this->promptValue($llm->userPrompts[1], 'suggestedJourneyLeg'));
    }

    public function test_a_bible_without_a_journey_leaves_the_fields_empty(): void
    {
        $llm = $this->llm();
        $directed = $this->director($llm)->direct([$this->shot()], $this->story(), $this->bibleWithoutJourney());

        $this->assertNull($directed[0]->journeyLeg);
        $this->assertNull($directed[0]->lightStage);
        // Un enum vacío es un schema inválido para el proveedor, así que la propiedad no se manda.
        $this->assertArrayNotHasKey('journeyLeg', $llm->schemas[0]['properties']['shots']['items']['properties']);
        $this->assertArrayNotHasKey('lightStage', $llm->schemas[0]['properties']['shots']['items']['properties']);
    }

    /**
     * @param  array<int, array<string, string>>  $rows
     * @return list<Shot>
     */
    private function direct(array $rows, int $scenes = 1): array
    {
        $shots = [];

        foreach (array_keys($rows) as $index => $shotIndex) {
            $shots[] = $this->shot(order: $shotIndex, sceneOrder: min($index + 1, $scenes));
        }

        return $this->director($this->llm($rows))->direct($shots, $this->story($scenes), $this->bible());
    }

    private function director(JsonLlm $llm): ShotDirector
    {
        return new ShotDirector($llm, $this->app->make(Repository::class));
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return list<string>
     */
    private function enumOf(array $schema, string $property): array
    {
        return $schema['properties']['shots']['items']['properties'][$property]['enum'];
    }

    private function promptValue(string $userPrompt, string $key): mixed
    {
        return json_decode($userPrompt, true)[$key] ?? null;
    }

    /**
     * Doble del LLM que responde cada plano con la fila que se le haya dado para ese shotIndex, y
     * guarda con qué instrucción, prompt y schema se le llamó en cada escena.
     *
     * @param  array<int, array<string, string>>  $rows
     */
    private function llm(array $rows = [], array $corrected = []): JsonLlm
    {
        return new class($rows, $corrected) implements JsonLlm
        {
            public string $systemInstruction = '';

            /** @var list<string> */
            public array $userPrompts = [];

            /** @var list<array<string, mixed>> */
            public array $schemas = [];

            public int $calls = 0;

            /**
             * @param  array<int, array<string, string>>  $rows
             * @param  array<int, array<string, string>>  $corrected  lo que devuelve a partir del segundo intento
             */
            public function __construct(private array $rows, private array $corrected = []) {}

            public function generateJson(
                string $systemInstruction,
                string $userPrompt,
                array $schema,
                LlmTask $task = LlmTask::Script,
                float $temperature = 1.0,
            ): array {
                $this->systemInstruction = $systemInstruction;
                $this->userPrompts[] = $userPrompt;
                $this->schemas[] = $schema;
                $this->calls++;

                $rows = $this->calls > 1 && $this->corrected !== [] ? $this->corrected : $this->rows;

                $decoded = json_decode($userPrompt, true);
                $received = is_array($decoded) && is_array($decoded['shots'] ?? null) ? $decoded['shots'] : [];
                $shots = [];

                foreach ($received as $row) {
                    $shotIndex = (int) $row['shotIndex'];

                    $shots[] = [
                        'shotIndex' => $shotIndex,
                        'description' => 'a dim hallway vanishing into fog at dusk',
                        'subject' => 'environment',
                        'framing' => 'wide establishing',
                        'threatStage' => 'reveal',
                        'journeyLeg' => 'roadside',
                        'lightStage' => 'grey-overcast',
                        ...$rows[$shotIndex] ?? [],
                    ];
                }

                return ['shots' => $shots];
            }

            public function isAvailable(): bool
            {
                return true;
            }

            public function name(): string
            {
                return 'doble de pruebas';
            }

            public function fallbackNotice(): ?string
            {
                return null;
            }
        };
    }

    private function shot(int $order = 1, int $sceneOrder = 1): Shot
    {
        return new Shot(
            order: $order,
            sceneOrder: $sceneOrder,
            start: 0.0,
            end: 4.0,
            sourceText: 'The door closed behind me in the empty hall.',
            framing: 'wide establishing',
            motion: 'static',
            subject: 'environment',
            threatStage: null,
            description: 'A dim hallway vanishing into fog at dusk',
            characterSlugs: [],
            imagePath: null,
        );
    }

    private function story(int $scenes = 1): Story
    {
        $rows = [];

        for ($order = 1; $order <= $scenes; $order++) {
            $rows[] = [
                'order' => $order,
                'narration' => 'The door closed behind me in the empty hall and I kept walking.',
                'imagePrompt' => 'A dim hallway in fog',
                'visualSummary' => 'A dim hallway vanishing into fog at dusk',
            ];
        }

        return Story::fromArray([
            'title' => 'Director fixture',
            'hook' => 'The door closed.',
            'description' => 'A fixture used to test shot direction.',
            'tags' => ['test'],
            'thumbnailPrompt' => 'An empty hallway',
            'scenes' => $rows,
            'pronunciations' => [],
        ]);
    }

    private function bible(): VisualBible
    {
        return VisualBible::fromArray([
            ...$this->bibleWithoutJourney()->toArray(),
            'journey' => [
                ['slug' => 'roadside', 'descriptor' => 'a two-lane road with no markings running into grassland'],
                ['slug' => 'fence-line', 'descriptor' => 'a sagging wire fence with a gap trodden through it'],
                ['slug' => 'yard', 'descriptor' => 'a bare yard of packed dirt and rusted drums'],
                ['slug' => 'threshold', 'descriptor' => 'a doorway standing open onto an unlit passage'],
                ['slug' => 'hallway', 'descriptor' => 'a narrow passage with doors on one side only'],
            ],
            'light' => [
                ['slug' => 'grey-overcast', 'descriptor' => 'flat grey light with no shadow anywhere'],
                ['slug' => 'failing-dusk', 'descriptor' => 'the last light draining out of a low sky'],
                ['slug' => 'headlight-only', 'descriptor' => 'two cones of headlight and nothing beyond them'],
                ['slug' => 'single-bulb', 'descriptor' => 'one bare bulb burning at the end of the passage'],
            ],
        ]);
    }

    private function bibleWithoutJourney(): VisualBible
    {
        return VisualBible::fromArray([
            'setting' => 'Abandoned ranch on open grassland under a low sky',
            'era' => '1990s rural',
            'timeOfDay' => 'overcast dusk',
            'weather' => 'heavy fog',
            'palette' => ['rust brown', 'bone white', 'charcoal'],
            'journey' => [],
            'light' => [],
            'recurringObjects' => [],
            'avoid' => ['neon signs'],
            'threat' => [
                'nature' => 'a tall whistle-thin silhouette in the grass',
                'stages' => [
                    ['stage' => 'hint', 'descriptor' => 'a maybe-shape among the distant trees'],
                    ['stage' => 'presence', 'descriptor' => 'a figure just outside the light'],
                    ['stage' => 'reveal', 'descriptor' => 'a backlit covered figure filling the frame'],
                ],
            ],
        ]);
    }
}
