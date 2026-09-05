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

    /**
     * shots.json se escribe con el plan y se va rellenando plano a plano durante media hora
     * larga. Darlo por "imágenes listas" en cuanto existe dejaba la historia a un clic de
     * saltar a sonido con la mitad de los planos sin pintar.
     */
    public function test_a_half_generated_plan_imports_as_narrated(): void
    {
        $slug = '2026-08-30-the-half-drawn-mill';
        $this->writeStory($slug, artifacts: ['narration.wav']);
        $this->writePlan($slug, done: 2, total: 6);

        $this->artisan('stories:import')->assertSuccessful();

        $story = Story::query()->where('slug', $slug)->first();

        $this->assertInstanceOf(Story::class, $story);
        $this->assertSame(StoryStatus::Narrated, $story->status);
    }

    public function test_a_plan_with_every_image_imports_as_images_ready(): void
    {
        $slug = '2026-08-30-the-fully-drawn-mill';
        $this->writeStory($slug, artifacts: ['narration.wav']);
        $this->writePlan($slug, done: 6, total: 6);

        $this->artisan('stories:import')->assertSuccessful();

        $story = Story::query()->where('slug', $slug)->first();

        $this->assertInstanceOf(Story::class, $story);
        $this->assertSame(StoryStatus::ImagesReady, $story->status);
    }

    /**
     * Una historia ya depurada tiene los imagePath apuntando a nada, pero también tiene su
     * vídeo, y el vídeo se comprueba antes: no puede caer a "narrada" por haber soltado las
     * imágenes que ya no hacen falta.
     */
    public function test_a_pruned_story_with_video_still_imports_as_pending_review(): void
    {
        $slug = '2026-08-30-the-pruned-mill';
        $this->writeStory($slug, artifacts: ['video.mp4']);
        $this->writePlan($slug, done: 0, total: 6);

        $this->artisan('stories:import')->assertSuccessful();

        $story = Story::query()->where('slug', $slug)->first();

        $this->assertInstanceOf(Story::class, $story);
        $this->assertSame(StoryStatus::PendingReview, $story->status);
    }

    /**
     * El caso de todos los días: la historia se lanzó con un comando encadenado, terminó, y la
     * fila se quedó donde estaba. Sin esto el panel enseña "narrada" con el MP4 ya escrito.
     */
    public function test_it_catches_up_with_a_story_that_advanced_outside_the_app(): void
    {
        $slug = '2026-08-30-the-mill-that-finished-alone';
        $story = Story::factory()->create(['slug' => $slug, 'status' => StoryStatus::Narrated]);
        $this->writeStory($slug, artifacts: ['video.mp4']);

        $this->artisan('stories:import')->assertSuccessful();

        $this->assertSame(StoryStatus::PendingReview, $story->fresh()->status);
    }

    /**
     * Nunca hacia atrás: encontrar menos ficheros de los que hubo no borra el trabajo hecho.
     */
    public function test_it_never_walks_a_story_backwards(): void
    {
        $slug = '2026-08-30-the-pruned-mill-again';
        $story = Story::factory()->create(['slug' => $slug, 'status' => StoryStatus::Rendered]);
        $this->writeStory($slug, artifacts: ['narration.wav']);

        $this->artisan('stories:import')->assertSuccessful();

        $this->assertSame(StoryStatus::Rendered, $story->fresh()->status);
    }

    /**
     * Aprobar, descargar, publicar y descartar son decisiones de una persona. Que aparezca un
     * fichero en disco no las revoca.
     */
    public function test_a_human_decision_is_never_overwritten(): void
    {
        foreach ([StoryStatus::ReadyToPublish, StoryStatus::Published, StoryStatus::Discarded] as $index => $decided) {
            $slug = '2026-08-30-the-decided-mill-'.$index;
            $story = Story::factory()->create(['slug' => $slug, 'status' => $decided]);
            $this->writeStory($slug, artifacts: ['video.mp4']);

            $this->artisan('stories:import')->assertSuccessful();

            $this->assertSame($decided, $story->fresh()->status);
        }
    }

    /**
     * Fallida tampoco: que los artefactos existan no borra que alguien tenga que mirar por qué
     * falló, y reanudar es un gesto suyo.
     */
    public function test_a_failed_story_stays_failed(): void
    {
        $slug = '2026-08-30-the-failed-mill';
        $story = Story::factory()->create(['slug' => $slug, 'status' => StoryStatus::Failed]);
        $this->writeStory($slug, artifacts: ['video.mp4']);

        $this->artisan('stories:import')->assertSuccessful();

        $this->assertSame(StoryStatus::Failed, $story->fresh()->status);
    }

    private function writePlan(string $slug, int $done, int $total): void
    {
        $files = $this->app->make(Filesystem::class);
        $directory = $this->storiesDirectory.DIRECTORY_SEPARATOR.$slug;
        $files->ensureDirectoryExists($directory);

        $shots = [];

        for ($order = 1; $order <= $total; $order++) {
            $path = null;

            if ($order <= $done) {
                $path = $directory.DIRECTORY_SEPARATOR.'plano-'.$order.'.jpg';
                $files->put($path, 'jpg');
            }

            $shots[] = ['order' => $order, 'sceneOrder' => 1, 'imagePath' => $path];
        }

        $files->put(
            $directory.DIRECTORY_SEPARATOR.'shots.json',
            (string) json_encode(['version' => 2, 'plannerVersion' => 1, 'shots' => $shots]),
        );
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
