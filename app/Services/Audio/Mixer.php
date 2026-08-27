<?php

declare(strict_types=1);

namespace App\Services\Audio;

use App\Exceptions\FfmpegException;
use App\Services\Ffmpeg\FfmpegFilterScript;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

final class Mixer
{
    // Threshold 0.03 (≈ -30.5 dBFS) y ratio 6 son los valores reales del ducking. La reducción que
    // producen depende del nivel instantáneo de la narración, no es una cifra fija: con la voz a
    // -14 LUFS hunden la cama bastante más de los 6-9 dB que declaraban audio.mix.duck_db_min/max,
    // que se han borrado de config por eso mismo. Release 400 ms: uno más corto bombea entre palabras.
    private const SIDECHAIN = 'threshold=0.03:ratio=6:attack=20:release=400:makeup=1';

    private readonly string $ffmpeg;

    private readonly int $nice;

    private readonly float $timeout;

    public function __construct(
        private Filesystem $files,
        private LibraryClipProcessor $processor,
        private LoggerInterface $logger,
        private FfmpegFilterScript $filterScript,
        Repository $config,
    ) {
        $this->ffmpeg = (string) $config->get('stories.ffmpeg.binary');
        $this->nice = (int) $config->get('stories.ffmpeg.nice');
        $this->timeout = (float) $config->get('stories.ffmpeg.timeout');
    }

    /**
     * @param  list<AudioTrack>  $tracks
     */
    public function mix(array $tracks, string $outputPath): string
    {
        $tracks = $this->assertTracks($tracks);
        $script = $this->filterScript($tracks);
        $this->files->ensureDirectoryExists(dirname($outputPath));

        $workDir = storage_path('app/tmp/mixer-'.bin2hex(random_bytes(6)));
        $this->files->ensureDirectoryExists($workDir);
        $scriptPath = $workDir.DIRECTORY_SEPARATOR.'filter.txt';
        $this->files->put($scriptPath, $script);

        $arguments = [
            $this->ffmpeg, '-nostdin', '-y', '-hide_banner',
        ];

        foreach ($tracks as $track) {
            $arguments[] = '-i';
            $arguments[] = $track->path;
        }

        foreach ($this->filterScript->arguments($scriptPath) as $argument) {
            $arguments[] = $argument;
        }

        $arguments[] = '-map';
        $arguments[] = '[out]';
        $arguments[] = '-c:a';
        // f32le: la suma es pura (normalize=0) y puede pasar de 0 dBFS. En s16 el recorte sería
        // irreversible y ocurriría antes de que loudnorm y alimiter vieran nada.
        $arguments[] = 'pcm_f32le';
        $arguments[] = '-ar';
        $arguments[] = '48000';
        $arguments[] = '-ac';
        $arguments[] = '2';
        $arguments[] = '-f';
        $arguments[] = 'wav';
        $arguments[] = $outputPath;

        try {
            $this->run($arguments);
        } catch (FfmpegException $exception) {
            $dumpPath = $this->dumpFilter($outputPath, $script, $exception);

            throw new FfmpegException(
                $exception->getMessage()."\nFiltro volcado a {$dumpPath}",
                $exception->command,
                $exception->errorOutput,
                $exception->getCode(),
                $exception,
            );
        } finally {
            $this->files->deleteDirectory($workDir);
        }

        if (! $this->files->isFile($outputPath) || $this->files->size($outputPath) < 1) {
            throw new RuntimeException('El mezclador no escribió '.$outputPath.'.');
        }

        return $outputPath;
    }

    /**
     * @param  list<AudioTrack>  $tracks
     */
    public function filterScript(array $tracks): string
    {
        $tracks = $this->assertTracks($tracks);
        $chains = [];
        $narration = [];
        $duckable = [];
        $dry = [];

        foreach ($tracks as $index => $track) {
            $duration = $this->processor->duration($track->path);
            $label = 't'.$index;
            $chains[] = $this->trackFilter($track, $index, $label, $duration);

            if ($track->role === AudioTrack::ROLE_NARRATION) {
                $narration[] = $label;
            } elseif ($track->duckable) {
                $duckable[] = $label;
            } else {
                $dry[] = $label;
            }
        }

        $narrLabel = $this->mixLabels($narration, 'narr', $chains);
        $final = [];

        if ($narrLabel !== null && $duckable !== []) {
            $chains[] = '['.$narrLabel.']asplit=2[narr_mix][narr_sc]';
            $duckBus = $this->mixLabels($duckable, 'duck_bus', $chains) ?? $duckable[0];
            $chains[] = '['.$duckBus.'][narr_sc]sidechaincompress='.self::SIDECHAIN.'[ducked]';
            $final[] = 'ducked';
            $final[] = 'narr_mix';
        } elseif ($narrLabel !== null) {
            $final[] = $narrLabel;
        } else {
            foreach ($duckable as $label) {
                $final[] = $label;
            }
        }

        foreach ($dry as $label) {
            $final[] = $label;
        }

        $chains[] = $this->amix($final, 'out');

        return implode(";\n", $chains)."\n";
    }

