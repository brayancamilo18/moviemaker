<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\JsonLlm;
use App\Enums\ReviewVerdict;
use App\Enums\StoryMode;
use App\Enums\StoryStatus;
use App\Exceptions\LlmGenerationException;
use App\Jobs\ReviewStory;
use App\Jobs\RunPipelineStep;
use App\Models\Story;
use App\Models\StoryEvent;
use App\Services\Llm\LlmTask;
use App\Services\Llm\LlmUsageMeter;
use App\Services\Pipeline\PipelineDispatcher;
use App\Services\Pipeline\PipelineProgress;
use App\Services\Pipeline\ScriptStep;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;
use Throwable;

final class PipelineJobTest extends TestCase
{
    use RefreshDatabase;

    private ?string $storiesDir = null;

    protected function tearDown(): void
    {
        if ($this->storiesDir !== null) {
            $this->app->make(Filesystem::class)->deleteDirectory(storage_path('app/'.$this->storiesDir));
        }

        parent::tearDown();
    }

    public function test_a_step_exception_marks_the_story_failed_and_records_the_step(): void
    {
        $story = $this->runFailingStep();

        $this->assertSame(StoryStatus::Failed, $story->status);
        $this->assertSame('images', $story->failed_step);
        $this->assertNotNull($story->failed_message);
        $this->assertNotSame('', $story->failed_message);

        $event = $story->events()->where('type', 'step_failed')->first();

        $this->assertInstanceOf(StoryEvent::class, $event);
        $this->assertSame(StoryStatus::Draft->value, $event->from_status);
        $this->assertSame(StoryStatus::Failed->value, $event->to_status);
        $this->assertSame('images', $event->payload['step'] ?? null);
    }

    public function test_a_failing_step_keeps_the_original_exception_as_previous(): void
    {
        $original = new LlmGenerationException('Motivo: max_tokens.');
        $story = Story::factory()->create([
            'status' => StoryStatus::Draft,
            'mode' => StoryMode::Original,
            'lore_slug' => null,
        ]);
        $this->bindThrowingScriptLlm($original);

        $result = $this->app->make(ScriptStep::class)->run($story);

        $this->assertFalse($result['ok'] ?? true);
        $this->assertSame($original, $result['exception'] ?? null);

        try {
            $this->app->call([new RunPipelineStep($story->id, 'script', chain: false), 'handle']);
            $this->fail('Se esperaba RuntimeException.');
        } catch (RuntimeException $exception) {
            $this->assertSame($original, $exception->getPrevious());
        }
    }

    public function test_a_job_failure_writes_the_cause_line_into_failed_message(): void
    {
        $original = new LlmGenerationException('Motivo: max_tokens.');
        $story = Story::factory()->create([
            'status' => StoryStatus::Draft,
            'mode' => StoryMode::Original,
            'lore_slug' => null,
        ]);
        $this->bindThrowingScriptLlm($original);

        try {
            $this->app->make(Dispatcher::class)->dispatch(new RunPipelineStep($story->id, 'script', chain: false));
        } catch (Throwable) {
        }

        $fresh = $story->fresh();

        $this->assertInstanceOf(Story::class, $fresh);
        $this->assertSame(StoryStatus::Failed, $fresh->status);
        $this->assertSame(
            "Motivo: max_tokens.\n\nCausa: App\\Exceptions\\LlmGenerationException: Motivo: max_tokens.",
            $fresh->failed_message,
        );
    }

    public function test_usage_is_added_to_the_story_and_recorded_as_llm_usage(): void
    {
        $story = Story::factory()->create([
            'llm_cost_usd' => 0.5,
            'llm_input_tokens' => 100,
            'llm_output_tokens' => 20,
        ]);
        $meter = $this->app->make(LlmUsageMeter::class);
        $meter->record('anthropic', 'claude-haiku-4-5', LlmTask::Script, 1_000_000, 200_000);

        $record = new ReflectionMethod(RunPipelineStep::class, 'recordUsage');
        $record->invoke(new RunPipelineStep($story->id, 'script'), $story, $meter);

        $fresh = $story->fresh();

        $this->assertInstanceOf(Story::class, $fresh);
        $this->assertEqualsWithDelta(2.5, (float) $fresh->llm_cost_usd, 0.000001);
        $this->assertSame(1000100, $fresh->llm_input_tokens);
        $this->assertSame(200020, $fresh->llm_output_tokens);

        $event = $fresh->events()->where('type', 'llm_usage')->first();

        $this->assertInstanceOf(StoryEvent::class, $event);
        $this->assertSame('script', $event->payload['step'] ?? null);
        $this->assertSame(1, $event->payload['calls'] ?? null);
        $this->assertSame(1_000_000, $event->payload['inputTokens'] ?? null);
        $this->assertSame(200_000, $event->payload['outputTokens'] ?? null);
        $this->assertEqualsWithDelta(2.0, (float) ($event->payload['costUsd'] ?? 0), 0.000001);
        $this->assertSame(1, $event->payload['byProvider']['anthropic']['calls'] ?? null);
        $this->assertSame(0, $meter->summary()['calls']);
    }

