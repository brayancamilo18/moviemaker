<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Story;
use App\Models\Thumbnail;
use App\Services\Image\ThumbnailCandidates;
use App\Services\Image\YouTubeThumbnail;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

final class ThumbnailTest extends TestCase
{
    use RefreshDatabase;

    private string $storiesDirectory;

    private string $cacheDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        $suffix = bin2hex(random_bytes(4));
        $this->storiesDirectory = storage_path('app/testing/thumb-stories-'.$suffix);
        $this->cacheDirectory = storage_path('app/testing/thumb-cache-'.$suffix);

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

    /**
     * Las cinco portadas elegidas a mano para «The Miller's Debt» eran todas subject=threat.
     * Un paisaje bonito no es la portada de un vídeo de terror.
     */
    public function test_a_shot_with_the_figure_beats_one_without(): void
    {
        $story = $this->storyWithShots([
            ['order' => 1, 'subject' => 'environment', 'framing' => 'medium shot', 'threatStage' => null, 'contrast' => 'high'],
            ['order' => 2, 'subject' => 'threat', 'framing' => 'medium shot', 'threatStage' => 'presence', 'contrast' => 'high'],
        ]);

        $candidates = $this->candidates()->propose($story->slug);

        $this->assertSame(2, $candidates[0]['order']);
    }

    /**
     * El encuadre cerrado se pierde a 168 píxeles, y el director lo tiene prohibido para la
     * amenaza de todas formas.
     */
    public function test_a_readable_framing_beats_a_close_one(): void
    {
        $story = $this->storyWithShots([
            ['order' => 1, 'subject' => 'threat', 'framing' => 'extreme close up', 'threatStage' => 'presence', 'contrast' => 'high'],
            ['order' => 2, 'subject' => 'threat', 'framing' => 'low angle', 'threatStage' => 'presence', 'contrast' => 'high'],
        ]);

        $candidates = $this->candidates()->propose($story->slug);

        $this->assertSame(2, $candidates[0]['order']);
    }

    /**
     * A igualdad de todo lo demás manda el contraste: es lo único que sobrevive al tamaño real
     * en la lista de YouTube.
     */
    public function test_contrast_breaks_the_tie(): void
    {
        $story = $this->storyWithShots([
            ['order' => 1, 'subject' => 'threat', 'framing' => 'low angle', 'threatStage' => 'presence', 'contrast' => 'flat'],
            ['order' => 2, 'subject' => 'threat', 'framing' => 'low angle', 'threatStage' => 'presence', 'contrast' => 'high'],
        ]);

        $candidates = $this->candidates()->propose($story->slug);

        $this->assertSame(2, $candidates[0]['order']);
        $this->assertGreaterThan($candidates[1]['contrast'], $candidates[0]['contrast']);
    }

    public function test_the_channel_bumper_is_never_a_candidate(): void
    {
        $story = $this->storyWithShots([
            ['order' => 1, 'subject' => 'threat', 'framing' => 'low angle', 'threatStage' => 'reveal', 'contrast' => 'high', 'isIntro' => true],
            ['order' => 2, 'subject' => 'environment', 'framing' => 'close detail', 'threatStage' => null, 'contrast' => 'flat'],
        ]);

        $orders = array_column($this->candidates()->propose($story->slug), 'order');

        $this->assertNotContains(1, $orders);
    }

    public function test_it_proposes_at_most_ten(): void
    {
        $shots = [];

        for ($order = 1; $order <= 25; $order++) {
            $shots[] = [
                'order' => $order,
                'subject' => 'threat',
                'framing' => 'low angle',
                'threatStage' => 'presence',
                'contrast' => 'high',
            ];
        }

        $story = $this->storyWithShots($shots);

        $this->assertCount(ThumbnailCandidates::LIMIT, $this->candidates()->propose($story->slug));
    }