    /**
     * @param  list<AudioTrack>  $tracks
     * @return list<AudioTrack>
     */
    private function assertTracks(array $tracks): array
    {
        if ($tracks === []) {
            throw new InvalidArgumentException('El mezclador necesita al menos una pista.');
        }

        $valid = [];

        foreach ($tracks as $index => $track) {
            if (! $track instanceof AudioTrack) {
                throw new InvalidArgumentException('La pista '.$index.' no es un AudioTrack.');
            }

            if (! $this->files->isFile($track->path)) {
                throw new InvalidArgumentException('No existe el audio de la pista '.$index.': '.$track->path);
            }

            $this->processor->assertAudio($track->path);
            $valid[] = $track;
        }

        return $valid;
    }

    private function trackFilter(AudioTrack $track, int $index, string $label, float $duration): string
    {
        $usable = $duration;

        if ($track->endAt !== null) {
            $usable = min($duration, $track->endAt - $track->startAt);
        }

        $parts = [
            sprintf('[%d:a]aformat=sample_fmts=fltp:sample_rates=48000:channel_layouts=stereo', $index),
        ];

        if ($track->endAt !== null) {
            $parts[] = sprintf('atrim=0:%.3f', $usable);
            $parts[] = 'asetpts=PTS-STARTPTS';
        }

        $fadeIn = min($track->fadeIn, $usable);
        $fadeOut = min($track->fadeOut, $usable);

        if ($fadeIn > 0) {
            $parts[] = sprintf('afade=t=in:st=0:d=%.3f', $fadeIn);
        }

        if ($fadeOut > 0) {
            $start = max(0.0, $usable - $fadeOut);
            $parts[] = sprintf('afade=t=out:st=%.3f:d=%.3f', $start, $fadeOut);
        }

        $parts[] = 'volume='.$this->formatDb($track->gainDb);

        $delayMs = (int) round($track->startAt * 1000);

        if ($delayMs > 0) {
            // El valor se repite por canal: si no, adelay solo retrasa el izquierdo.
            $parts[] = sprintf('adelay=%d|%d', $delayMs, $delayMs);
        }

        return implode(',', $parts).'['.$label.']';
    }

    /**
     * @param  list<string>  $labels
     * @param  list<string>  $chains
     */
    private function mixLabels(array $labels, string $out, array &$chains): ?string
    {
        if ($labels === []) {
            return null;
        }

        if (count($labels) === 1) {
            return $labels[0];
        }

        $chains[] = $this->amix($labels, $out);

        return $out;
    }

    /**
     * @param  list<string>  $labels
     */
    private function amix(array $labels, string $out): string
    {
        if ($labels === []) {
            throw new RuntimeException('amix no puede ejecutarse sin entradas.');
        }

        $inputs = implode('', array_map(
            static fn (string $label): string => '['.$label.']',
            $labels,
        ));

        // normalize=0: el amix por defecto divide el volumen entre entradas y destroza los niveles.
        return $inputs.'amix=inputs='.count($labels).':normalize=0['.$out.']';
    }

    private function formatDb(float $gainDb): string
    {
        $formatted = rtrim(rtrim(sprintf('%.3f', $gainDb), '0'), '.');

        if ($formatted === '' || $formatted === '-') {
            $formatted = '0';
        }

        return $formatted.'dB';
    }

    private function dumpFilter(string $outputPath, string $script, Throwable $exception): string
    {
        $dumpPath = $outputPath.'.filter.log';

        try {
            $this->files->ensureDirectoryExists(dirname($dumpPath));
            $this->files->put($dumpPath, $script);
        } catch (Throwable) {
            $dumpPath = storage_path('logs/mixer.filter.log');
            $this->files->ensureDirectoryExists(dirname($dumpPath));
            $this->files->put($dumpPath, $script);
        }

        $this->logger->error('FFmpeg mix falló. Filtro volcado a '.$dumpPath, [
            'filter' => $script,
            'error' => $exception->getMessage(),
        ]);

        return $dumpPath;
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
