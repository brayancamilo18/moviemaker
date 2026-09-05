<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\TextToSpeech;
use App\Services\Tts\KokoroTts;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Client\Factory;
use Tests\TestCase;

final class PruneStoryCommandTest extends TestCase
{
    private string $salida;

    private string $cache;

    private string $imagenes;

    protected function setUp(): void
    {
        parent::setUp();

        $raiz = storage_path('app/testing/prune-'.bin2hex(random_bytes(4)));
        $this->salida = $raiz.'/stories';
        $this->cache = $raiz.'/tts-cache';
        $this->imagenes = $raiz.'/image-cache';

        $files = $this->app->make(Filesystem::class);

        foreach ([$this->salida, $this->cache, $this->imagenes] as $dir) {
            $files->ensureDirectoryExists($dir);
        }

        config([
            'stories.output_path' => 'testing/'.basename($raiz).'/stories',
            'stories.story.intro.text' => 'This is the channel intro. Stay to the end.',
            'stories.story.outro.text' => 'That was the story for tonight.',
        ]);

        $this->app->forgetInstance(TextToSpeech::class);
        $this->app->singleton(TextToSpeech::class, fn (): TextToSpeech => new KokoroTts(
            http: $this->app->make(Factory::class),
            files: $this->app->make(Filesystem::class),
            baseUrl: 'http://127.0.0.1:8020',
            voice: 'af_heart',
            speed: 1.0,
            timeout: 5,
            cacheDirectory: $this->cache,
        ));
    }

    protected function tearDown(): void
    {
        $this->app->make(Filesystem::class)->deleteDirectory(dirname($this->salida));

        parent::tearDown();
    }

    public function test_a_story_without_a_video_is_left_untouched(): void
    {
        $this->story('sin-video', conVideo: false, frases: ['A door closed downstairs.']);

        $this->artisan('story:prune')->assertSuccessful();

        $this->assertTrue($this->cached('A door closed downstairs.'));
        $this->assertFileExists($this->imagenes.'/sin-video-1.jpg');
    }

    public function test_a_finished_story_releases_its_images_and_its_voice(): void
    {
        $this->story('terminada', conVideo: true, frases: ['A door closed downstairs.']);

        $this->artisan('story:prune')->assertSuccessful();

        $this->assertFalse($this->cached('A door closed downstairs.'));
        $this->assertFileDoesNotExist($this->imagenes.'/terminada-1.jpg');
    }

    public function test_the_channel_intro_and_outro_survive_the_prune(): void
    {
        // Son texto fijo, iguales en cada historia: soltarlas es pagarlas otra vez.
        $this->story('terminada', conVideo: true, frases: [
            'A door closed downstairs.',
            'This is the channel intro.',
            'That was the story for tonight.',
        ]);

        $this->artisan('story:prune')->assertSuccessful();

        $this->assertFalse($this->cached('A door closed downstairs.'));
        $this->assertTrue($this->cached('This is the channel intro.'));
        $this->assertTrue($this->cached('That was the story for tonight.'));
    }

    public function test_a_dry_run_reports_without_deleting_anything(): void
    {
        $this->story('terminada', conVideo: true, frases: ['A door closed downstairs.']);

        $this->artisan('story:prune', ['--dry-run' => true])->assertSuccessful();

        $this->assertTrue($this->cached('A door closed downstairs.'));
        $this->assertFileExists($this->imagenes.'/terminada-1.jpg');
    }

    public function test_keep_images_releases_only_the_voice(): void
    {
        $this->story('terminada', conVideo: true, frases: ['A door closed downstairs.']);

        $this->artisan('story:prune', ['--keep-images' => true])->assertSuccessful();

        $this->assertFalse($this->cached('A door closed downstairs.'));
        $this->assertFileExists($this->imagenes.'/terminada-1.jpg');
    }

    private function cached(string $texto): bool
    {
        return $this->app->make(TextToSpeech::class)->isCached($texto);
    }

    /**
     * @param  list<string>  $frases
     */
    private function story(string $slug, bool $conVideo, array $frases): void
    {
        $files = $this->app->make(Filesystem::class);
        $carpeta = $this->salida.'/'.$slug;
        $files->ensureDirectoryExists($carpeta);

        if ($conVideo) {
            $files->put($carpeta.'/video.mp4', 'mp4');
        }

        $imagen = $this->imagenes.'/'.$slug.'-1.jpg';
        $files->put($imagen, str_repeat('x', 2048));
        $files->put($carpeta.'/shots.json', json_encode([
            'shots' => [['order' => 1, 'imagePath' => $imagen]],
        ]));

        $sentences = [];

        foreach ($frases as $i => $frase) {
            $sentences[] = ['order' => $i + 1, 'text' => $frase, 'ttsText' => $frase];
            // El nombre lo decide la misma función que usa el driver, así que se
            // escribe a través de él en vez de replicar el hash aquí.
            $files->put($this->cachePathFor($frase), str_repeat('a', 4096));
        }

        $files->put($carpeta.'/timings.json', json_encode(['sentences' => $sentences]));
    }

    private function cachePathFor(string $texto): string
    {
        return $this->cache.'/'.sha1($texto.'af_heart'.'1').'.wav';
    }
}
