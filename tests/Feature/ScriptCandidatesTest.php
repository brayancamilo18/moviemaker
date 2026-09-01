<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\JsonLlm;
use App\Enums\StoryMode;
use App\Enums\StoryStatus;
use App\Models\Story;
use App\Services\Llm\LlmTask;
use App\Services\Pipeline\ScriptStep;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

final class ScriptCandidatesTest extends TestCase
{
    use RefreshDatabase;

    private string $storiesDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
        Http::preventStrayRequests();

        $this->storiesDirectory = 'testing/candidates-'.bin2hex(random_bytes(4));

        $config = $this->app->make('config');
        $config->set('stories.output_path', $this->storiesDirectory);
        $config->set('stories.review.enabled', true);
        $config->set('stories.story.candidates', 3);
    }

    protected function tearDown(): void
    {
        $this->app->make(Filesystem::class)->deleteDirectory(storage_path('app/testing'));

        parent::tearDown();
    }

    public function test_three_candidates_are_generated_and_the_best_score_wins(): void
    {
        $this->bindLlm([
            ['title' => 'El pozo tibio', 'score' => 5, 'verdict' => 'revise'],
            ['title' => 'La cuerda del molino', 'score' => 9, 'verdict' => 'publish'],
            ['title' => 'El cencerro sin vaca', 'score' => 6, 'verdict' => 'revise'],
        ]);

        $result = $this->app->make(ScriptStep::class)->run($this->draft());

        $this->assertTrue($result['ok'] ?? false);
        $this->assertSame(3, $result['candidates'] ?? null);
        $this->assertSame('La cuerda del molino', $result['title'] ?? null);
        $this->assertSame(9, $result['score'] ?? null);
        $this->assertSame(
            ['El pozo tibio', 'El cencerro sin vaca'],
            array_column($result['discarded'] ?? [], 'title'),
        );
    }

    public function test_only_the_winner_reaches_the_disk(): void
    {
        $this->bindLlm([
            ['title' => 'El pozo tibio', 'score' => 5, 'verdict' => 'revise'],
            ['title' => 'La cuerda del molino', 'score' => 9, 'verdict' => 'publish'],
            ['title' => 'El cencerro sin vaca', 'score' => 6, 'verdict' => 'revise'],
        ]);

        $result = $this->app->make(ScriptStep::class)->run($this->draft());

        $written = $this->app->make(Filesystem::class)->files(storage_path('app/'.$this->storiesDirectory));

        $this->assertCount(1, $written);
        $this->assertSame($result['slug'].'.json', $written[0]->getFilename());
    }

    public function test_a_tie_on_score_is_broken_by_the_verdict(): void
    {
        $this->bindLlm([
            ['title' => 'El pozo tibio', 'score' => 7, 'verdict' => 'revise'],
            ['title' => 'La cuerda del molino', 'score' => 7, 'verdict' => 'publish'],
        ]);

        $this->app->make('config')->set('stories.story.candidates', 2);

        $result = $this->app->make(ScriptStep::class)->run($this->draft());

        $this->assertSame('La cuerda del molino', $result['title'] ?? null);
    }

    public function test_without_review_only_one_candidate_is_generated(): void
    {
        $this->bindLlm([
            ['title' => 'El pozo tibio', 'score' => 5, 'verdict' => 'revise'],
        ]);

        $result = $this->app->make(ScriptStep::class)->run($this->draft(), null, ['skip_review' => true]);

        $this->assertSame(1, $result['candidates'] ?? null);
        $this->assertSame([], $result['discarded'] ?? null);
        $this->assertArrayHasKey('score', $result);
        $this->assertNull($result['score']);
    }

    private function draft(): Story
    {
        return Story::factory()->create([
            'status' => StoryStatus::Draft,
            'mode' => StoryMode::Original,
            'lore_slug' => null,
        ]);
    }

    /**
     * Un LLM que devuelve un guion distinto y una nota distinta en cada pareja generación/revisión,
     * para poder afirmar cuál de los candidatos gana.
     *
     * @param  list<array{title: string, score: int, verdict: string}>  $candidates
     */
    private function bindLlm(array $candidates): void
    {
        $scripts = [];
        $reviews = [];

        foreach ($candidates as $candidate) {
            $scripts[] = $this->scriptFixture($candidate['title']);
            $reviews[] = [
                'nonNativePhrases' => [],
                'clichedElements' => [],
                'tensionDips' => [],
                'ttsRisks' => [],
                'score' => $candidate['score'],
                'verdict' => $candidate['verdict'],
            ];
        }

        $this->app->instance(JsonLlm::class, new class($scripts, $reviews) implements JsonLlm
        {
            private int $scriptCalls = 0;

            private int $reviewCalls = 0;

            /**
             * @param  list<array<string, mixed>>  $scripts
             * @param  list<array<string, mixed>>  $reviews
             */
            public function __construct(
                private array $scripts,
                private array $reviews,
            ) {}

            public function generateJson(
                string $systemInstruction,
                string $userPrompt,
                array $schema,
                LlmTask $task = LlmTask::Script,
                float $temperature = 1.0,
                ?int $maxTokensOverride = null,
            ): array {
                if ($task === LlmTask::Review) {
                    return $this->reviews[$this->reviewCalls++] ?? [];
                }

                return $this->scripts[$this->scriptCalls++] ?? [];
            }

            public function isAvailable(): bool
            {
                return true;
            }

            public function name(): string
            {
                return 'fake';
            }

            public function fallbackNotice(): ?string
            {
                return null;
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function scriptFixture(string $title): array
    {
        $json = file_get_contents(base_path('tests/Fixtures/story-response.json'));

        $this->assertNotFalse($json);

        /** @var array<string, mixed> $data */
        $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        $data['title'] = $title;

        return $data;
    }
}
