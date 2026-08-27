<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\TextToSpeech;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class DoctorCommandTest extends TestCase
{
    private string $workDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        putenv('COLUMNS=200');

        $this->workDirectory = storage_path('app/testing/doctor-'.bin2hex(random_bytes(4)));
        (new Filesystem)->ensureDirectoryExists($this->workDirectory);

        $config = $this->app->make('config');
        $config->set('stories.audio.library_path', $this->workDirectory.'/audio');
        $config->set('stories.tts.base_url', 'http://127.0.0.1:8020');
        // Valores que sí están en su sitio, para que el único fallo sea el que prueba cada test.
        $config->set('stories.gemini.api_key', 'clave-de-prueba');
        $config->set('stories.whisper.binary', 'php');
        $config->set('stories.whisper.model', $this->workDirectory.'/whisper/ggml-base.en.bin');

        $this->app->forgetInstance(TextToSpeech::class);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->workDirectory);

        parent::tearDown();
    }

    public function test_a_missing_whisper_model_is_a_blocking_failure(): void
    {
        $this->fakeHealthySidecar();

        $this->artisan('story:doctor')
            ->assertFailed()
            ->expectsOutputToContain('[bloqueante] modelo de whisper: El modelo de whisper.cpp no existe en');
    }

    public function test_the_missing_model_explains_both_ways_to_fix_it(): void
    {
        $this->fakeHealthySidecar();

        $this->artisan('story:doctor')
            ->assertFailed()
            ->expectsOutputToContain(
                'define WHISPER_MODEL con la ruta absoluta a un ggml-*.bin, o déjala vacía y '
                .'coloca el modelo en storage/app/whisper/ggml-base.en.bin.',
            );
    }

    public function test_warn_only_reports_the_blocking_failure_but_succeeds(): void
    {
        $this->fakeHealthySidecar();

        $this->artisan('story:doctor', ['--warn-only' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('Con --warn-only el diagnóstico no interrumpe la instalación.');
    }

    public function test_a_broken_sidecar_is_a_blocking_failure(): void
    {
        $this->writeWhisperModel();

        Http::fake([
            'http://127.0.0.1:8020/health' => Http::response('boom', 500),
        ]);

        $this->artisan('story:doctor')
            ->assertFailed()
            ->expectsOutputToContain('[bloqueante] sidecar de Kokoro: No responde en /health.');
    }

    public function test_a_missing_manifest_is_only_a_warning(): void
    {
        $this->writeWhisperModel();
        $this->fakeHealthySidecar();

        $this->artisan('story:doctor')
            ->assertSuccessful()
            ->expectsOutputToContain('[aviso] manifest de audio')
            ->expectsOutputToContain('Entorno usable');
    }

    public function test_reports_clips_missing_from_disk(): void
    {
        $this->writeWhisperModel();
        $this->fakeHealthySidecar();

        $files = new Filesystem;
        $library = $this->workDirectory.'/audio';
        $files->ensureDirectoryExists($library.'/sfx');
        $files->put($library.'/sfx/present.wav', 'RIFF');
        $files->put($library.'/manifest.json', (string) json_encode([
            'version' => 1,
            'clips' => [
                ['file' => 'sfx/present.wav', 'type' => 'sfx'],
                ['file' => 'sfx/gone.wav', 'type' => 'sfx'],
            ],
        ]));

        $this->artisan('story:doctor')
            ->assertSuccessful()
            ->expectsOutputToContain('1 de 2 clips no están en disco: sfx/gone.wav');
    }

    public function test_passes_when_everything_is_in_place(): void
    {
        $this->writeWhisperModel();
        $this->fakeHealthySidecar();

        $files = new Filesystem;
        $library = $this->workDirectory.'/audio';
        $files->ensureDirectoryExists($library.'/sfx');
        $files->put($library.'/sfx/present.wav', 'RIFF');
        $files->put($library.'/manifest.json', (string) json_encode([
            'version' => 1,
            'clips' => [
                ['file' => 'sfx/present.wav', 'type' => 'sfx'],
            ],
        ]));

        $this->artisan('story:doctor')
            ->assertSuccessful()
            ->expectsOutputToContain('Entorno listo.');
    }

    public function test_never_prints_the_value_of_a_secret(): void
    {
        $this->writeWhisperModel();
        $this->fakeHealthySidecar();

        $this->app->make('config')->set('stories.audio.freesound.token', 'token-secretisimo');

        $this->artisan('story:doctor')
            ->assertSuccessful()
            ->expectsOutputToContain('definida')
            ->doesntExpectOutputToContain('token-secretisimo');
    }

    private function writeWhisperModel(): void
    {
        $path = $this->workDirectory.'/whisper/ggml-base.en.bin';
        (new Filesystem)->ensureDirectoryExists(dirname($path));
        (new Filesystem)->put($path, str_repeat('m', 2048));
    }

    private function fakeHealthySidecar(): void
    {
        Http::fake([
            'http://127.0.0.1:8020/health' => Http::response([
                'status' => 'ok',
                'model_loaded' => true,
            ], 200),
        ]);
    }
}
