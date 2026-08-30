<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\TextToSpeech;
use App\Services\Diagnostics\EnvironmentDoctor;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class DoctorCommandTest extends TestCase
{
    use RefreshDatabase;

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
        $config->set('stories.llm.gemini.api_key', 'clave-de-prueba');
        $config->set('stories.llm.anthropic.api_key', 'clave-de-respaldo');
        $config->set('stories.whisper.binary', 'php');
        $config->set('stories.whisper.model', $this->workDirectory.'/whisper/ggml-base.en.bin');
        $config->set('stories.doctor.config_cache_path', $this->workDirectory.'/no-config-cache.php');

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
            '*generativelanguage.googleapis.com*' => Http::response('not found', 404),
            '*api.anthropic.com*' => Http::response('unauthorized', 401),
        ]);

        $this->artisan('story:doctor')
            ->assertFailed()
            ->expectsOutputToContain('[bloqueante] sidecar de Kokoro: No responde en /health.');
    }

    public function test_it_puts_the_source_resolution_on_the_table(): void
    {
        $this->writeWhisperModel();
        $this->fakeHealthySidecar();

        $config = $this->app->make('config');
        $config->set('stories.images.width', 1024);
        $config->set('stories.video.width', 1280);
        $config->set('stories.video.zoom_max', 1.18);

        $this->artisan('story:doctor')
            ->assertSuccessful()
            ->expectsOutputToContain('Fuentes de 1024 px para una salida de 1280 px');
    }

    public function test_a_source_that_covers_the_zoom_is_not_a_warning(): void
    {
        $this->writeWhisperModel();
        $this->fakeHealthySidecar();

        $config = $this->app->make('config');
        $config->set('stories.images.width', 2048);
        $config->set('stories.video.width', 1280);
        $config->set('stories.video.zoom_max', 1.18);

        $this->artisan('story:doctor')
            ->assertSuccessful()
            ->expectsOutputToContain('hacen falta 1511 y sobran')
            ->doesntExpectOutputToContain('[aviso] resolución de las fuentes');
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

    public function test_a_single_provider_with_credential_is_enough(): void
    {
        $this->writeWhisperModel();
        $this->fakeHealthySidecar();

        $this->app->make('config')->set('stories.llm.gemini.api_key', '');

        $this->artisan('story:doctor')
            ->assertSuccessful()
            ->expectsOutputToContain('[aviso] GEMINI_API_KEY: ausente.')
            ->expectsOutputToContain('Entorno usable, pero con avisos.');
    }

    public function test_without_any_provider_the_doctor_blocks(): void
    {
        $this->writeWhisperModel();
        $this->fakeHealthySidecar();

        $config = $this->app->make('config');
        $config->set('stories.llm.gemini.api_key', '');
        $config->set('stories.llm.anthropic.api_key', '');

        $this->artisan('story:doctor')
            ->assertFailed()
            ->expectsOutputToContain(
                '[bloqueante] proveedor de LLM: Ni GEMINI_API_KEY ni ANTHROPIC_API_KEY están '
                .'definidas: no se puede generar el guion.',
            );
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

    public function test_model_keys_show_only_the_last_four_characters(): void
    {
        $this->writeWhisperModel();
        $this->fakeHealthySidecar();

        $this->artisan('story:doctor')
            ->assertSuccessful()
            ->expectsOutputToContain('termina en ueba')
            ->expectsOutputToContain('termina en aldo')
            ->doesntExpectOutputToContain('clave-de-prueba')
            ->doesntExpectOutputToContain('clave-de-respaldo');
    }

    public function test_a_http_401_or_404_counts_as_connectivity(): void
    {
        $this->writeWhisperModel();
        $this->fakeHealthySidecar();

        $checks = $this->checksByName();

        $this->assertTrue($checks['salida a Gemini']['ok']);
        $this->assertSame('green', $checks['salida a Gemini']['status']);
        $this->assertStringContainsString('HTTP 404', $checks['salida a Gemini']['detail']);
        $this->assertTrue($checks['salida a Anthropic']['ok']);
        $this->assertStringContainsString('HTTP 401', $checks['salida a Anthropic']['detail']);
    }

    public function test_a_connection_exception_is_a_blocking_network_failure(): void
    {
        $this->writeWhisperModel();

        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '127.0.0.1:8020/health')) {
                return Http::response(['status' => 'ok', 'model_loaded' => true], 200);
            }

            throw new ConnectionException(
                'cURL error 6: Could not resolve host: generativelanguage.googleapis.com',
            );
        });

        $gemini = $this->checksByName()['salida a Gemini'];

        $this->assertFalse($gemini['ok']);
        $this->assertTrue($gemini['blocking']);
        $this->assertSame('red', $gemini['status']);
        $this->assertStringContainsString('Could not resolve host', $gemini['detail']);
        $this->assertStringContainsString('Sin DNS', $gemini['detail']);
    }

    public function test_a_stale_queue_job_is_an_amber_warning_with_the_worker_command(): void
    {
        $this->writeWhisperModel();
        $this->fakeHealthySidecar();
        $this->app->make('config')->set('queue.default', 'database');

        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => '{}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->getTimestamp() - 20,
            'created_at' => now()->getTimestamp() - 20,
        ]);

        $cola = $this->checksByName()['cola'];

        $this->assertFalse($cola['ok']);
        $this->assertSame('amber', $cola['status']);
        $this->assertStringContainsString('QUEUE_CONNECTION=database', $cola['detail']);
        $this->assertSame('php artisan queue:work --tries=1', $cola['fix']);
    }

    public function test_a_cached_config_file_is_an_amber_warning(): void
    {
        $this->writeWhisperModel();
        $this->fakeHealthySidecar();

        $path = $this->workDirectory.'/cached-config.php';
        (new Filesystem)->put($path, '<?php return [];');
        $this->app->make('config')->set('stories.doctor.config_cache_path', $path);

        $cache = $this->checksByName()['config cacheada'];

        $this->assertFalse($cache['ok']);
        $this->assertSame('amber', $cache['status']);
        $this->assertSame('php artisan config:clear && php artisan cache:clear', $cache['fix']);
    }

    public function test_fix_hints_prints_the_command_for_each_failure(): void
    {
        $this->writeWhisperModel();
        $this->fakeHealthySidecar();

        $path = $this->workDirectory.'/cached-config.php';
        (new Filesystem)->put($path, '<?php return [];');
        $this->app->make('config')->set('stories.doctor.config_cache_path', $path);

        $this->artisan('story:doctor', ['--fix-hints' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('php artisan config:clear && php artisan cache:clear');
    }

    public function test_the_stories_table_is_reported_as_queryable(): void
    {
        $this->writeWhisperModel();
        $this->fakeHealthySidecar();

        $this->artisan('story:doctor')
            ->assertSuccessful()
            ->expectsOutputToContain('Tabla stories consultable');
    }

    private function writeWhisperModel(): void
    {
        $path = $this->workDirectory.'/whisper/ggml-base.en.bin';
        (new Filesystem)->ensureDirectoryExists(dirname($path));
        (new Filesystem)->put($path, str_repeat('m', 2048));
    }

    /**
     * @return array<string, array{name: string, ok: bool, blocking: bool, status: string, detail: string, fix: string}>
     */
    private function checksByName(): array
    {
        $checks = $this->app->make(EnvironmentDoctor::class)->checks();

        return array_column($checks, null, 'name');
    }

    private function fakeHealthySidecar(): void
    {
        Http::fake([
            'http://127.0.0.1:8020/health' => Http::response([
                'status' => 'ok',
                'model_loaded' => true,
            ], 200),
            '*generativelanguage.googleapis.com*' => Http::response('not found', 404),
            '*api.anthropic.com*' => Http::response('unauthorized', 401),
        ]);
    }
}
