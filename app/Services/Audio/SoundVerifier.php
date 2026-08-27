<?php

declare(strict_types=1);

namespace App\Services\Audio;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

final class SoundVerifier
{
    private const MIN_BYTES = 5 * 1024;

    private const SFX_MIN_PEAK_DBFS = -35.0;

    private const CLIP_PEAK_DBFS = 0.0;

    private const AMBIENCE_UNIFORMITY_DB = 12.0;

    private readonly string $ffmpeg;

    private readonly string $ffprobe;

    private readonly int $nice;

    private readonly float $timeout;

    private readonly float $muteRmsDb;

    public function __construct(
        private Filesystem $files,
        Repository $config,
    ) {
        $this->ffmpeg = (string) $config->get('stories.ffmpeg.binary');
        $this->ffprobe = (string) $config->get('stories.ffmpeg.ffprobe');
        $this->nice = (int) $config->get('stories.ffmpeg.nice');
        $this->timeout = (float) $config->get('stories.ffmpeg.timeout');
        $this->muteRmsDb = (float) $config->get('stories.audio.resolve.silent_rms_db', -50.0);
    }

    public function verify(string $path, string $type, float $minDuration): VerificationResult
    {
        $size = $this->files->isFile($path) ? $this->files->size($path) : 0;

        if ($path === '' || ! $this->files->isFile($path) || $size <= self::MIN_BYTES) {
            return VerificationResult::fail(
                'No existe o pesa 5 KB o menos ('.$size.' B).',
            );
        }

        $probe = $this->probe($path);

        if ($probe === null) {
            return VerificationResult::fail(
                'ffprobe no reconoció una pista de audio con duración válida.',
            );
        }

        if ($probe['duration'] < $minDuration) {
            return VerificationResult::fail(sprintf(
                'La duración (%.3f s) no llega a minDuration (%.3f s).',
                $probe['duration'],
                $minDuration,
            ));
        }

        $loudness = $this->volumeDetect($path);

        if ($loudness['mean'] === null) {
            return VerificationResult::fail('No se pudo medir el RMS con volumedetect.');
        }

        if ($loudness['mean'] <= $this->muteRmsDb) {
            return VerificationResult::fail(sprintf(
                'Está mudo: RMS medio %.1f dB (umbral %.1f dB).',
                $loudness['mean'],
                $this->muteRmsDb,
            ));
        }

        if ($loudness['peak'] === null) {
            return VerificationResult::fail('No se pudo medir el pico con volumedetect.');
        }

        if ($loudness['peak'] >= self::CLIP_PEAK_DBFS) {
            return VerificationResult::fail(sprintf(
                'Saturado: el pico llega a %.1f dBFS.',
                $loudness['peak'],
            ));
        }

        if ($type === 'sfx' && $loudness['peak'] <= self::SFX_MIN_PEAK_DBFS) {
            return VerificationResult::fail(sprintf(
                'El pico (%.1f dBFS) es demasiado bajo para un golpe; el mínimo es %.1f dBFS.',
                $loudness['peak'],
                self::SFX_MIN_PEAK_DBFS,
            ));
        }

        if ($type !== 'ambience') {
            return VerificationResult::ok();
        }

        return $this->ambienceUniformity($path, $probe['duration']);
    }

    /**
     * @return array{duration: float}|null
     */
    private function probe(string $path): ?array
    {
        $process = $this->run([
            $this->ffprobe, '-v', 'error',
            '-select_streams', 'a:0',
            '-show_entries', 'stream=codec_type,codec_name,sample_rate,duration:format=duration',
            '-of', 'json',
            $path,
        ]);

        if (! $process->isSuccessful()) {
            return null;
        }

        $payload = json_decode($process->getOutput(), true);
        $stream = is_array($payload) ? ($payload['streams'][0] ?? null) : null;

        if (! is_array($stream)) {
            return null;
        }

        $codecType = (string) ($stream['codec_type'] ?? '');
        $codec = (string) ($stream['codec_name'] ?? '');
        $sampleRate = (int) ($stream['sample_rate'] ?? 0);
        $duration = (float) ($stream['duration'] ?? 0);

        if ($duration <= 0 && is_array($payload['format'] ?? null)) {
            $duration = (float) ($payload['format']['duration'] ?? 0);
        }

        if ($codecType !== 'audio' || $codec === '' || $sampleRate < 1 || $duration <= 0) {
            return null;
        }

        return ['duration' => round($duration, 3)];
    }

    /**
     * @return array{mean: ?float, peak: ?float}
     */
    private function volumeDetect(string $path, float $start = 0.0, ?float $length = null): array
    {
        $arguments = [$this->ffmpeg, '-nostdin', '-hide_banner'];

        if ($start > 0) {
            $arguments[] = '-ss';
            $arguments[] = sprintf('%.3f', $start);
        }

        if ($length !== null) {
            $arguments[] = '-t';
            $arguments[] = sprintf('%.3f', $length);
        }

        $arguments[] = '-i';
        $arguments[] = $path;
        $arguments[] = '-af';
        $arguments[] = 'volumedetect';
        $arguments[] = '-f';
        $arguments[] = 'null';
        $arguments[] = '-';

        $stderr = $this->run($arguments)->getErrorOutput();

        return [
            'mean' => $this->parseDb($stderr, 'mean_volume'),
            'peak' => $this->parseDb($stderr, 'max_volume'),
        ];
    }

    private function ambienceUniformity(string $path, float $duration): VerificationResult
    {
        $third = $duration / 3.0;

        if ($third < 0.05) {
            return VerificationResult::fail(
                'Demasiado corto para medir uniformidad de cama ('.sprintf('%.3f', $duration).' s).',
            );
        }

        $head = $this->volumeDetect($path, 0.0, $third)['mean'];
        $tail = $this->volumeDetect($path, $duration - $third, $third)['mean'];

        if ($head === null || $tail === null) {
            return VerificationResult::fail('No se pudo medir la uniformidad RMS de la cama.');
        }

        $delta = abs($head - $tail);

        if ($delta > self::AMBIENCE_UNIFORMITY_DB) {
            return VerificationResult::fail(sprintf(
                'No sirve como cama: el RMS del primer tercio (%.1f dB) y el del último (%.1f dB) difieren %.1f dB.',
                $head,
                $tail,
                $delta,
            ));
        }

        return VerificationResult::ok();
    }

    private function parseDb(string $stderr, string $label): ?float
    {
        if (preg_match('/'.$label.':\s*(-?(?:\d+\.?\d*|inf))\s*dB/i', $stderr, $matches) !== 1) {
            return null;
        }

        $raw = strtolower($matches[1]);

        if ($raw === 'inf' || $raw === '-inf') {
            return $raw === '-inf' ? -120.0 : 0.0;
        }

        return (float) $matches[1];
    }

    /**
     * @param  list<string>  $arguments
     */
    private function run(array $arguments): Process
    {
        $process = new Process(['nice', '-n', (string) $this->nice, ...$arguments]);
        $process->setTimeout($this->timeout);
        $process->run();

        return $process;
    }
}