    public function test_a_failed_step_still_persists_usage_already_spent(): void
    {
        $story = Story::factory()->create(['status' => StoryStatus::Draft]);
        $this->app->make(LlmUsageMeter::class)->record(
            'anthropic',
            'claude-haiku-4-5',
            LlmTask::Review,
            1_000_000,
            0,
        );

        (new RunPipelineStep($story->id, 'script'))->failed(new RuntimeException('sin sitio'));

        $fresh = $story->fresh();

        $this->assertInstanceOf(Story::class, $fresh);
        $this->assertSame(StoryStatus::Failed, $fresh->status);
        $this->assertEqualsWithDelta(1.0, (float) $fresh->llm_cost_usd, 0.000001);
        $this->assertSame(1_000_000, $fresh->llm_input_tokens);
        $this->assertSame(0, $fresh->llm_output_tokens);
        $this->assertSame(1, $fresh->events()->where('type', 'llm_usage')->count());
    }

    public function test_handle_resets_the_meter_so_a_leftover_call_is_not_billed(): void
    {
        Bus::fake();

        $story = Story::factory()->create([
            'status' => StoryStatus::Draft,
            'mode' => StoryMode::Original,
            'lore_slug' => null,
        ]);
        $this->app->make(LlmUsageMeter::class)->record(
            'anthropic',
            'claude-haiku-4-5',
            LlmTask::Script,
            1_000_000,
            0,
        );
        $this->runSuccessfulScriptJob($story, chain: false);

        $fresh = $story->fresh();

        $this->assertInstanceOf(Story::class, $fresh);
        $this->assertEqualsWithDelta(0.0, (float) $fresh->llm_cost_usd, 0.000001);
        $this->assertSame(0, $fresh->events()->where('type', 'llm_usage')->count());
    }

    public function test_failed_message_keeps_the_original_exception_class_and_message(): void
    {
        $story = Story::factory()->create(['status' => StoryStatus::Draft]);
        $job = new RunPipelineStep($story->id, 'script');

        $job->failed(new RuntimeException(
            'El paso del pipeline falló.',
            previous: new LlmGenerationException('Motivo: max_tokens.'),
        ));

        $fresh = $story->fresh();

        $this->assertInstanceOf(Story::class, $fresh);
        $this->assertSame(
            "El paso del pipeline falló.\n\nCausa: App\\Exceptions\\LlmGenerationException: Motivo: max_tokens.",
            $fresh->failed_message,
        );
        $this->assertSame('script', $fresh->failed_step);
    }

    public function test_a_failed_step_is_not_retried(): void
    {
        $job = new RunPipelineStep(0, 'images');

        $this->assertSame(1, $job->tries);

        $story = $this->runFailingStep();

        $this->assertSame(1, $story->events()->where('type', 'step_failed')->count());
    }

    public function test_progress_stores_and_returns_label_done_and_total(): void
    {
        $progress = $this->app->make(PipelineProgress::class);

        $progress->put(17, 'images', 'plano 3', 3, 10, 'direct');

        $this->assertSame(
            [
                'step' => 'images',
                'label' => 'plano 3',
                'done' => 3,
                'total' => 10,
                'stage' => 'direct',
            ],
            $progress->get(17),
        );
    }

    public function test_progress_without_a_stage_returns_null_stage(): void
    {
        $progress = $this->app->make(PipelineProgress::class);

        $progress->put(18, 'narration', 'frase 1', 1, 4);

        $stored = $progress->get(18);

        $this->assertIsArray($stored);
        $this->assertArrayHasKey('stage', $stored);
        $this->assertNull($stored['stage']);
    }

