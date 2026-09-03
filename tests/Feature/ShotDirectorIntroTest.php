<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\JsonLlm;
use App\DataObjects\Shot;
use App\DataObjects\Story;
use App\DataObjects\VisualBible;
use App\Services\Audio\SfxDirector;
use App\Services\Image\ShotDirector;
use App\Services\Image\ShotPromptBuilder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

final class ShotDirectorIntroTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
        Http::preventStrayRequests();

        config([
            'stories.story.intro.enabled' => true,
            'stories.story.cold_open.enabled' => true,
            'stories.llm.provider' => 'gemini',
            'stories.llm.fallback' => '',
            'stories.llm.gemini.api_key' => 'clave-de-prueba',
            'stories.llm.gemini.max_retries' => 0,
        ]);

        $this->app->forgetInstance(JsonLlm::class);
    }

    public function test_the_director_skips_the_intro_but_directs_the_cold_open(): void
    {
        $this->fakeGemini();

        $directed = $this->app->make(ShotDirector::class)->direct(
            $this->plannedShots(),
            $this->story(),
            $this->bible(),
        );
        $intro = $this->introShot($directed);

        // Cold open y las dos escenas de historia. La careta no se dirige.
        $this->assertSame(3, $this->geminiCalls());
        $this->assertSame((string) config('stories.story.intro.image_prompt'), $intro->description);
        $this->assertSame('wide establishing', $intro->framing);
        $this->assertTrue($intro->isIntro);
        $this->assertFalse($intro->isOutro);
    }

    public function test_the_cold_open_gets_a_directed_description(): void
    {
        $this->fakeGemini();

        $directed = $this->app->make(ShotDirector::class)->direct(
            $this->plannedShots(),
            $this->story(),
            $this->bible(),
        );
        $coldOpen = $this->shotForScene($directed, (int) config('stories.story.cold_open.scene_order'));

        $this->assertSame('Fog gathering over an empty dirt road at dusk', $coldOpen->description);
        $this->assertFalse($coldOpen->isIntro);
        $this->assertFalse($coldOpen->isOutro);
    }

    public function test_the_intro_prompt_omits_the_visual_bible_setting(): void
    {
        $this->fakeGemini();

        $bible = $this->bible();
        $directed = $this->app->make(ShotDirector::class)->direct($this->plannedShots(), $this->story(), $bible);
        $prompt = $this->app->make(ShotPromptBuilder::class)->build($this->introShot($directed), $bible);

        $this->assertStringNotContainsString($bible->setting, $prompt);
        $this->assertStringContainsString((string) config('stories.story.intro.image_prompt'), $prompt);
    }

    public function test_the_sfx_director_does_not_ask_gemini_for_the_intro_scene(): void
    {
        $this->fakeGemini();

        $this->app->make(SfxDirector::class)->direct($this->plannedShots(), $this->story());

        // Cold open y las dos escenas de historia. Ni careta ni cierre.
        $this->assertSame(3, $this->geminiCalls());
    }

    private function fakeGemini(): void
    {
        Http::fake(function (Request $request) {
            $this->assertStringContainsString('generateContent', $request->url());

            $payload = $request->data();
            $text = is_array($payload) ? (string) ($payload['contents'][0]['parts'][0]['text'] ?? '{}') : '{}';
            $user = json_decode($text, true);
            $user = is_array($user) ? $user : [];

            if (array_key_exists('bible', $user) || array_key_exists('storyProgress', $user)) {
                $this->assertGreaterThanOrEqual(0.0, (float) ($user['storyProgress'] ?? -1.0));

                $shots = [];

                foreach (is_array($user['shots'] ?? null) ? $user['shots'] : [] as $shot) {
                    if (! is_array($shot) || ! isset($shot['shotIndex'])) {
                        continue;
                    }

                    $shots[] = [
                        'shotIndex' => (int) $shot['shotIndex'],
                        'description' => 'Fog gathering over an empty dirt road at dusk',
                        'subject' => 'environment',
                        'framing' => 'medium shot',
                        'threatStage' => '',
                        'journeyLeg' => 'roadside',
                        'lightStage' => 'grey-overcast',
                        'characterSlugs' => [],
                    ];
                }

                return Http::response($this->geminiEnvelope(['shots' => $shots]), 200);
            }

            if (array_key_exists('sceneNarration', $user)) {
                return Http::response($this->geminiEnvelope(['effects' => []]), 200);
            }

            $this->fail('Petición a Gemini sin prompt de dirección ni de efectos.');
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function geminiEnvelope(array $payload): array
    {
        return [
            'candidates' => [
                [
                    'finishReason' => 'STOP',
                    'content' => [
                        'parts' => [
                            ['text' => json_encode($payload, JSON_UNESCAPED_UNICODE)],
                        ],
                    ],
                ],
            ],
        ];
    }

    private function geminiCalls(): int
    {
        return Http::recorded(
            static fn (Request $request): bool => str_contains($request->url(), 'generateContent'),
        )->count();
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

        $this->fail('No llegó el plano de careta.');
    }

    /**
     * @param  list<Shot>  $shots
     */
    private function shotForScene(array $shots, int $sceneOrder): Shot
    {
        foreach ($shots as $shot) {
            if ($shot->sceneOrder === $sceneOrder) {
                return $shot;
            }
        }

        $this->fail("No llegó ningún plano de la escena {$sceneOrder}.");
    }

    /**
     * @return list<Shot>
     */
    private function plannedShots(): array
    {
        return [
            $this->shot(1, (int) config('stories.story.cold_open.scene_order'), 0.0, 8.0),
            $this->shot(2, (int) config('stories.story.intro.scene_order'), 8.0, 22.0, isIntro: true),
            $this->shot(3, 1, 22.0, 26.0),
            $this->shot(4, 2, 26.0, 30.0),
            $this->shot(5, (int) config('stories.story.outro.scene_order'), 30.0, 52.0, isOutro: true),
        ];
    }

    private function shot(
        int $order,
        int $sceneOrder,
        float $start,
        float $end,
        bool $isOutro = false,
        bool $isIntro = false,
    ): Shot {
        $fixed = $isOutro || $isIntro;

        return new Shot(
            order: $order,
            sceneOrder: $sceneOrder,
            start: $start,
            end: $end,
            sourceText: 'The door closed behind me in the empty hall.',
            framing: $fixed ? 'wide establishing' : 'medium shot',
            motion: $fixed ? 'static' : 'zoom_in',
            subject: 'environment',
            threatStage: null,
            description: $fixed ? '' : 'A dim hallway vanishing into fog at dusk',
            characterSlugs: [],
            imagePath: null,
            isOutro: $isOutro,
            isIntro: $isIntro,
        );
    }

    private function story(): Story
    {
        return Story::fromArray([
            'title' => 'Intro director fixture',
            'hook' => 'The door closed.',
            'description' => 'A fixture used to test the opening direction.',
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
                    'narration' => 'Then the whistle came closer along the empty road.',
                    'imagePrompt' => 'An empty road in fog',
                    'visualSummary' => 'An empty road swallowed by fog at dusk',
                ],
            ],
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
            'journey' => [
                ['slug' => 'roadside', 'descriptor' => 'a two-lane road with no markings running into grassland'],
                ['slug' => 'fence-line', 'descriptor' => 'a sagging wire fence with a gap trodden through it'],
                ['slug' => 'yard', 'descriptor' => 'a bare yard of packed dirt and rusted drums'],
            ],
            'light' => [
                ['slug' => 'grey-overcast', 'descriptor' => 'flat grey light with no shadow anywhere'],
                ['slug' => 'failing-dusk', 'descriptor' => 'the last light draining out of a low sky'],
            ],
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
