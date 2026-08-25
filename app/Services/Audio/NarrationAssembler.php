<?php

declare(strict_types=1);

namespace App\Services\Audio;

use App\Exceptions\FfmpegException;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use InvalidArgumentException;
use JsonException;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Process\Process;

final class NarrationAssembler
{
    private readonly string $ffmpeg;

    private readonly string $ffprobe;

    private readonly int $nice;

    private readonly float $timeout;

    private readonly int $highpassHz;

    private readonly int $lowpassHz;

    private readonly string $aecho;

    private readonly float $loudnormI;

    private readonly float $loudnormTp;

    private readonly float $loudnormLra;

    private readonly string $mp3Bitrate;

    private readonly int $mp3SampleRate;

    private readonly string $storiesDirectory;

    public function __construct(
        private Filesystem $files,
        private LoggerInterface $logger,
        Repository $config,
    ) {
        $this->ffmpeg = (string) $config->get('stories.ffmpeg.binary');
        $this->ffprobe = (string) $config->get('stories.ffmpeg.ffprobe');
        $this->nice = (int) $config->get('stories.ffmpeg.nice');
        $this->timeout = (float) $config->get('stories.ffmpeg.timeout');
        $this->highpassHz = (int) $config->get('stories.ffmpeg.filters.highpass');
        $this->lowpassHz = (int) $config->get('stories.ffmpeg.filters.lowpass');
        $this->aecho = (string) $config->get('stories.ffmpeg.filters.aecho');
        $this->loudnormI = (float) $config->get('stories.ffmpeg.loudnorm.I');
        $this->loudnormTp = (float) $config->get('stories.ffmpeg.loudnorm.TP');
        $this->loudnormLra = (float) $config->get('stories.ffmpeg.loudnorm.LRA');
        $this->mp3Bitrate = (string) $config->get('stories.ffmpeg.mp3_bitrate');
        $this->mp3SampleRate = (int) $config->get('stories.ffmpeg.mp3_sample_rate');
        $this->storiesDirectory = storage_path('app/'.$config->get('stories.output_path'));
    }

    /**
     * @param  list<array{path: string, pauseAfter: float}>  $clips
     * @return array{wav: string, mp3: string, duration: float}
     */
    public function assemble(string $slug, array $clips): array
    {
        $slug = $this->assertSlug($slug);
        $clips = $this->assertClips($clips);

        $format = $this->probeFormat($clips[0]['path']);
        $workDir = $this->makeWorkDirectory();

        try {
            $listPath = $workDir.DIRECTORY_SEPARATOR.'concat.txt';
            $concatPath = $workDir.DIRECTORY_SEPARATOR.'concat.wav';
            $this->files->put($listPath, $this->concatList($clips, $format, $workDir));

            // Primer pase: concat demuxer, sin re-codificar. filter_complex no escala a ~200 cortes.
            $this->run([
                $this->ffmpeg, '-nostdin', '-y', '-hide_banner',
                '-f', 'concat', '-safe', '0', '-i', $listPath,
                '-c', 'copy',
                $concatPath,
            ]);

            $directory = $this->storiesDirectory.DIRECTORY_SEPARATOR.$slug;
            $this->files->ensureDirectoryExists($directory);

            $wavPath = $directory.DIRECTORY_SEPARATOR.'narration.wav';
            $mp3Path = $directory.DIRECTORY_SEPARATOR.'narration.mp3';

            $this->normalize($concatPath, $wavPath, $format);
            $this->encodeMp3($wavPath, $mp3Path);

            return [
                'wav' => $wavPath,
                'mp3' => $mp3Path,
                'duration' => $this->probeDuration($wavPath),
            ];
        } finally {
            $this->files->deleteDirectory($workDir);
        }
    }

