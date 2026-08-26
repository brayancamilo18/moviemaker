<?php

declare(strict_types=1);

namespace App\Services\Audio;

use App\Exceptions\FfmpegException;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use InvalidArgumentException;
use JsonException;
use RuntimeException;
use Symfony\Component\Process\Process;

final class MasterProcessor
{
    private readonly string $ffmpeg;

    private readonly string $ffprobe;

    private readonly int $nice;

    private readonly float $timeout;

    private readonly float $loudnormI;

    private readonly float $loudnormTp;

    private readonly float $loudnormLra;

    private readonly float $limiter;

    private readonly string $mp3Bitrate;

    private readonly int $mp3SampleRate;

    public function __construct(
        private Filesystem $files,
        Repository $config,
    ) {
        $this->ffmpeg = (string) $config->get('stories.ffmpeg.binary');
        $this->ffprobe = (string) $config->get('stories.ffmpeg.ffprobe');
        $this->nice = (int) $config->get('stories.ffmpeg.nice');
        $this->timeout = (float) $config->get('stories.ffmpeg.timeout');
        $this->loudnormI = (float) $config->get('stories.ffmpeg.loudnorm.I', -14.0);
        $this->loudnormTp = (float) $config->get('stories.ffmpeg.loudnorm.TP', -1.5);
        $this->loudnormLra = (float) $config->get('stories.ffmpeg.loudnorm.LRA', 11.0);
        $this->limiter = (float) $config->get('stories.ffmpeg.alimiter_limit', 0.95);
        $this->mp3Bitrate = (string) $config->get('stories.ffmpeg.mp3_bitrate', '320k');
        $this->mp3SampleRate = (int) $config->get('stories.ffmpeg.mp3_sample_rate', 48000);
    }

