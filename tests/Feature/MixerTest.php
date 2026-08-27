<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\FfmpegException;
use App\Services\Audio\AudioTrack;
use App\Services\Audio\LibraryClipProcessor;
use App\Services\Audio\MasterProcessor;
use App\Services\Audio\Mixer;
use Illuminate\Filesystem\Filesystem;
use InvalidArgumentException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class MixerTest extends TestCase
{
    private string $workDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workDir = storage_path('app/testing/mixer-'.bin2hex(random_bytes(4)));
        (new Filesystem)->ensureDirectoryExists($this->workDir);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->workDir);

        parent::tearDown();
    }

    public function test_filter_script_delays_both_channels_and_ducks_against_narration(): void
    {
        $narration = $this->makeWav('narration.wav', 1.0);
        $ambience = $this->makeWav('ambience.wav', 2.0);
        $sfx = $this->makeWav('sfx.wav', 0.4);

        $script = $this->app->make(Mixer::class)->filterScript([
            new AudioTrack($narration, AudioTrack::ROLE_NARRATION, 0.0, null, 0.0, false, 0.0, 0.0),
            new AudioTrack($ambience, AudioTrack::ROLE_AMBIENCE, 0.0, null, -18.0, true, 0.2, 0.2),
            new AudioTrack($sfx, AudioTrack::ROLE_SFX, 1.5, null, 0.0, false, 0.0, 0.0),
        ]);

        $this->assertStringContainsString('adelay=1500|1500', $script);
        $this->assertStringContainsString('asplit=2[narr_mix][narr_sc]', $script);
        $this->assertStringContainsString(
            'sidechaincompress=threshold=0.03:ratio=6:attack=20:release=400:makeup=1',
            $script,
        );
        $this->assertStringContainsString('amix=inputs=3:normalize=0[out]', $script);
        $this->assertStringNotContainsString('loudnorm', $script);
        $this->assertStringContainsString('volume=-18dB', $script);
        $this->assertStringContainsString('afade=t=in:st=0:d=0.200', $script);
    }

    public function test_mix_with_only_narration_writes_48khz_stereo_wav(): void
    {
        $narration = $this->makeWav('narration.wav', 1.0);
        $output = $this->workDir.'/mix.wav';

        $path = $this->app->make(Mixer::class)->mix([
            new AudioTrack($narration, AudioTrack::ROLE_NARRATION, 0.0, null, 0.0, false, 0.0, 0.0),
        ], $output);

        $this->assertSame($output, $path);
        $this->assertFileExists($output);

        $probe = $this->probe($output);
        $this->assertSame(48000, $probe['sample_rate']);
        $this->assertSame(2, $probe['channels']);
        $this->assertEqualsWithDelta(1.0, $probe['duration'], 0.15);
    }

    public function test_mix_with_duckable_ambience_succeeds(): void
    {
        $narration = $this->makeWav('narration.wav', 1.2);
        $ambience = $this->makeWav('ambience.wav', 1.5);
        $output = $this->workDir.'/mix-duck.wav';

        $this->app->make(Mixer::class)->mix([
            new AudioTrack($narration, AudioTrack::ROLE_NARRATION, 0.0, null, 0.0, false, 0.0, 0.0),
            new AudioTrack($ambience, AudioTrack::ROLE_AMBIENCE, 0.0, null, -18.0, true, 0.05, 0.05),
        ], $output);

        $this->assertFileExists($output);
        $this->assertGreaterThan(1000, (new Filesystem)->size($output));
    }

    public function test_delayed_sfx_extends_the_mix_without_normalizing(): void
    {
        $narration = $this->makeWav('narration.wav', 1.0);
        $sfx = $this->makeWav('sfx.wav', 0.5);
        $output = $this->workDir.'/mix-delay.wav';

        $this->app->make(Mixer::class)->mix([
            new AudioTrack($narration, AudioTrack::ROLE_NARRATION, 0.0, null, 0.0, false, 0.0, 0.0),
            new AudioTrack($sfx, AudioTrack::ROLE_SFX, 1.5, null, 0.0, false, 0.0, 0.0),
        ], $output);

        $this->assertEqualsWithDelta(2.0, $this->probe($output)['duration'], 0.2);
    }

    /**
     * Nivel real, no estructura del filtro: un golpe a -0.5 dBFS sumado a pelo (normalize=0) sobre
     * narración y cama se sale de 0 dBFS, y en s16 el recorte llegaba al máster ya hecho.
     */
    public function test_a_hit_at_minus_half_a_decibel_does_not_clip_the_intermediate_mix(): void
    {
        $narration = $this->makeWav('narration.wav', 4.0, -6.0);
        $ambience = $this->makeWav('ambience.wav', 4.0, -30.0);
        $sfx = $this->makeWav('sfx.wav', 1.0, -0.5);
        $output = $this->workDir.'/mix-levels.wav';

        $gainDb = $this->app->make(LibraryClipProcessor::class)->sfxGainDb($sfx);

        $this->app->make(Mixer::class)->mix([
            new AudioTrack($narration, AudioTrack::ROLE_NARRATION, 0.0, null, 0.0, false, 0.0, 0.0),
            new AudioTrack($ambience, AudioTrack::ROLE_AMBIENCE, 0.0, null, 0.0, true, 0.0, 0.0),
            new AudioTrack($sfx, AudioTrack::ROLE_SFX, 1.0, null, $gainDb, false, 0.0, 0.0),
        ], $output);

        $truePeak = $this->app->make(MasterProcessor::class)->measure($output)['truePeak'];

        $this->assertLessThan(0.0, $truePeak, sprintf('La mezcla intermedia recorta: %.1f dBTP.', $truePeak));
    }

    public function test_failed_mix_dumps_the_full_filter_script(): void
    {
        $narration = $this->makeWav('narration.wav', 0.5);
        $outputDir = $this->workDir.'/not-a-file';
        (new Filesystem)->ensureDirectoryExists($outputDir);

        try {
            $this->app->make(Mixer::class)->mix([
                new AudioTrack($narration, AudioTrack::ROLE_NARRATION, 0.0, null, 0.0, false, 0.0, 0.0),
            ], $outputDir);
            $this->fail('Se esperaba FfmpegException.');
        } catch (FfmpegException $exception) {
            $dump = $outputDir.'.filter.log';
            $this->assertFileExists($dump);
            $this->assertStringContainsString('amix=inputs=1:normalize=0[out]', (string) file_get_contents($dump));
            $this->assertStringContainsString($dump, $exception->getMessage());
        }
    }

    public function test_audio_track_rejects_unknown_roles(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new AudioTrack('/tmp/x.wav', 'foley', 0.0, null, 0.0, false, 0.0, 0.0);
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

    private function makeWav(string $name, float $duration, ?float $peakDbfs = null): string
    {
        $path = $this->workDir.'/'.$name;
        $this->sine($path, $duration, 0.0);

        if ($peakDbfs !== null) {
            // La amplitud de la fuente sine de ffmpeg cambia entre versiones, así que un volume
            // fijo no fija el pico: hay que medir lo que ha salido y corregir.
            $this->sine($path, $duration, $peakDbfs - $this->samplePeakDbfs($path));
        }

        return $path;
    }

    private function sine(string $path, float $duration, float $gainDb): void
    {
        $process = new Process([
            'ffmpeg', '-nostdin', '-y', '-hide_banner',
            '-f', 'lavfi',
            '-i', sprintf('sine=frequency=220:sample_rate=48000:duration=%.3f', $duration),
            '-ac', '2',
            '-ar', '48000',
            '-sample_fmt', 's16',
            '-af', sprintf('volume=%.3fdB', $gainDb),
            $path,
        ]);
        $process->setTimeout(30);
        $process->mustRun();
    }

    private function samplePeakDbfs(string $path): float
    {
        $process = new Process([
            'ffmpeg', '-nostdin', '-hide_banner',
            '-i', $path,
            '-af', 'volumedetect',
            '-f', 'null',
            '-',
        ]);
        $process->setTimeout(30);
        $process->mustRun();

        $this->assertSame(
            1,
            preg_match('/max_volume:\s*(-?\d+(?:\.\d+)?)\s*dB/', $process->getErrorOutput(), $matches),
        );

        return (float) $matches[1];
    }
}