    public function test_script_step_reports_generate_then_review(): void
    {
        $story = Story::factory()->create([
            'status' => StoryStatus::Draft,
            'mode' => StoryMode::Original,
            'lore_slug' => null,
        ]);
        $this->bindSuccessfulScriptLlm();
        $this->app->make('config')->set('stories.review.enabled', true);
        $this->app->forgetInstance(ScriptStep::class);

        $stages = [];
        $this->app->make(ScriptStep::class)->run(
            $story,
            function (string $label, int $done, int $total, ?string $stage = null) use (&$stages): void {
                $stages[] = $stage;
            },
        );

        $this->assertSame(['generate', 'review', 'review'], $stages);
    }

    public function test_advance_from_narrated_queues_the_images_step(): void
    {
        Bus::fake();

        $story = Story::factory()->create(['status' => StoryStatus::Narrated]);

        $this->app->make(PipelineDispatcher::class)->advance($story);

        Bus::assertDispatched(
            RunPipelineStep::class,
            static fn (RunPipelineStep $job): bool => $job->storyId === $story->id
                && $job->step === 'images'
                && $job->chain === true,
        );
    }

    public function test_a_successful_job_with_chain_false_does_not_enqueue_the_next_step(): void
    {
        Bus::fake();

        $story = Story::factory()->create([
            'status' => StoryStatus::Draft,
            'mode' => StoryMode::Original,
            'lore_slug' => null,
        ]);
        $this->runSuccessfulScriptJob($story, chain: false);

        Bus::assertNothingDispatched();
        $this->assertSame(StoryStatus::ScriptReady, $story->fresh()?->status);
    }

    public function test_a_successful_job_with_chain_true_enqueues_the_next_step(): void
    {
        Bus::fake();

        $story = Story::factory()->create([
            'status' => StoryStatus::Draft,
            'mode' => StoryMode::Original,
            'lore_slug' => null,
        ]);
        $this->runSuccessfulScriptJob($story, chain: true);

        Bus::assertDispatched(
            RunPipelineStep::class,
            static fn (RunPipelineStep $job): bool => $job->storyId === $story->id
                && $job->step === 'narration'
                && $job->chain === true,
        );
        $this->assertSame(StoryStatus::ScriptReady, $story->fresh()?->status);
    }

    public function test_a_returned_slug_is_copied_onto_a_story_without_one(): void
    {
        Bus::fake();

        $story = Story::factory()->create([
            'status' => StoryStatus::Draft,
            'mode' => StoryMode::Original,
            'lore_slug' => null,
            'slug' => '',
        ]);
        $this->runSuccessfulScriptJob($story, chain: false);

        $fixture = $this->scriptFixture();
        $expected = date('Y-m-d').'-'.Str::slug((string) $fixture['title']);

        $this->assertSame($expected, $story->fresh()?->slug);
    }

    public function test_a_draft_prefixed_slug_is_replaced_by_the_returned_slug(): void
    {
        $story = Story::factory()->create(['slug' => 'draft-20260830-120000-abc123']);
        $job = new RunPipelineStep($story->id, 'script', chain: false);

        $apply = new ReflectionMethod(RunPipelineStep::class, 'applyMetrics');
        $apply->invoke($job, $story, ['slug' => 'mi-historia']);

        $this->assertSame('mi-historia', $story->fresh()?->slug);
    }

    public function test_a_colliding_returned_slug_gets_a_numeric_suffix_and_records_the_event(): void
    {
        Story::factory()->create(['slug' => 'mi-historia']);
        $story = Story::factory()->create(['slug' => 'draft-20260830-120000-abc123']);
        $job = new RunPipelineStep($story->id, 'script', chain: false);

        $apply = new ReflectionMethod(RunPipelineStep::class, 'applyMetrics');
        $apply->invoke($job, $story, ['slug' => 'mi-historia']);

        $fresh = $story->fresh();

        $this->assertInstanceOf(Story::class, $fresh);
        $this->assertSame('mi-historia-2', $fresh->slug);

        $event = $fresh->events()->where('type', 'slug_collision')->first();

        $this->assertInstanceOf(StoryEvent::class, $event);
        $this->assertSame('mi-historia', $event->payload['requested'] ?? null);
        $this->assertSame('mi-historia-2', $event->payload['assigned'] ?? null);
    }

