<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Story;
use App\Services\Image\ContactSheet;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ContactSheetTest extends TestCase
{
    use RefreshDatabase;

    private string $storiesDirectory;

    private string $cacheDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        $suffix = bin2hex(random_bytes(4));
        $this->storiesDirectory = storage_path('app/testing/sheet-stories-'.$suffix);
        $this->cacheDirectory = storage_path('app/testing/sheet-cache-'.$suffix);

        $files = $this->app->make(Filesystem::class);
        $files->ensureDirectoryExists($this->storiesDirectory);
        $files->ensureDirectoryExists($this->cacheDirectory);

        config([
            'stories.output_path' => 'testing/'.basename($this->storiesDirectory),
            'stories.images.cache_path' => 'testing/'.basename($this->cacheDirectory),
        ]);
    }

    protected function tearDown(): void
    {
        $files = $this->app->make(Filesystem::class);
        $files->deleteDirectory($this->storiesDirectory);
        $files->deleteDirectory($this->cacheDirectory);

        parent::tearDown();
    }

    public function test_the_sheet_lists_every_shot_and_says_which_have_an_image(): void
    {
        $story = $this->storyWithPlan([
            ['order' => 1, 'subject' => 'environment', 'image' => 'uno'],
            ['order' => 2, 'subject' => 'threat', 'image' => null],
            ['order' => 3, 'subject' => 'detail', 'image' => 'tres'],
        ]);

        $this->get(route('sheet.show', $story))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('ContactSheet')
                ->where('shots.0.hasImage', true)
                ->where('shots.1.hasImage', false)
                ->where('shots.2.hasImage', true)
                ->where('stats.total', 3)
                ->where('stats.withImage', 2));
    }

    public function test_it_serves_the_image_of_a_shot(): void
    {
        $story = $this->storyWithPlan([
            ['order' => 1, 'subject' => 'environment', 'image' => 'uno'],
        ]);

        $response = $this->get(route('sheet.image', ['story' => $story, 'order' => 1]));

        $response->assertOk();
        $this->assertSame('image/jpeg', $response->headers->get('Content-Type'));
    }

    public function test_a_shot_without_an_image_is_a_404_and_not_a_broken_stream(): void
    {
        $story = $this->storyWithPlan([
            ['order' => 1, 'subject' => 'environment', 'image' => null],
        ]);

        $this->get(route('sheet.image', ['story' => $story, 'order' => 1]))->assertNotFound();
    }

    public function test_an_unknown_shot_order_is_a_404(): void
    {
        $story = $this->storyWithPlan([
            ['order' => 1, 'subject' => 'environment', 'image' => 'uno'],
        ]);

        $this->get(route('sheet.image', ['story' => $story, 'order' => 77]))->assertNotFound();
    }

    /**
     * shots.json es un fichero en disco y su imagePath es una ruta absoluta. Servirla tal cual
     * convertiría la ruta de imágenes en un lector de ficheros arbitrarios, así que una ruta
     * que apunte fuera de la caché y del directorio de la historia no se abre.
     */
    public function test_a_path_outside_the_allowed_roots_is_refused(): void
    {
        $outside = storage_path('app/testing/sheet-outside-'.bin2hex(random_bytes(4)).'.jpg');
        $this->app->make(Filesystem::class)->put($outside, 'secreto');

        $story = $this->storyWithPlan([
            ['order' => 1, 'subject' => 'environment', 'image' => null],
        ]);

        $this->overwritePlanImagePath($story->slug, $outside);

        $this->get(route('sheet.image', ['story' => $story, 'order' => 1]))->assertNotFound();

        $this->assertNull($this->app->make(ContactSheet::class)->imagePath($story->slug, 1));

        $this->app->make(Filesystem::class)->delete($outside);
    }

    /**
     * Un ../ dentro del JSON no puede salir de la caché: la comprobación va sobre la ruta ya
     * resuelta, no sobre la que está escrita.
     */
    public function test_a_traversal_out_of_the_cache_is_refused(): void
    {
        $outside = storage_path('app/testing/sheet-traversal-'.bin2hex(random_bytes(4)).'.jpg');
        $this->app->make(Filesystem::class)->put($outside, 'secreto');

        $story = $this->storyWithPlan([
            ['order' => 1, 'subject' => 'environment', 'image' => null],
        ]);

        $this->overwritePlanImagePath(
            $story->slug,
            $this->cacheDirectory.DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR.basename($outside),
        );

        $this->get(route('sheet.image', ['story' => $story, 'order' => 1]))->assertNotFound();

        $this->app->make(Filesystem::class)->delete($outside);
    }

    public function test_it_flags_a_threat_stage_that_arrives_before_its_gate(): void
    {
        $shots = [];

        for ($order = 1; $order <= 10; $order++) {
            $shots[] = [
                'order' => $order,
                'subject' => $order === 2 ? 'threat' : 'environment',
                'image' => null,
                // El plano 2 de 10 cae en el 11 % de la historia; reveal no se permite
                // hasta el 70 %.
                'threatStage' => $order === 2 ? 'reveal' : null,
            ];
        }

        $story = $this->storyWithPlan($shots);

        $stats = $this->app->make(ContactSheet::class)->stats($story->slug);
        $reveal = collect($stats['threat'])->firstWhere('stage', 'reveal');

        $this->assertSame(2, $reveal['firstOrder']);
        $this->assertTrue($reveal['early']);
    }

    public function test_a_stage_that_never_appears_is_not_flagged(): void
    {
        $story = $this->storyWithPlan([
            ['order' => 1, 'subject' => 'environment', 'image' => null],
            ['order' => 2, 'subject' => 'environment', 'image' => null],
        ]);

        $stats = $this->app->make(ContactSheet::class)->stats($story->slug);

        foreach ($stats['threat'] as $stage) {
            $this->assertNull($stage['firstOrder']);
            $this->assertFalse($stage['early']);
        }
    }

    public function test_a_story_without_a_plan_shows_nothing_to_review(): void
    {
        $story = Story::factory()->create(['slug' => 'sheet-sin-plan']);

        $this->get(route('sheet.show', $story))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('ContactSheet')
                ->where('shots', [])
                ->where('stats', null));
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function storyWithPlan(array $rows): Story
    {
        $slug = 'sheet-story';
        $story = Story::factory()->create(['slug' => $slug]);
        $files = $this->app->make(Filesystem::class);

        $shots = [];

        foreach ($rows as $index => $row) {
            $imagePath = null;

            if ($row['image'] !== null) {
                $imagePath = $this->cacheDirectory.DIRECTORY_SEPARATOR.$row['image'].'.jpg';
                $files->put($imagePath, 'jpg');
            }

            $shots[] = [
                'order' => $row['order'],
                'sceneOrder' => 1,
                'start' => $index * 5.0,
                'end' => ($index * 5.0) + 4.0,
                'subject' => $row['subject'],
                'framing' => 'medium shot',
                'motion' => 'static',
                'threatStage' => $row['threatStage'] ?? null,
                'sourceText' => 'Una frase.',
                'description' => 'Una descripción.',
                'prompt' => 'un prompt',
                'imagePath' => $imagePath,
            ];
        }

        $this->writePlan($slug, $shots);

        return $story;
    }

    /**
     * @param  list<array<string, mixed>>  $shots
     */
    private function writePlan(string $slug, array $shots): void
    {
        $files = $this->app->make(Filesystem::class);
        $directory = $this->storiesDirectory.DIRECTORY_SEPARATOR.$slug;
        $files->ensureDirectoryExists($directory);
        $files->put(
            $directory.DIRECTORY_SEPARATOR.'shots.json',
            (string) json_encode(['version' => 2, 'plannerVersion' => 1, 'shots' => $shots]),
        );
    }

    private function overwritePlanImagePath(string $slug, string $path): void
    {
        $files = $this->app->make(Filesystem::class);
        $planPath = $this->storiesDirectory.DIRECTORY_SEPARATOR.$slug.DIRECTORY_SEPARATOR.'shots.json';
        $plan = json_decode($files->get($planPath), true);
        $plan['shots'][0]['imagePath'] = $path;
        $files->put($planPath, (string) json_encode($plan));
    }
}
