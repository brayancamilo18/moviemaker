<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Image\ShotImageCount;
use Illuminate\Filesystem\Filesystem;
use Tests\TestCase;

final class ShotImageCountTest extends TestCase
{
    private string $stories;

    private string $images;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stories = storage_path('app/'.config('stories.output_path'));
        $this->images = storage_path('app/shot-image-count-test');
        $this->app->make(Filesystem::class)->ensureDirectoryExists($this->images);
    }

    protected function tearDown(): void
    {
        $files = $this->app->make(Filesystem::class);
        $files->deleteDirectory($this->images);
        $files->deleteDirectory($this->stories.DIRECTORY_SEPARATOR.$this->slug());

        parent::tearDown();
    }

    public function test_a_story_without_a_plan_has_nothing_to_count(): void
    {
        $this->assertNull($this->counter()->get($this->slug()));
    }

    public function test_it_counts_only_the_shots_whose_image_is_on_disk(): void
    {
        $this->writePlan([
            $this->existingImage('uno'),
            $this->existingImage('dos'),
            null,
            null,
            null,
        ]);

        $this->assertSame(['done' => 2, 'total' => 5], $this->counter()->get($this->slug()));
    }

    /**
     * story:prune borra las imágenes cuando ya hay vídeo y deja los imagePath escritos
     * apuntando a nada. Contar rutas en vez de ficheros daría una historia entera de
     * imágenes que ya no se pueden enseñar.
     */
    public function test_a_path_that_no_longer_exists_does_not_count(): void
    {
        $this->writePlan([
            $this->images.DIRECTORY_SEPARATOR.'borrada.jpg',
            $this->existingImage('viva'),
        ]);

        $this->assertSame(['done' => 1, 'total' => 2], $this->counter()->get($this->slug()));
    }

    public function test_a_plan_without_shots_counts_as_no_plan(): void
    {
        $this->writePlan([]);

        $this->assertNull($this->counter()->get($this->slug()));
    }

    /**
     * La cuenta se memoriza contra el mtime, así que un plano nuevo tiene que verse en el
     * sondeo siguiente y no dos segundos tarde ni cinco minutos tarde.
     */
    public function test_a_new_image_shows_up_after_the_plan_is_rewritten(): void
    {
        $this->writePlan([$this->existingImage('uno'), null]);
        $this->assertSame(['done' => 1, 'total' => 2], $this->counter()->get($this->slug()));

        $this->writePlan([$this->existingImage('uno'), $this->existingImage('dos')]);

        $this->assertSame(['done' => 2, 'total' => 2], $this->counter()->get($this->slug()));
    }

    /**
     * @param  list<string|null>  $imagePaths
     */
    private function writePlan(array $imagePaths): void
    {
        $shots = [];

        foreach ($imagePaths as $index => $path) {
            $shots[] = [
                'order' => $index + 1,
                'sceneOrder' => 1,
                'imagePath' => $path,
            ];
        }

        $files = $this->app->make(Filesystem::class);
        $directory = $this->stories.DIRECTORY_SEPARATOR.$this->slug();
        $files->ensureDirectoryExists($directory);
        $files->put(
            $directory.DIRECTORY_SEPARATOR.'shots.json',
            (string) json_encode(['version' => 2, 'plannerVersion' => 1, 'shots' => $shots]),
        );
    }

    private function existingImage(string $name): string
    {
        $path = $this->images.DIRECTORY_SEPARATOR.$name.'.jpg';
        $this->app->make(Filesystem::class)->put($path, 'jpg');

        return $path;
    }

    private function counter(): ShotImageCount
    {
        return $this->app->make(ShotImageCount::class);
    }

    private function slug(): string
    {
        return 'shot-image-count-test-story';
    }
}