    /**
     * En la caché las imágenes viven bajo el hash de su prompt y story:prune las borra en
     * cuanto existe el MP4. Una portada que se evapora justo cuando la historia está lista
     * para publicarse no sirve para nada, así que la candidata se copia a la historia.
     */
    public function test_a_candidate_survives_the_cache_being_wiped(): void
    {
        $story = $this->storyWithShots([
            ['order' => 7, 'subject' => 'threat', 'framing' => 'low angle', 'threatStage' => 'presence', 'contrast' => 'high'],
        ]);

        $this->candidates()->propose($story->slug);

        $this->app->make(Filesystem::class)->cleanDirectory($this->cacheDirectory);

        $this->assertNotNull($this->candidates()->preservedPath($story->slug, 7));
        $this->get(route('thumbnail.image', ['story' => $story, 'order' => 7]))->assertOk();
    }

    public function test_a_shot_that_was_never_a_candidate_has_no_image(): void
    {
        $story = $this->storyWithShots([
            ['order' => 1, 'subject' => 'threat', 'framing' => 'low angle', 'threatStage' => 'presence', 'contrast' => 'high'],
        ]);

        $this->candidates()->propose($story->slug);

        $this->get(route('thumbnail.image', ['story' => $story, 'order' => 99]))->assertNotFound();
    }

    public function test_it_saves_a_variant_without_selecting_it(): void
    {
        $story = $this->storyWithShots([
            ['order' => 4, 'subject' => 'threat', 'framing' => 'low angle', 'threatStage' => 'presence', 'contrast' => 'high'],
        ]);

        $this->post(route('thumbnail.store', $story), $this->composition())
            ->assertRedirect(route('thumbnail.show', $story));

        $thumbnail = Thumbnail::query()->firstOrFail();

        $this->assertSame($story->id, $thumbnail->story_id);
        $this->assertSame(4, $thumbnail->shot_order);
        $this->assertFalse($thumbnail->is_selected);
    }

    public function test_choosing_a_variant_unselects_the_previous_one(): void
    {
        $story = $this->storyWithShots([
            ['order' => 4, 'subject' => 'threat', 'framing' => 'low angle', 'threatStage' => 'presence', 'contrast' => 'high'],
        ]);

        $first = $story->thumbnails()->create($this->composition() + ['is_selected' => true]);
        $second = $story->thumbnails()->create($this->composition());

        $this->post(route('thumbnail.select', ['story' => $story, 'thumbnail' => $second]))
            ->assertRedirect(route('thumbnail.show', $story));

        $this->assertFalse($first->fresh()->is_selected);
        $this->assertTrue($second->fresh()->is_selected);
    }

    /**
     * Los ids de miniatura son globales, así que la ruta lleva historia y miniatura y hay que
     * comprobar que casan: si no, cualquiera puede tocar la portada de otra historia.
     */
    public function test_a_variant_of_another_story_cannot_be_touched(): void
    {
        $mine = $this->storyWithShots([
            ['order' => 4, 'subject' => 'threat', 'framing' => 'low angle', 'threatStage' => 'presence', 'contrast' => 'high'],
        ]);
        $other = Story::factory()->create(['slug' => 'otra-historia']);
        $thumbnail = $other->thumbnails()->create($this->composition());

        $this->post(route('thumbnail.select', ['story' => $mine, 'thumbnail' => $thumbnail]))->assertNotFound();
        $this->delete(route('thumbnail.destroy', ['story' => $mine, 'thumbnail' => $thumbnail]))->assertNotFound();

        $this->assertNotNull($thumbnail->fresh());
    }

    public function test_a_composition_out_of_range_is_refused(): void
    {
        $story = $this->storyWithShots([
            ['order' => 4, 'subject' => 'threat', 'framing' => 'low angle', 'threatStage' => 'presence', 'contrast' => 'high'],
        ]);

        $this->post(route('thumbnail.store', $story), $this->composition(['font_size' => 900]))
            ->assertSessionHasErrors('font_size');

        $this->post(route('thumbnail.store', $story), $this->composition(['align' => 'diagonal']))
            ->assertSessionHasErrors('align');
    }

