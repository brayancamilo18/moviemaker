<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\DiscardReason;
use App\Enums\StoryStatus;
use App\Models\Story;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ImportStoriesTest extends TestCase
{
    use RefreshDatabase;

    private string $storiesDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storiesDirectory = storage_path('app/testing/import-stories-'.bin2hex(random_bytes(4)));

        $files = $this->app->make(Filesystem::class);
        $files->ensureDirectoryExists($this->storiesDirectory);

        config(['stories.output_path' => 'testing/'.basename($this->storiesDirectory)]);
    }

    protected function tearDown(): void
    {
        $this->app->make(Filesystem::class)->deleteDirectory($this->storiesDirectory);

        parent::tearDown();
    }

    public function test_a_folder_with_video_imports_as_pending_review(): void
    {
        $this->writeStory('2026-08-30-the-rendered-mill', artifacts: ['video.mp4']);

        $this->artisan('stories:import')
            ->assertSuccessful()
            ->expectsOutputToContain('crear');

        $story = Story::query()->where('slug', '2026-08-30-the-rendered-mill')->first();

        $this->assertInstanceOf(Story::class, $story);
        $this->assertSame(StoryStatus::PendingReview, $story->status);
        $this->assertSame('The rendered mill', $story->title);
        $this->assertSame(2, $story->scene_count);
    }

    public function test_a_folder_with_only_narration_imports_as_narrated(): void
    {
        $this->writeStory('2026-08-30-the-spoken-mill', artifacts: ['narration.wav']);

        $this->artisan('stories:import')->assertSuccessful();

        $story = Story::query()->where('slug', '2026-08-30-the-spoken-mill')->first();

        $this->assertInstanceOf(Story::class, $story);
        $this->assertSame(StoryStatus::Narrated, $story->status);
    }

    public function test_an_existing_discarded_story_keeps_its_human_decision(): void
    {
        $slug = '2026-08-30-the-discarded-mill';
        $publishedAt = now()->subDay();

        Story::factory()->create([
            'slug' => $slug,
            'title' => 'Old title',
            'status' => StoryStatus::Discarded,
            'discard_reason' => DiscardReason::Pacing,
            'discard_note' => 'No hay tensión',
            'published_url' => 'https://youtu.be/old',
            'published_at' => $publishedAt,
        ]);

        $this->writeStory($slug, [
            'title' => 'The discarded mill',
        ], ['video.mp4']);

        $this->artisan('stories:import')
            ->assertSuccessful()
            ->expectsOutputToContain('actualizar');

        $story = Story::query()->where('slug', $slug)->first();

        $this->assertInstanceOf(Story::class, $story);
        $this->assertSame(StoryStatus::Discarded, $story->status);
        $this->assertSame(DiscardReason::Pacing, $story->discard_reason);
        $this->assertSame('No hay tensión', $story->discard_note);
        $this->assertSame('https://youtu.be/old', $story->published_url);
        $this->assertNotNull($story->published_at);
        $this->assertSame($publishedAt->toDateTimeString(), $story->published_at->toDateTimeString());
        $this->assertSame('The discarded mill', $story->title);
        $this->assertSame(1, Story::query()->count());
    }

    public function test_dry_run_does_not_create_a_record(): void
    {
        $this->writeStory('2026-08-30-the-dry-mill', artifacts: ['narration.wav']);

        $this->artisan('stories:import', ['--dry-run' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('crear')
            ->expectsOutputToContain('Creadas: 1');

        $this->assertSame(0, Story::query()->count());
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @param  list<string>  $artifacts
     */
    private function writeStory(string $slug, array $overrides = [], array $artifacts = []): void
    {
        $payload = [
            'title' => 'The rendered mill',
            'hook' => 'The door closed.',
            'description' => 'A fixture used to test story import.',
            'tags' => ['test'],
            'thumbnailPrompt' => 'An empty hallway',
            'scenes' => [
                ['order' => 1, 'narration' => 'The door closed.', 'imagePrompt' => 'x', 'visualSummary' => 'x'],
                ['order' => 2, 'narration' => 'I kept walking.', 'imagePrompt' => 'x', 'visualSummary' => 'x'],
            ],
            'pronunciations' => [],
            'mode' => 'folklore',
            'lore_slug' => 'el-silbon',
            'lore_name' => 'El Silbón',
            'review' => [
                'score' => 8,
                'verdict' => 'publish',
                'nonNativePhrases' => [],
                'clichedElements' => [],
                'tensionDips' => [],
                'ttsRisks' => [],
            ],
            'audio' => [
                'durationSeconds' => 12.5,
                'sentenceCount' => 2,
            ],
            ...$overrides,
        ];

        $files = $this->app->make(Filesystem::class);
        $files->put(
            $this->storiesDirectory.DIRECTORY_SEPARATOR.$slug.'.json',
            json_encode($payload, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n",
        );

        if ($artifacts === []) {
            return;
        }

        $directory = $this->storiesDirectory.DIRECTORY_SEPARATOR.$slug;
        $files->ensureDirectoryExists($directory);

        foreach ($artifacts as $artifact) {
            $files->put($directory.DIRECTORY_SEPARATOR.$artifact, '');
        }
    }
}
