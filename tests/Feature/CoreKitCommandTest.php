<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Audio\AudioLibrary;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class CoreKitCommandTest extends TestCase
{
    private string $libraryDir;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Sleep::fake();
        putenv('COLUMNS=120');

        $this->libraryDir = storage_path('app/testing/audio-core-'.bin2hex(random_bytes(4)));
        $this->app->make('config')->set('stories.audio.library_path', $this->libraryDir);
        $this->app->make('config')->set('stories.audio.core_search_candidates', 4);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->libraryDir);

        parent::tearDown();
    }

    public function test_verify_fails_when_the_core_kit_is_missing(): void
    {
        $this->artisan('audio:core-kit', ['--verify' => true])
            ->assertFailed()
            ->expectsOutputToContain('Faltan o fallan');
    }

    public function test_downloads_the_best_rated_candidate_that_passes_the_verifier(): void
    {
        $silent = $this->wav('silent.wav', 1.0, mute: true);
        $audible = $this->wav('hit.wav', 1.0, mute: false);

        Http::fake([
            'freesound.org/apiv2/search/text*' => Http::response([
                'results' => [
                    $this->sound(10, 'Silent thud', 5.0, 90, 'https://freesound.org/data/previews/10.mp3'),
                    $this->sound(11, 'Good thud', 4.0, 10, 'https://freesound.org/data/previews/11.mp3'),
                ],
            ], 200),
            'https://freesound.org/data/previews/10.mp3' => Http::response(file_get_contents($silent), 200),
            'https://freesound.org/data/previews/11.mp3' => Http::response(file_get_contents($audible), 200),
        ]);

        $this->artisan('audio:core-kit', [
            '--only' => 'impact_dull',
        ])->assertSuccessful()
            ->expectsOutputToContain('impact_dull');

        $path = $this->libraryDir.'/core/impact-dull.wav';
        $this->assertFileExists($path);

        $clip = $this->app->make(AudioLibrary::class)->clips()[0];
        $this->assertTrue($clip['is_core']);
        $this->assertSame('core/impact-dull.wav', $clip['file']);
        $this->assertSame('11', $clip['source_id']);
    }

    public function test_keeps_an_existing_core_file_unless_forced(): void
    {
        $existing = $this->wav('existing.wav', 1.0, mute: false);
        $replacement = $this->wav('replacement.wav', 1.0, mute: false);
        $destination = $this->libraryDir.'/core/impact-dull.wav';

        (new Filesystem)->ensureDirectoryExists(dirname($destination));
        (new Filesystem)->copy($existing, $destination);

        Http::fake([
            'freesound.org/apiv2/search/text*' => Http::response([
                'results' => [
                    $this->sound(22, 'Replacement thud', 5.0, 1, 'https://freesound.org/data/previews/22.mp3'),
                ],
            ], 200),
            'https://freesound.org/data/previews/22.mp3' => Http::response(file_get_contents($replacement), 200),
        ]);

        $this->artisan('audio:core-kit', ['--only' => 'impact_dull'])
            ->assertSuccessful()
            ->expectsOutputToContain('conservado');

        Http::assertNothingSent();

        $this->artisan('audio:core-kit', [
            '--only' => 'impact_dull',
            '--force' => true,
        ])->assertSuccessful()
            ->expectsOutputToContain('descargado');

        $clip = $this->app->make(AudioLibrary::class)->clips()[0];
        $this->assertSame('22', $clip['source_id']);
        $this->assertTrue($clip['is_core']);
    }

    public function test_an_existing_core_file_absent_from_the_index_is_indexed_as_unknown_license(): void
    {
        $existing = $this->wav('existing.wav', 1.0, mute: false);
        $destination = $this->libraryDir.'/core/impact-dull.wav';

        (new Filesystem)->ensureDirectoryExists(dirname($destination));
        (new Filesystem)->copy($existing, $destination);

        $this->artisan('audio:core-kit', ['--only' => 'impact_dull'])
            ->assertSuccessful()
            ->expectsOutputToContain('conservado');

        $clip = $this->app->make(AudioLibrary::class)->clips()[0];

        $this->assertSame('core/impact-dull.wav', $clip['file']);
        $this->assertSame(AudioLibrary::LICENSE_UNKNOWN, $clip['license']);
        $this->assertSame(AudioLibrary::AUTHOR_UNKNOWN, $clip['author']);
        $this->assertTrue($clip['attribution_required']);
        Http::assertNothingSent();
    }

    public function test_verify_passes_when_the_core_file_is_audible(): void
    {
        $audible = $this->wav('ok.wav', 1.0, mute: false);
        $destination = $this->libraryDir.'/core/impact-dull.wav';
        (new Filesystem)->ensureDirectoryExists(dirname($destination));
        (new Filesystem)->copy($audible, $destination);

        $this->artisan('audio:core-kit', [
            '--verify' => true,
            '--only' => 'impact_dull',
        ])->assertSuccessful()
            ->expectsOutputToContain('verificadas');
    }

    public function test_rejects_an_unknown_only_slug(): void
    {
        $this->artisan('audio:core-kit', ['--only' => 'not_a_category'])
            ->assertFailed()
            ->expectsOutputToContain("No existe la categoría 'not_a_category'");
    }

    /**
     * @return array<string, mixed>
     */
    private function sound(int $id, string $name, float $rating, int $downloads, string $preview): array
    {
        return [
            'id' => $id,
            'name' => $name,
            'username' => 'field-recordist',
            'license' => 'Creative Commons 0',
            'duration' => 1.2,
            'avg_rating' => $rating,
            'num_downloads' => $downloads,
            'tags' => ['thud', 'impact'],
            'url' => 'https://freesound.org/people/field-recordist/sounds/'.$id.'/',
            'previews' => [
                'preview-hq-mp3' => $preview,
            ],
        ];
    }

    private function wav(string $name, float $duration, bool $mute): string
    {
        $path = $this->libraryDir.'/'.$name;
        (new Filesystem)->ensureDirectoryExists($this->libraryDir);

        $input = $mute
            ? sprintf('anullsrc=r=48000:cl=stereo:d=%.3f', $duration)
            : sprintf('sine=frequency=220:sample_rate=48000:duration=%.3f', $duration);

        $arguments = [
            'ffmpeg', '-nostdin', '-y', '-hide_banner',
            '-f', 'lavfi',
            '-i', $input,
            '-ac', '2',
            '-ar', '48000',
            '-sample_fmt', 's16',
        ];

        if (! $mute) {
            $arguments[] = '-af';
            $arguments[] = 'volume=-12dB';
        }

        $arguments[] = $path;

        $process = new Process($arguments);
        $process->setTimeout(30);
        $process->mustRun();

        return $path;
    }
}