    /**
     * @param  list<array{path: string, pauseAfter: float}>  $clips
     * @param  array{sample_rate: int, channels: int, codec_name: string, layout: string}  $format
     */
    private function concatList(array $clips, array $format, string $workDir): string
    {
        $silences = [];
        $lines = [];

        foreach ($clips as $clip) {
            $lines[] = $this->concatFileLine($clip['path']);

            $pauseAfter = $clip['pauseAfter'];

            if ($pauseAfter < 0.001) {
                continue;
            }

            $key = sprintf('%.3f', $pauseAfter);

            if (! isset($silences[$key])) {
                $silences[$key] = $this->makeSilence(
                    $workDir.DIRECTORY_SEPARATOR.'silence-'.$key.'.wav',
                    (float) $key,
                    $format,
                );
            }

            $lines[] = $this->concatFileLine($silences[$key]);
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * @param  array{sample_rate: int, channels: int, codec_name: string, layout: string}  $format
     */
    private function makeSilence(string $path, float $seconds, array $format): string
    {
        $this->run([
            $this->ffmpeg, '-nostdin', '-y', '-hide_banner',
            '-f', 'lavfi',
            '-i', sprintf('anullsrc=r=%d:cl=%s', $format['sample_rate'], $format['layout']),
            '-t', sprintf('%.3f', $seconds),
            '-c:a', $format['codec_name'],
            $path,
        ]);

        return $path;
    }

    /**
     * @param  array{sample_rate: int, channels: int, codec_name: string, layout: string}  $format
     */
    private function normalize(string $input, string $output, array $format): void
    {
        $chain = $this->filterChain();

        // Primera pasada de loudnorm: solo mide. La pasada única de loudnorm es imprecisa y se nota.
        $measured = $this->run([
            $this->ffmpeg, '-nostdin', '-y', '-hide_banner',
            '-i', $input,
            '-af', $chain.','.$this->loudnormFilter(['print_format' => 'json']),
            '-f', 'null',
            '-',
        ]);

        $stats = $this->parseLoudnorm($measured->getErrorOutput());

        $this->run([
            $this->ffmpeg, '-nostdin', '-y', '-hide_banner',
            '-i', $input,
            '-af', $chain.','.$this->loudnormFilter([
                'measured_I' => $stats['input_i'],
                'measured_TP' => $stats['input_tp'],
                'measured_LRA' => $stats['input_lra'],
                'measured_thresh' => $stats['input_thresh'],
                'offset' => $stats['target_offset'],
                'linear' => 'true',
            ]),
            '-c:a', $format['codec_name'],
            '-ar', (string) $format['sample_rate'],
            '-ac', (string) $format['channels'],
            $output,
        ]);
    }

    private function encodeMp3(string $wavPath, string $mp3Path): void
    {
        // MPEG-1 exige 32/44.1/48 kHz para 320k; a 24 kHz LAME cae a 160k.
        $this->run([
            $this->ffmpeg, '-nostdin', '-y', '-hide_banner',
            '-i', $wavPath,
            '-ar', (string) $this->mp3SampleRate,
            '-codec:a', 'libmp3lame',
            '-b:a', $this->mp3Bitrate,
            $mp3Path,
        ]);
    }

    /**
     * @return array{sample_rate: int, channels: int, codec_name: string, layout: string}
     */
    private function probeFormat(string $path): array
    {
        $process = $this->run([
            $this->ffprobe, '-v', 'error',
            '-select_streams', 'a:0',
            '-show_entries', 'stream=sample_rate,channels,codec_name,channel_layout',
            '-of', 'json',
            $path,
        ]);

        try {
            /** @var array{streams?: list<array{sample_rate?: string|int, channels?: int, codec_name?: string, channel_layout?: string}>} $payload */
            $payload = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException(
                'ffprobe no devolvió JSON válido para '.$path.'.',
                previous: $exception,
            );
        }

        $stream = $payload['streams'][0] ?? null;
        $sampleRate = (int) ($stream['sample_rate'] ?? 0);
        $channels = (int) ($stream['channels'] ?? 0);
        $codec = (string) ($stream['codec_name'] ?? '');

        if ($sampleRate < 1 || $channels < 1 || $codec === '') {
            throw new RuntimeException('ffprobe no pudo leer el formato de '.$path.'.');
        }

        return [
            'sample_rate' => $sampleRate,
            'channels' => $channels,
            'codec_name' => $codec,
            'layout' => $this->channelLayout($channels, $stream['channel_layout'] ?? null),
        ];
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

        return $duration;
    }

    private function channelLayout(int $channels, mixed $layout): string
    {
        if (is_string($layout) && $layout !== '' && $layout !== 'unknown') {
            return $layout;
        }

        return match ($channels) {
            1 => 'mono',
            2 => 'stereo',
            default => $channels.'c',
        };
    }

    private function filterChain(): string
    {
        return sprintf(
            'highpass=f=%d,lowpass=f=%d,aecho=%s',
            $this->highpassHz,
            $this->lowpassHz,
            $this->aecho,
        );
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

    private function formatLoudnorm(float $value): string
    {
        return sprintf('%.2f', $value);
    }

    /**
     * @param  list<string>  $arguments
     */
    private function run(array $arguments): Process
    {
        $process = new Process(['nice', '-n', (string) $this->nice, ...$arguments]);
        $process->setTimeout($this->timeout);
        $process->run();

        $this->logger->info('Salida de FFmpeg.', [
            'command' => $process->getCommandLine(),
            'exit_code' => $process->getExitCode(),
            'stderr' => $process->getErrorOutput(),
        ]);

        if (! $process->isSuccessful()) {
            throw FfmpegException::fromProcess($process);
        }

        return $process;
    }

    private function concatFileLine(string $path): string
    {
        $absolute = realpath($path);

        if ($absolute === false) {
            throw new RuntimeException('No se encontró el WAV '.$path.'.');
        }

        return "file '".str_replace("'", "'\\''", $absolute)."'";
    }

    private function makeWorkDirectory(): string
    {
        $directory = storage_path('app/tmp/narration-'.bin2hex(random_bytes(8)));
        $this->files->ensureDirectoryExists($directory);

        return $directory;
    }

    private function assertSlug(string $slug): string
    {
        $slug = trim($slug);

        if ($slug === '' || basename($slug) !== $slug) {
            throw new InvalidArgumentException('El slug de la historia no es válido.');
        }

        return $slug;
    }

    /**
     * @param  list<array{path: string, pauseAfter: float}>  $clips
     * @return list<array{path: string, pauseAfter: float}>
     */
    private function assertClips(array $clips): array
    {
        if ($clips === []) {
            throw new InvalidArgumentException('No hay clips WAV para ensamblar.');
        }

        foreach ($clips as $index => $clip) {
            $path = $clip['path'] ?? '';

            if (! is_string($path) || $path === '' || ! $this->files->isFile($path)) {
                throw new InvalidArgumentException("El clip WAV #{$index} no existe.");
            }
        }

        return $clips;
    }
}
