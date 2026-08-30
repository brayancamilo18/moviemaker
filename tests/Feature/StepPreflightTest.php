<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\StoryStatus;
use App\Models\Story;
use App\Services\Audio\AudioLibrary;
use App\Services\Audio\SoundCategorizer;
use App\Services\Pipeline\StepPreflight;
use App\Services\Tts\KokoroTts;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class StepPreflightTest extends TestCase
{
    use RefreshDatabase;

    private string $workDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        $this->workDirectory = storage_path('app/testing/preflight-'.bin2hex(random_bytes(4)));
        (new Filesystem)->ensureDirectoryExists($this->workDirectory);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->workDirectory);

        parent::tearDown();
    }

    public function test_a_down_sidecar_fails_the_narration_check_with_its_fix(): void
    {
        Http::fake([
            'http://127.0.0.1:8020/health' => Http::response('boom', 500),
        ]);

        $checks = $this->app->make(StepPreflight::class)->check('narration');
        $sidecar = $this->named($checks, 'sidecar de Kokoro');

        $this->assertFalse($sidecar['ok']);
        $this->assertSame(KokoroTts::START_COMMAND, $sidecar['fix']);
        $this->assertNotSame('', $sidecar['detail']);
    }

    public function test_every_check_is_ok_when_the_machine_is_ready(): void
    {
        $this->prepareReadyMachine();

        foreach ($this->app->make(StepPreflight::class)->all() as $step => $checks) {
            $this->assertNotSame([], $checks, $step);

            foreach ($checks as $check) {
                $this->assertTrue(
                    $check['ok'],
                    $step.' / '.$check['name'].': '.$check['detail'],
                );
            }
        }
    }

    public function test_progress_includes_preflight_for_the_next_step_of_the_status(): void
    {
        $this->prepareReadyMachine();

        $expected = [
            StoryStatus::ScriptReady->value => 'narration',
            StoryStatus::Narrated->value => 'images',
            StoryStatus::ImagesReady->value => 'sound',
            StoryStatus::Mixed->value => 'render',
            StoryStatus::Draft->value => null,
        ];

        foreach ($expected as $status => $step) {
            $story = Story::factory()->create(['status' => StoryStatus::from($status)]);

            $this->get(route('stories.progress', $story))
                ->assertOk()
                ->assertJsonPath('preflight.step', $step);

            if ($step === null) {
                $this->get(route('stories.progress', $story))
                    ->assertJsonPath('preflight.checks', []);

                continue;
            }

            $checks = $this->get(route('stories.progress', $story))->json('preflight.checks');

            $this->assertIsArray($checks);
            $this->assertNotSame([], $checks);
        }
    }

    private function prepareReadyMachine(): void
    {
        $files = new Filesystem;
        $library = $this->workDirectory.'/audio';
        $cacheRelative = 'testing/preflight-cache-'.basename($this->workDirectory);
        $cache = storage_path('app/'.$cacheRelative);

        $files->ensureDirectoryExists($library.'/core');
        $files->ensureDirectoryExists($cache);

        $config = $this->app->make('config');
        $config->set('stories.whisper.binary', 'php');
        $config->set('stories.images.provider', 'pollinations');
        $config->set('stories.images.cache_path', $cacheRelative);
        $config->set('stories.audio.library_path', $library);
        $config->set('stories.audio.freesound.token', 'test-freesound-token');

        $this->app->forgetInstance(AudioLibrary::class);
        $this->app->forgetInstance(StepPreflight::class);

        foreach ($this->app->make(SoundCategorizer::class)->all() as $category) {
            $files->put($library.'/core/'.$category['coreFile'], 'wav');
        }

        Http::fake([
            'http://127.0.0.1:8020/health' => Http::response([
                'status' => 'ok',
                'model_loaded' => true,
            ], 200),
        ]);
    }

    /**
     * @param  list<array{name: string, ok: bool, detail: string, fix: string}>  $checks
     * @return array{name: string, ok: bool, detail: string, fix: string}
     */
    private function named(array $checks, string $name): array
    {
        foreach ($checks as $check) {
            if ($check['name'] === $name) {
                return $check;
            }
        }

        $this->fail("No apareció la comprobación '{$name}'.");
    }
}