    public function test_script_step_keeps_the_story_when_review_throws(): void
    {
        $story = Story::factory()->create([
            'status' => StoryStatus::Draft,
            'mode' => StoryMode::Original,
            'lore_slug' => null,
        ]);
        $this->bindScriptLlm(reviewFails: true);

        $result = $this->app->make(ScriptStep::class)->run($story);

        $this->assertTrue($result['ok'] ?? false);
        $this->assertArrayHasKey('verdict', $result);
        $this->assertNull($result['verdict']);
        $this->assertIsArray($result['warnings'] ?? null);
        $this->assertStringStartsWith('Revisión automática fallida:', $result['warnings'][0] ?? '');

        $path = storage_path('app/'.$this->storiesDir).DIRECTORY_SEPARATOR.$result['slug'].'.json';
        $this->assertFileExists($path);
        $this->assertArrayNotHasKey(
            'review',
            json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR),
        );
    }

    public function test_a_failed_review_keeps_the_script_and_records_a_warning(): void
    {
        Bus::fake();

        $story = Story::factory()->create([
            'status' => StoryStatus::Draft,
            'mode' => StoryMode::Original,
            'lore_slug' => null,
        ]);
        $this->bindScriptLlm(reviewFails: true);
        $this->app->call([new RunPipelineStep($story->id, 'script', chain: false), 'handle']);

        $fresh = $story->fresh();

        $this->assertInstanceOf(Story::class, $fresh);
        $this->assertSame(StoryStatus::ScriptReady, $fresh->status);
        $this->assertNull($fresh->verdict);
        $this->assertNull($fresh->score);

        $path = storage_path('app/'.$this->storiesDir).DIRECTORY_SEPARATOR.$fresh->slug.'.json';
        $this->assertFileExists($path);

        /** @var array<string, mixed> $payload */
        $payload = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $this->assertArrayNotHasKey('review', $payload);

        $event = $fresh->events()->where('type', 'step_warning')->first();

        $this->assertInstanceOf(StoryEvent::class, $event);
        $this->assertSame('script', $event->payload['step'] ?? null);
        $this->assertIsArray($event->payload['messages'] ?? null);
        $this->assertStringStartsWith('Revisión automática fallida:', $event->payload['messages'][0] ?? '');
    }

    public function test_review_story_writes_the_review_without_changing_status(): void
    {
        $this->storiesDir = 'testing/pipeline-job-'.bin2hex(random_bytes(4));
        $this->app->make('config')->set('stories.output_path', $this->storiesDir);
        $this->app->make('config')->set('stories.review.enabled', true);

        $slug = '2026-08-30-reviewed-mill';
        $directory = storage_path('app/'.$this->storiesDir);
        $this->app->make(Filesystem::class)->ensureDirectoryExists($directory);
        $this->app->make(Filesystem::class)->put(
            $directory.DIRECTORY_SEPARATOR.$slug.'.json',
            json_encode($this->scriptFixture(), JSON_THROW_ON_ERROR),
        );

        $story = Story::factory()->create([
            'slug' => $slug,
            'status' => StoryStatus::ScriptReady,
            'verdict' => null,
            'score' => null,
        ]);

        Http::preventStrayRequests();
        $this->app->instance(JsonLlm::class, $this->jsonLlm($this->scriptFixture(), $this->reviewFixture()));

        $this->app->call([new ReviewStory($story->id), 'handle']);

        $fresh = $story->fresh();

        $this->assertInstanceOf(Story::class, $fresh);
        $this->assertSame(StoryStatus::ScriptReady, $fresh->status);
        $this->assertSame(ReviewVerdict::Publish, $fresh->verdict);
        $this->assertSame(8.0, $fresh->score);

        /** @var array<string, mixed> $payload */
        $payload = json_decode(
            $this->app->make(Filesystem::class)->get($directory.DIRECTORY_SEPARATOR.$slug.'.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $this->assertSame('publish', $payload['review']['verdict'] ?? null);
        $this->assertSame(8, $payload['review']['score'] ?? null);
    }

    public function test_review_story_failure_does_not_mark_the_story_failed(): void
    {
        $story = Story::factory()->create(['status' => StoryStatus::ScriptReady]);
        $job = new ReviewStory($story->id);

        $job->failed(new RuntimeException('sin sitio'));

        $fresh = $story->fresh();

        $this->assertInstanceOf(Story::class, $fresh);
        $this->assertSame(StoryStatus::ScriptReady, $fresh->status);

        $event = $fresh->events()->where('type', 'review_failed')->first();

        $this->assertInstanceOf(StoryEvent::class, $event);
        $this->assertSame('sin sitio', $event->note);
    }

    public function test_an_existing_slug_is_not_replaced_by_a_returned_slug(): void
    {
        $story = Story::factory()->create(['slug' => 'kept-slug']);
        $job = new RunPipelineStep($story->id, 'script', chain: false);

        $apply = new ReflectionMethod(RunPipelineStep::class, 'applyMetrics');
        $apply->invoke($job, $story, [
            'slug' => 'other-from-step',
            'title' => 'Otro título',
        ]);

        $fresh = $story->fresh();

        $this->assertInstanceOf(Story::class, $fresh);
        $this->assertSame('kept-slug', $fresh->slug);
        $this->assertSame('Otro título', $fresh->title);
    }

    private function runFailingStep(): Story
    {
        $story = Story::factory()->create(['status' => StoryStatus::Draft]);

        try {
            $this->app->make(Dispatcher::class)->dispatch(new RunPipelineStep($story->id, 'images'));
        } catch (Throwable) {
            // El driver sync relanza tras failed(); el aserto es el estado de la historia.
        }

        $fresh = $story->fresh();

        $this->assertInstanceOf(Story::class, $fresh);

        return $fresh;
    }

    private function runSuccessfulScriptJob(Story $story, bool $chain): void
    {
        $this->bindSuccessfulScriptLlm();

        $job = new RunPipelineStep($story->id, 'script', $chain);
        $this->app->call([$job, 'handle']);
    }

    private function bindSuccessfulScriptLlm(): void
    {
        $this->bindScriptLlm(reviewFails: false);
    }

    private function bindScriptLlm(bool $reviewFails): void
    {
        $this->storiesDir = 'testing/pipeline-job-'.bin2hex(random_bytes(4));

        $config = $this->app->make('config');
        $config->set('stories.review.enabled', $reviewFails);
        $config->set('stories.output_path', $this->storiesDir);

        Http::preventStrayRequests();

        $this->app->instance(JsonLlm::class, $this->jsonLlm(
            $this->scriptFixture(),
            $reviewFails ? new LlmGenerationException('sin sitio') : $this->reviewFixture(),
        ));
    }

    private function bindThrowingScriptLlm(Throwable $exception): void
    {
        $this->storiesDir = 'testing/pipeline-job-'.bin2hex(random_bytes(4));

        $config = $this->app->make('config');
        $config->set('stories.review.enabled', false);
        $config->set('stories.output_path', $this->storiesDir);

        Http::preventStrayRequests();

        $this->app->instance(JsonLlm::class, $this->jsonLlm($exception, $this->reviewFixture()));
    }

    /**
     * @param  array<string, mixed>|Throwable  $script
     * @param  array<string, mixed>|Throwable  $review
     */
    private function jsonLlm(array|Throwable $script, array|Throwable $review): JsonLlm
    {
        return new class($script, $review) implements JsonLlm
        {
            /**
             * @param  array<string, mixed>|Throwable  $script
             * @param  array<string, mixed>|Throwable  $review
             */
            public function __construct(
                private array|Throwable $script,
                private array|Throwable $review,
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
                    if ($this->review instanceof Throwable) {
                        throw $this->review;
                    }

                    return $this->review;
                }

                if ($this->script instanceof Throwable) {
                    throw $this->script;
                }

                return $this->script;
            }

            public function isAvailable(): bool
            {
                return true;
            }

            public function name(): string
            {
                return 'test-llm';
            }

            public function fallbackNotice(): ?string
            {
                return null;
            }
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function reviewFixture(): array
    {
        return [
            'nonNativePhrases' => [],
            'clichedElements' => [],
            'tensionDips' => [],
            'ttsRisks' => [],
            'score' => 8,
            'verdict' => 'publish',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function scriptFixture(): array
    {
        $json = file_get_contents(base_path('tests/Fixtures/story-response.json'));

        $this->assertNotFalse($json);

        /** @var array<string, mixed> $data */
        $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        return $data;
    }
}
