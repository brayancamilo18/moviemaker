<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\JsonLlm;
use App\Enums\StoryMode;
use App\Enums\StoryStatus;
use App\Jobs\RunPipelineStep;
use App\Models\Story;
use App\Models\StoryEvent;
use App\Services\Llm\LlmTask;
use App\Services\Pipeline\PipelineDispatcher;
use App\Services\Pipeline\PipelineProgress;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use ReflectionMethod;
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

        $progress->put(17, 'images', 'plano 3', 3, 10);

        $this->assertSame(
            [
                'step' => 'images',
                'label' => 'plano 3',
                'done' => 3,
                'total' => 10,
            ],
            $progress->get(17),
        );
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
        $this->storiesDir = 'testing/pipeline-job-'.bin2hex(random_bytes(4));

        $config = $this->app->make('config');
        $config->set('stories.review.enabled', false);
        $config->set('stories.output_path', $this->storiesDir);

        Http::preventStrayRequests();

        $this->app->instance(JsonLlm::class, new class($this->scriptFixture()) implements JsonLlm
        {
            /**
             * @param  array<string, mixed>  $story
             */
            public function __construct(private array $story) {}

            public function generateJson(
                string $systemInstruction,
                string $userPrompt,
                array $schema,
                LlmTask $task = LlmTask::Script,
                float $temperature = 1.0,
            ): array {
                return $this->story;
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
        });
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
