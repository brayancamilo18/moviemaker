<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\JsonLlm;
use App\DataObjects\DirectedSfx;
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

final class ShotDirectorOutroTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
        Http::preventStrayRequests();

        config([
            'stories.story.outro.enabled' => true,
            'stories.llm.provider' => 'gemini',
            'stories.llm.fallback' => '',
            'stories.llm.gemini.api_key' => 'clave-de-prueba',
            'stories.llm.gemini.max_retries' => 0,
        ]);

        $this->app->forgetInstance(JsonLlm::class);
    }

    public function test_the_director_does_not_ask_gemini_for_the_outro_scene(): void
    {
        $this->fakeGemini();

        $shots = $this->plannedShots();
        $directed = $this->app->make(ShotDirector::class)->direct($shots, $this->story(), $this->bible());
        $outro = $this->outroShot($directed);

        $this->assertSame(3, $this->geminiCalls());
        $this->assertSame((string) config('stories.story.outro.image_prompt'), $outro->description);
        $this->assertSame('wide establishing', $outro->framing);
        $this->assertTrue($outro->isOutro);
    }

    public function test_the_outro_prompt_omits_the_visual_bible_setting(): void
    {
        $this->fakeGemini();

        $bible = $this->bible();
        $directed = $this->app->make(ShotDirector::class)->direct($this->plannedShots(), $this->story(), $bible);
        $prompt = $this->app->make(ShotPromptBuilder::class)->build($this->outroShot($directed), $bible);

        $this->assertStringNotContainsString($bible->setting, $prompt);
        $this->assertStringContainsString((string) config('stories.story.outro.image_prompt'), $prompt);
    }

    public function test_the_sfx_director_does_not_ask_gemini_for_the_outro_scene(): void
    {
        $this->fakeGemini();

        $effects = $this->app->make(SfxDirector::class)->direct($this->plannedShots(), $this->story());

        $this->assertSame(3, $this->geminiCalls());
        $this->assertSame([], array_values(array_filter(
            $effects,
            static fn (DirectedSfx $effect): bool => $effect->shotIndex === 4,
        )));
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
    private function outroShot(array $shots): Shot
    {
        foreach ($shots as $shot) {
            if ($shot->isOutro || $shot->sceneOrder === (int) config('stories.story.outro.scene_order')) {
                return $shot;
            }
        }

        $this->fail('No llegó el plano de outro.');
    }

    /**
     * @return list<Shot>
     */
    private function plannedShots(): array
    {
        $outroOrder = (int) config('stories.story.outro.scene_order');

        return [
            $this->shot(1, 1, 0.0, 4.0, false),
            $this->shot(2, 2, 4.0, 8.0, false),
            $this->shot(3, 3, 8.0, 12.0, false),
            $this->shot(4, $outroOrder, 12.0, 34.0, true),
        ];
    }

    private function shot(int $order, int $sceneOrder, float $start, float $end, bool $isOutro): Shot
    {
        return new Shot(
            order: $order,
            sceneOrder: $sceneOrder,
            start: $start,
            end: $end,
            sourceText: 'The door closed behind me in the empty hall.',
            framing: 'medium shot',
            motion: $isOutro ? 'static' : 'zoom_in',
            subject: 'environment',
            threatStage: null,
            description: $isOutro ? '' : 'A dim hallway vanishing into fog at dusk',
            characterSlugs: [],
            imagePath: null,
            isOutro: $isOutro,
        );
    }

    private function story(): Story
    {
        $scenes = [];

        for ($order = 1; $order <= 3; $order++) {
            $scenes[] = [
                'order' => $order,
                'narration' => 'The door closed behind me in the empty hall and I kept walking.',
                'imagePrompt' => 'A dim hallway in fog',
                'visualSummary' => 'A dim hallway vanishing into fog at dusk',
            ];
        }

        return Story::fromArray([
            'title' => 'Outro director fixture',
            'hook' => 'The door closed.',
            'description' => 'A fixture used to test outro direction.',
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
