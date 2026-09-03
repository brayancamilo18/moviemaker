<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Audio\SyntheticSound;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class SyntheticSoundTest extends TestCase
{
    private string $synthDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->synthDir = 'testing/audio-synth-'.bin2hex(random_bytes(4));
        $this->app->make('config')->set('stories.audio.resolve.synth_path', $this->synthDir);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory(storage_path('app/'.$this->synthDir));

        parent::tearDown();
    }

    public function test_wind_and_exterior_write_48khz_stereo_with_requested_duration(): void
    {
        $synth = $this->app->make(SyntheticSound::class);

        $wind = $synth->generate('wind', 8.0);
        $exterior = $synth->generate('exterior', 8.0);

        $this->assertSame($wind, $exterior);
        $this->assertSame(48000, $this->probe($wind)['sample_rate']);
        $this->assertSame(2, $this->probe($wind)['channels']);
        $this->assertEqualsWithDelta(8.0, $this->probe($wind)['duration'], 0.15);
    }

    public function test_interior_room_and_dread_aliases(): void
    {
        $synth = $this->app->make(SyntheticSound::class);

        $this->assertSame($synth->generate('interior', 6.0), $synth->generate('room', 6.0));
        $this->assertSame($synth->generate('drone', 6.0), $synth->generate('dread', 6.0));
        $this->assertFileExists($synth->generate('interior', 6.0));
        $this->assertFileExists($synth->generate('dread', 6.0));
    }

    public function test_unknown_profile_falls_back_to_drone(): void
    {
        $synth = $this->app->make(SyntheticSound::class);

        $this->assertSame(
            $synth->generate('drone', 6.0),
            $synth->generate('whatever', 6.0),
        );
    }

    public function test_infers_profile_from_query(): void
    {
        $synth = $this->app->make(SyntheticSound::class);

        $this->assertSame(SyntheticSound::PROFILE_WIND, $synth->inferProfile('wind howling night'));
        $this->assertSame(SyntheticSound::PROFILE_ROOM, $synth->inferProfile('empty room tone', ['house']));
        $this->assertSame(SyntheticSound::PROFILE_DRONE, $synth->inferProfile('low drone ominous'));
        $this->assertSame(SyntheticSound::PROFILE_DRONE, $synth->inferProfile('totallyunknownfx'));
        $this->assertSame(SyntheticSound::PROFILE_IMPACT, $synth->inferProfile('dull thud impact'));
        $this->assertSame(SyntheticSound::PROFILE_FRICTION, $synth->inferProfile('wood scrape friction'));
    }

    public function test_pads_short_bed_durations_to_fit_fades(): void
    {
        $path = $this->app->make(SyntheticSound::class)->generate('wind', 2.0);

        $this->assertEqualsWithDelta(6.0, $this->probe($path)['duration'], 0.15);
    }

    public function test_seed_varies_the_same_profile(): void
    {
        $synth = $this->app->make(SyntheticSound::class);

        $first = $synth->generate('wind', 6.0, 1);
        $second = $synth->generate('wind', 6.0, 2);

        $this->assertNotSame($first, $second);
        $this->assertFileExists($first);
        $this->assertFileExists($second);
    }

    public function test_impact_and_friction_are_short_stereo_bursts(): void
    {
        $synth = $this->app->make(SyntheticSound::class);

        $impact = $synth->generate('impact', 0.55, 3);
        $friction = $synth->generate('friction', 0.95, 4);

        $this->assertSame(48000, $this->probe($impact)['sample_rate']);
        $this->assertSame(2, $this->probe($impact)['channels']);
        $this->assertLessThan(2.0, $this->probe($impact)['duration']);
        $this->assertGreaterThan(0.2, $this->probe($impact)['duration']);

        $this->assertSame(48000, $this->probe($friction)['sample_rate']);
        $this->assertSame(2, $this->probe($friction)['channels']);
        $this->assertLessThan(2.5, $this->probe($friction)['duration']);
        $this->assertGreaterThan(0.3, $this->probe($friction)['duration']);
    }

    /**
     * anoisesrc sin seed coge una al azar en cada render, así que la misma receta devolvía audio
     * distinto: el pico del impact bailaba entre -20,8 y -26,9 dBFS. Eso dejaba la caché de
     * generate() indexando por hash un contenido que cambiaba, y hacía que la comprobación de
     * audibilidad de aquí abajo fallara una vez de cada tantas.
     */
    public function test_the_same_seed_renders_byte_identical_audio(): void
    {
        $synth = $this->app->make(SyntheticSound::class);
        $files = new Filesystem;

        foreach (['impact' => 0.55, 'friction' => 0.95, 'wind' => 6.0, 'room' => 6.0] as $profile => $duration) {
            $first = $files->get($synth->generate($profile, $duration, 5));
            $files->delete($synth->generate($profile, $duration, 5));
            $second = $files->get($synth->generate($profile, $duration, 5));

            $this->assertSame(
                sha1($first),
                sha1($second),
                "El perfil {$profile} no es reproducible con la misma semilla.",
            );
        }
    }

    public function test_generated_beds_and_effects_are_audible_on_laptop_speakers(): void
    {
        $synth = $this->app->make(SyntheticSound::class);

        foreach (['wind', 'room', 'drone'] as $profile) {
            $path = $synth->generate($profile, 6.0);
            $loudness = $this->volume($path);

            $this->assertGreaterThan(
                -40.0,
                $loudness['mean'],
                "La cama {$profile} quedó muda (RMS {$loudness['mean']} dB).",
            );
            $this->assertGreaterThan(
                -28.0,
                $loudness['peak'],
                "La cama {$profile} no tiene pico audible ({$loudness['peak']} dBFS).",
            );
        }

        foreach (['impact', 'friction'] as $profile) {
            $path = $synth->generate($profile, $profile === 'impact' ? 0.55 : 0.95, 7);
            $loudness = $this->volume($path);

            $this->assertGreaterThan(
                -45.0,
                $loudness['mean'],
                "El efecto {$profile} quedó mudo (RMS {$loudness['mean']} dB).",
            );
            $this->assertGreaterThan(
                -28.0,
                $loudness['peak'],
                "El efecto {$profile} no tiene pico audible ({$loudness['peak']} dBFS).",
            );
        }
    }

    /**
     * @return array{sample_rate: int, channels: int, duration: float}
     */
    private function probe(string $path): array
    {
        $process = new Process([
            'ffprobe', '-v', 'error',
            '-select_streams', 'a:0',
            '-show_entries', 'stream=sample_rate,channels:format=duration',
            '-of', 'json',
            $path,
        ]);
        $process->setTimeout(30);
        $process->mustRun();

        /** @var array<string, mixed> $payload */
        $payload = json_decode($process->getOutput(), true);
        $stream = is_array($payload['streams'][0] ?? null) ? $payload['streams'][0] : [];

        return [
            'sample_rate' => (int) ($stream['sample_rate'] ?? 0),
            'channels' => (int) ($stream['channels'] ?? 0),
            'duration' => (float) ($payload['format']['duration'] ?? 0),
        ];
    }

    /**
     * @return array{mean: float, peak: float}
     */
    private function volume(string $path): array
    {
        $process = new Process([
            'ffmpeg', '-hide_banner', '-i', $path, '-af', 'volumedetect', '-f', 'null', '-',
        ]);
        $process->setTimeout(30);
        $process->mustRun();

        $stderr = $process->getErrorOutput();

        if (preg_match('/mean_volume:\s*(-?(?:\d+\.?\d*|inf))\s*dB/i', $stderr, $mean) !== 1
            || preg_match('/max_volume:\s*(-?(?:\d+\.?\d*|inf))\s*dB/i', $stderr, $peak) !== 1) {
            $this->fail('FFmpeg no midió el volumen de '.$path.'.');
        }

        return [
            'mean' => (float) $mean[1],
            'peak' => (float) $peak[1],
        ];
    }
}