    /**
     * Masteriza la mezcla completa. No aplicar sobre la narración sola.
     * El WAV final dura exactamente $targetDuration (última frase + cola).
     *
     * @return array{wav: string, mp3: string}
     */
    public function process(string $mixPath, string $outputDirectory, float $targetDuration): array
    {
        if ($mixPath === '' || ! $this->files->isFile($mixPath)) {
            throw new InvalidArgumentException('No existe la mezcla a masterizar: '.$mixPath);
        }

        if ($targetDuration <= 0) {
            throw new InvalidArgumentException('La duración objetivo del máster debe ser mayor que 0.');
        }

        $this->files->ensureDirectoryExists($outputDirectory);
        $wavPath = rtrim($outputDirectory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'narration_mix.wav';
        $mp3Path = rtrim($outputDirectory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'narration_mix.mp3';
        $fitted = rtrim($outputDirectory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'.mix-target.wav';

        try {
            $sourceDuration = $this->duration($mixPath);

            if ($sourceDuration - $targetDuration > 0.5) {
                throw new RuntimeException(sprintf(
                    'El recorte eliminaría %.3f s de audio: mezcla %.3f s, objetivo %.3f s. '
                    .'Revisa NarrationClock: nunca se trunca narración en silencio.',
                    $sourceDuration - $targetDuration,
                    $sourceDuration,
                    $targetDuration,
                ));
            }

            $this->fitToDuration($mixPath, $fitted, $targetDuration);

            $measured = $this->run([
                $this->ffmpeg, '-nostdin', '-y', '-hide_banner',
                '-i', $fitted,
                // Primera pasada: solo mide. La pasada única de loudnorm es imprecisa y se nota.
                '-af', $this->loudnormFilter(['print_format' => 'json']),
                '-f', 'null',
                '-',
            ]);

            $stats = $this->parseLoudnorm($measured->getErrorOutput());

            $this->run([
                $this->ffmpeg, '-nostdin', '-y', '-hide_banner',
                '-i', $fitted,
                '-af', $this->loudnormFilter([
                    'measured_I' => $stats['input_i'],
                    'measured_TP' => $stats['input_tp'],
                    'measured_LRA' => $stats['input_lra'],
                    'measured_thresh' => $stats['input_thresh'],
                    'offset' => $stats['target_offset'],
                    'linear' => 'true',
                ]).',alimiter=limit='.$this->formatLimiter($this->limiter),
                '-c:a', 'pcm_s16le',
                '-ar', '48000',
                '-ac', '2',
                '-f', 'wav',
                $wavPath,
            ]);
        } finally {
            $this->files->delete($fitted);
        }

        $this->run([
            $this->ffmpeg, '-nostdin', '-y', '-hide_banner',
            '-i', $wavPath,
            '-ar', (string) $this->mp3SampleRate,
            '-codec:a', 'libmp3lame',
            '-b:a', $this->mp3Bitrate,
            $mp3Path,
        ]);

        if (! $this->files->isFile($wavPath) || $this->files->size($wavPath) < 1) {
            throw new RuntimeException('El masterizado no escribió '.$wavPath.'.');
        }

        if (! $this->files->isFile($mp3Path) || $this->files->size($mp3Path) < 1) {
            throw new RuntimeException('El masterizado no escribió '.$mp3Path.'.');
        }

        $actual = $this->probeDuration($wavPath);

        if (abs($actual - $targetDuration) > 0.05) {
            throw new RuntimeException(sprintf(
                'El máster dura %.3f s y se esperaban %.3f s (última frase + cola).',
                $actual,
                $targetDuration,
            ));
        }

        return [
            'wav' => $wavPath,
            'mp3' => $mp3Path,
        ];
    }

    /**
     * @return array{lufs: float, truePeak: float, lra: float}
     */
    public function measure(string $path): array
    {
        if ($path === '' || ! $this->files->isFile($path)) {
            throw new InvalidArgumentException('No existe el audio a medir: '.$path);
        }

        $process = $this->run([
            $this->ffmpeg, '-nostdin', '-hide_banner',
            '-i', $path,
            '-filter:a', 'ebur128=peak=true',
            '-f', 'null',
            '-',
        ]);

        return $this->parseEbur128($process->getErrorOutput(), $path);
    }

    private function fitToDuration(string $source, string $destination, float $duration): void
    {
        $duration = round(max(0.001, $duration), 3);

        $this->run([
            $this->ffmpeg, '-nostdin', '-y', '-hide_banner',
            '-i', $source,
            '-t', sprintf('%.3f', $duration),
            '-af', sprintf(
                'atrim=0:%.3f,apad=whole_dur=%.3f,asetpts=PTS-STARTPTS,aformat=sample_rates=48000:channel_layouts=stereo',
                $duration,
                $duration,
            ),
            '-c:a', 'pcm_s16le',
            '-ar', '48000',
            '-ac', '2',
            '-f', 'wav',
            $destination,
        ]);
    }

    private function duration(string $path): float
    {
        return $this->probeDuration($path);
    }

    private function probeDuration(string $path): float
    {
        $process = $this->run([
            $this->ffprobe, '-v', 'error',
            '-show_entries', 'format=duration',
            '-of', 'csv=p=0',
            $path,
        ]);

        $duration = (float) trim($process->getOutput());

        if ($duration <= 0) {
            throw new RuntimeException('ffprobe no pudo leer la duración de '.$path.'.');
        }

        return round($duration, 3);
    }

    /**
     * @param  array<string, float|string>  $options
     */
    private function loudnormFilter(array $options = []): string
    {
        $parts = [
            'I='.$this->formatLoudnorm($this->loudnormI),
            'TP='.$this->formatLoudnorm($this->loudnormTp),
            'LRA='.$this->formatLoudnorm($this->loudnormLra),
        ];

        foreach ($options as $name => $value) {
            $parts[] = $name.'='.$value;
        }

        return 'loudnorm='.implode(':', $parts);
    }

    /**
     * @return array{input_i: string, input_tp: string, input_lra: string, input_thresh: string, target_offset: string}
     */
    private function parseLoudnorm(string $stderr): array
    {
        $start = strrpos($stderr, '{');
        $end = strrpos($stderr, '}');

        if ($start === false || $end === false || $end <= $start) {
            throw new RuntimeException('FFmpeg no devolvió mediciones de loudnorm.');
        }

        try {
            /** @var array<string, mixed> $stats */
            $stats = json_decode(substr($stderr, $start, $end - $start + 1), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('FFmpeg devolvió mediciones de loudnorm ilegibles.', previous: $exception);
        }

        foreach (['input_i', 'input_tp', 'input_lra', 'input_thresh', 'target_offset'] as $key) {
            if (! isset($stats[$key]) || ! is_numeric($stats[$key])) {
                throw new RuntimeException("Falta la medición de loudnorm '{$key}'.");
            }
        }

        return [
            'input_i' => $this->formatLoudnorm((float) $stats['input_i']),
            'input_tp' => $this->formatLoudnorm((float) $stats['input_tp']),
            'input_lra' => $this->formatLoudnorm((float) $stats['input_lra']),
            'input_thresh' => $this->formatLoudnorm((float) $stats['input_thresh']),
            'target_offset' => $this->formatLoudnorm((float) $stats['target_offset']),
        ];
    }

    /**
     * @return array{lufs: float, truePeak: float, lra: float}
     */
    private function parseEbur128(string $stderr, string $path): array
    {
        $summary = $stderr;
        $offset = strripos($stderr, 'Summary:');

        if ($offset !== false) {
            $summary = substr($stderr, $offset);
        }

        if (preg_match('/Integrated loudness:\s*I:\s*(-?(?:\d+\.?\d*|inf))\s*LUFS/is', $summary, $integrated) !== 1
            && preg_match('/I:\s*(-?(?:\d+\.?\d*|inf))\s*LUFS/i', $summary, $integrated) !== 1) {
            throw new RuntimeException('FFmpeg no devolvió LUFS integrado para '.$path.'.');
        }

        if (preg_match('/Loudness range:\s*LRA:\s*(-?(?:\d+\.?\d*|inf))\s*LU/is', $summary, $range) !== 1
            && preg_match('/^\s*LRA:\s*(-?(?:\d+\.?\d*|inf))\s*LU\s*$/im', $summary, $range) !== 1) {
            throw new RuntimeException('FFmpeg no devolvió el rango dinámico para '.$path.'.');
        }

        if (preg_match('/True peak:.*?Peak:\s*(-?(?:\d+\.?\d*|inf))\s*dB/is', $summary, $peak) !== 1
            && preg_match('/Peak:\s*(-?(?:\d+\.?\d*|inf))\s*dB(?:FS|TP)?/i', $summary, $peak) !== 1) {
            throw new RuntimeException('FFmpeg no devolvió true peak para '.$path.'.');
        }

        $lufs = $this->eburNumber($integrated[1]);
        $lra = $this->eburNumber($range[1]);
        $truePeak = $this->eburNumber($peak[1]);

        if ($lufs === null || $lra === null || $truePeak === null) {
            throw new RuntimeException('FFmpeg devolvió mediciones ebur128 no numéricas para '.$path.'.');
        }

        return [
            'lufs' => round($lufs, 1),
            'truePeak' => round($truePeak, 1),
            'lra' => round($lra, 1),
        ];
    }

    private function eburNumber(string $value): ?float
    {
        if (strtolower($value) === 'inf' || strtolower($value) === '-inf') {
            return null;
        }

        return (float) $value;
    }

    private function formatLoudnorm(float $value): string
    {
        return sprintf('%.2f', $value);
    }

    private function formatLimiter(float $limit): string
    {
        $formatted = rtrim(rtrim(sprintf('%.4f', $limit), '0'), '.');

        return $formatted === '' ? '0.95' : $formatted;
    }

    /**
     * @param  list<string>  $arguments
     */
    private function run(array $arguments): Process
    {
        $process = new Process(['nice', '-n', (string) $this->nice, ...$arguments]);
        $process->setTimeout($this->timeout);
        $process->run();

        if (! $process->isSuccessful()) {
            throw FfmpegException::fromProcess($process);
        }

        return $process;
    }
}