    public function test_it_stores_the_composed_jpeg_next_to_the_story(): void
    {
        $story = $this->storyWithShots([
            ['order' => 4, 'subject' => 'threat', 'framing' => 'low angle', 'threatStage' => 'presence', 'contrast' => 'high'],
        ]);

        $this->post(route('thumbnail.store', $story), $this->composition())
            ->assertRedirect(route('thumbnail.show', $story));

        $thumbnail = Thumbnail::query()->firstOrFail();
        $path = $this->app->make(YouTubeThumbnail::class)->path($story, $thumbnail);

        $this->assertNotNull($path);
        $this->assertSame(
            [YouTubeThumbnail::WIDTH, YouTubeThumbnail::HEIGHT],
            array_slice((array) getimagesize($path), 0, 2),
        );
    }

    public function test_it_downloads_the_thumbnail_as_a_file(): void
    {
        $story = $this->storyWithShots([
            ['order' => 4, 'subject' => 'threat', 'framing' => 'low angle', 'threatStage' => 'presence', 'contrast' => 'high'],
        ]);

        $this->post(route('thumbnail.store', $story), $this->composition());
        $thumbnail = Thumbnail::query()->firstOrFail();

        $response = $this->get(route('thumbnail.download', ['story' => $story, 'thumbnail' => $thumbnail]));

        $response->assertOk();
        $this->assertSame(YouTubeThumbnail::MIME, $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment;', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('miniatura.jpg', (string) $response->headers->get('Content-Disposition'));
    }

    /**
     * Lo que sale por aquí acaba subido a YouTube. Un tamaño que no es 1280 × 720 se rechaza
     * en el formulario y no delante del formulario de subida.
     */
    public function test_an_image_of_the_wrong_size_is_refused(): void
    {
        $story = $this->storyWithShots([
            ['order' => 4, 'subject' => 'threat', 'framing' => 'low angle', 'threatStage' => 'presence', 'contrast' => 'high'],
        ]);

        $this->post(route('thumbnail.store', $story), $this->composition([
            'image' => $this->jpegUpload(640, 360),
        ]))->assertSessionHasErrors('image');

        $this->assertSame(0, Thumbnail::query()->count());
    }

    public function test_a_file_that_is_not_a_jpeg_is_refused(): void
    {
        $story = $this->storyWithShots([
            ['order' => 4, 'subject' => 'threat', 'framing' => 'low angle', 'threatStage' => 'presence', 'contrast' => 'high'],
        ]);

        $this->post(route('thumbnail.store', $story), $this->composition([
            'image' => UploadedFile::fake()->createWithContent('miniatura.jpg', 'esto no es un jpeg'),
        ]))->assertSessionHasErrors('image');
    }

    public function test_a_variant_without_a_file_cannot_be_downloaded(): void
    {
        $story = $this->storyWithShots([
            ['order' => 4, 'subject' => 'threat', 'framing' => 'low angle', 'threatStage' => 'presence', 'contrast' => 'high'],
        ]);

        $thumbnail = $story->thumbnails()->create($this->compositionWithoutImage());

        $this->get(route('thumbnail.download', ['story' => $story, 'thumbnail' => $thumbnail]))
            ->assertNotFound();
    }

    public function test_deleting_a_variant_takes_its_file_with_it(): void
    {
        $story = $this->storyWithShots([
            ['order' => 4, 'subject' => 'threat', 'framing' => 'low angle', 'threatStage' => 'presence', 'contrast' => 'high'],
        ]);

        $this->post(route('thumbnail.store', $story), $this->composition());
        $thumbnail = Thumbnail::query()->firstOrFail();
        $path = $this->app->make(YouTubeThumbnail::class)->path($story, $thumbnail);

        $this->delete(route('thumbnail.destroy', ['story' => $story, 'thumbnail' => $thumbnail]));

        $this->assertFileDoesNotExist((string) $path);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function composition(array $overrides = []): array
    {
        return [
            'image' => $this->jpegUpload(YouTubeThumbnail::WIDTH, YouTubeThumbnail::HEIGHT),
            ...$this->compositionWithoutImage(),
            ...$overrides,
        ];
    }

    private function jpegUpload(int $width, int $height): UploadedFile
    {
        $path = storage_path('app/testing/upload-'.bin2hex(random_bytes(4)).'.jpg');
        $this->app->make(Filesystem::class)->ensureDirectoryExists(dirname($path));

        $image = imagecreatetruecolor($width, $height);
        imagejpeg($image, $path, 92);
        imagedestroy($image);

        return new UploadedFile($path, 'miniatura.jpg', 'image/jpeg', null, true);
    }

    /**
     * @return array<string, mixed>
     */
    private function compositionWithoutImage(): array
    {
        return [
            'name' => 'Plano 4',
            'shot_order' => 4,
            'frame_second' => 12.5,
            'line1' => 'NADIE ENCENDIÓ',
            'line2' => 'ESAS VELAS',
            'line3' => null,
            'font_size' => 132,
            'pos_y' => 58,
            'align' => 'left',
            'vignette' => 55,
            'contrast' => 118,
            'saturation' => 72,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function storyWithShots(array $rows): Story
    {
        $slug = 'thumb-story';
        $story = Story::factory()->create(['slug' => $slug]);
        $files = $this->app->make(Filesystem::class);
        $shots = [];

        foreach ($rows as $index => $row) {
            $imagePath = $this->cacheDirectory.DIRECTORY_SEPARATOR.'plano-'.$row['order'].'.jpg';
            $this->writeJpeg($imagePath, $row['contrast']);

            $shots[] = [
                'order' => $row['order'],
                'sceneOrder' => 1,
                'start' => $index * 5.0,
                'end' => ($index * 5.0) + 4.0,
                'subject' => $row['subject'],
                'framing' => $row['framing'],
                'motion' => 'static',
                'threatStage' => $row['threatStage'],
                'sourceText' => 'Una frase.',
                'description' => 'Una descripción.',
                'prompt' => 'un prompt',
                'imagePath' => $imagePath,
                'isIntro' => $row['isIntro'] ?? false,
                'isOutro' => $row['isOutro'] ?? false,
                'placeholder' => false,
            ];
        }

        $directory = $this->storiesDirectory.DIRECTORY_SEPARATOR.$slug;
        $files->ensureDirectoryExists($directory);
        $files->put(
            $directory.DIRECTORY_SEPARATOR.'shots.json',
            (string) json_encode(['version' => 2, 'plannerVersion' => 1, 'shots' => $shots]),
        );

        return $story;
    }

    /**
     * Un JPEG de verdad, porque la nota se mide sobre los píxeles. "high" es medio negro y
     * medio gris claro —una silueta a contraluz, mucha desviación—; "flat" es gris uniforme.
     */
    private function writeJpeg(string $path, string $contrast): void
    {
        $image = imagecreatetruecolor(64, 36);
        $dark = imagecolorallocate($image, 10, 10, 10);
        $light = imagecolorallocate($image, 150, 150, 150);
        $flat = imagecolorallocate($image, 70, 70, 70);

        if ($contrast === 'high') {
            imagefilledrectangle($image, 0, 0, 31, 35, $dark);
            imagefilledrectangle($image, 32, 0, 63, 35, $light);
        } else {
            imagefilledrectangle($image, 0, 0, 63, 35, $flat);
        }

        $this->app->make(Filesystem::class)->ensureDirectoryExists(dirname($path));
        imagejpeg($image, $path, 92);
        imagedestroy($image);
    }

    private function candidates(): ThumbnailCandidates
    {
        return $this->app->make(ThumbnailCandidates::class);
    }
}
