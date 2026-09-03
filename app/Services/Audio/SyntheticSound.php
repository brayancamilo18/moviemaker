<?php

declare(strict_types=1);

namespace App\Services\Audio;

use App\Exceptions\FfmpegException;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use InvalidArgumentException;
use Symfony\Component\Process\Process;

final class SyntheticSound
{
    public const PROFILE_WIND = 'wind';

    public const PROFILE_ROOM = 'room';

    public const PROFILE_DRONE = 'drone';

    public const PROFILE_IMPACT = 'impact';

    public const PROFILE_FRICTION = 'friction';

    public const PROFILE_NONE = 'none';

    private const FADE_SECONDS = 3.0;

    private const MIN_BED_DURATION = 6.0;

    // Sube con cada receta: el caché de generate() no debe devolver camas mudas viejas.
    private const RECIPE_VERSION = 4;

    private readonly string $ffmpeg;

    private readonly int $nice;

    private readonly float $timeout;

    private readonly string $directory;

    public function __construct(
        private Filesystem $files,
        Repository $config,
    ) {
        $this->ffmpeg = (string) $config->get('stories.ffmpeg.binary');
        $this->nice = (int) $config->get('stories.ffmpeg.nice');
        $this->timeout = (float) $config->get('stories.ffmpeg.timeout');
        $relative = (string) $config->get('stories.audio.resolve.synth_path', 'tmp/audio-synth');
        $this->directory = storage_path('app/'.$relative);
    }

    public function generate(string $profile, float $duration, int $seed = 0): string
    {
        $profile = $this->normalizeProfile($profile);

        if ($profile === self::PROFILE_NONE) {
            throw new InvalidArgumentException('No hay receta sintética para el perfil none.');
        }

        $seed = max(0, $seed);
        $duration = $this->renderDuration($profile, $duration, $seed);
        $hash = sha1(self::RECIPE_VERSION.':'.$profile.':'.sprintf('%.3f', $duration).':'.$seed);
        $path = $this->directory.DIRECTORY_SEPARATOR.$profile.'-'.$hash.'.wav';

        $this->files->ensureDirectoryExists($this->directory);

        if ($this->files->isFile($path) && $this->files->size($path) > 0) {
            return $path;
        }

        match ($profile) {
            self::PROFILE_WIND => $this->renderWind($path, $duration, $seed),
            self::PROFILE_ROOM => $this->renderRoom($path, $duration, $seed),
            self::PROFILE_DRONE => $this->renderDrone($path, $duration, $seed),
            self::PROFILE_IMPACT => $this->renderImpact($path, $duration, $seed),
            self::PROFILE_FRICTION => $this->renderFriction($path, $duration, $seed),
            default => throw new InvalidArgumentException("Perfil sintético desconocido: {$profile}."),
        };

        if (! $this->files->isFile($path) || $this->files->size($path) < 1) {
            throw new InvalidArgumentException('No se pudo generar el sonido sintético.');
        }

        return $path;
    }

    /**
     * @param  list<string>  $tags
     */
    public function inferProfile(string $query, array $tags = []): string
    {
        $haystack = mb_strtolower(trim($query.' '.implode(' ', $tags)));

        if ($this->containsAny($haystack, ['thud', 'impact', 'slam', 'bang', 'knock'])) {
            return self::PROFILE_IMPACT;
        }

        if ($this->containsAny($haystack, ['friction', 'scrape', 'creak', 'crack', 'wood stress', 'crackle'])) {
            return self::PROFILE_FRICTION;
        }

        if ($this->containsAny($haystack, ['wind', 'howl', 'exterior', 'outside', 'outdoor', 'forest', 'storm', 'gale', 'rain'])) {
            return self::PROFILE_WIND;
        }

        if ($this->containsAny($haystack, ['interior', 'indoor', 'room', 'house', 'empty', 'chamber'])) {
            return self::PROFILE_ROOM;
        }

        if ($this->containsAny($haystack, ['drone', 'dread', 'ominous', 'threat', 'hum', 'rumble'])) {
            return self::PROFILE_DRONE;
        }

        return self::PROFILE_DRONE;
    }

    public function normalizeProfile(string $profile): string
    {
        $profile = mb_strtolower(trim($profile));

        return match ($profile) {
            'wind', 'exterior', 'outside', 'outdoor' => self::PROFILE_WIND,
            'room', 'interior', 'indoor' => self::PROFILE_ROOM,
            'drone', 'dread' => self::PROFILE_DRONE,
            'impact' => self::PROFILE_IMPACT,
            'friction' => self::PROFILE_FRICTION,
            'none' => self::PROFILE_NONE,
            default => self::PROFILE_DRONE,
        };
    }

    public function isCredibleEffect(string $profile): bool
    {
        $profile = $this->normalizeProfile($profile);

        return $profile === self::PROFILE_IMPACT || $profile === self::PROFILE_FRICTION;
    }

    public function ambienceProfile(string $profile): string
    {
        $profile = $this->normalizeProfile($profile);

        return match ($profile) {
            self::PROFILE_WIND, self::PROFILE_ROOM, self::PROFILE_DRONE => $profile,
            default => self::PROFILE_DRONE,
        };
    }

    private function renderDuration(string $profile, float $duration, int $seed): float
    {
        if ($this->isCredibleEffect($profile)) {
            $duration = min(2.5, max(0.2, $duration));

            return round($duration * (1.0 + $this->unit($seed, 1) * 0.18), 3);
        }

        return max(self::MIN_BED_DURATION, $duration);
    }

    private function renderWind(string $path, float $duration, int $seed): void
    {
        $source = sprintf(
            'anoisesrc=color=brown:seed=%d:sample_rate=48000:duration=%.3f',
            $this->noiseSeed($seed, 1),
            $duration,
        );
        $lowpass = $this->vary($seed, 2, 500.0, 80.0, 350.0);
        $tremolo = $this->vary($seed, 3, 0.12, 0.04, 0.1);
        $body = sprintf(
            'highpass=f=100,lowpass=f=%.1f,tremolo=f=%.3f:d=0.4,volume=-12dB',
            $lowpass,
            $tremolo,
        );

        $this->renderSimple($path, $source, $body.','.$this->bedFades($duration));
    }

    private function renderRoom(string $path, float $duration, int $seed): void
    {
        $source = sprintf(
            'anoisesrc=color=brown:seed=%d:sample_rate=48000:duration=%.3f',
            $this->noiseSeed($seed, 1),
            $duration,
        );
        $lowpass = $this->vary($seed, 2, 200.0, 40.0, 140.0);
        $body = sprintf(
            'highpass=f=80,lowpass=f=%.1f,volume=-14dB',
            $lowpass,
        );

        $this->renderSimple($path, $source, $body.','.$this->bedFades($duration));
    }

    private function renderDrone(string $path, float $duration, int $seed): void
    {
        $lowHz = $this->vary($seed, 2, 55.0, 3.0, 50.0);
        $midHz = $this->vary($seed, 3, 82.4, 4.0, 76.0);
        $tremolo = $this->vary($seed, 4, 0.12, 0.03, 0.1);
        $lowpass = $this->vary($seed, 5, 400.0, 60.0, 280.0);
        $fades = $this->bedFades($duration);

        $low = sprintf('sine=frequency=%.2f:sample_rate=48000:duration=%.3f', $lowHz, $duration);
        $mid = sprintf('sine=frequency=%.2f:sample_rate=48000:duration=%.3f', $midHz, $duration);

        $this->run([
            $this->ffmpeg, '-nostdin', '-y', '-hide_banner',
            '-f', 'lavfi', '-i', $low,
            '-f', 'lavfi', '-i', $mid,
            '-filter_complex',
            sprintf(
                '[0:a][1:a]amix=inputs=2:normalize=0,tremolo=f=%.3f:d=0.35,lowpass=f=%.1f,volume=-12dB,%s[out]',
                $tremolo,
                $lowpass,
                $fades,
            ),
            '-map', '[out]',
            '-ac', '2',
            '-ar', '48000',
            '-sample_fmt', 's16',
            $path,
        ]);
    }

    private function renderImpact(string $path, float $duration, int $seed): void
    {
        $source = sprintf(
            'anoisesrc=color=brown:seed=%d:sample_rate=48000:duration=%.3f',
            $this->noiseSeed($seed, 1),
            $duration,
        );
        $lowpass = $this->vary($seed, 2, 400.0, 50.0, 320.0);
        $fadeIn = min(0.02, $duration * 0.08);
        $body = sprintf(
            // Como en friction, el lowpass deja poca energía y cuánta deja depende de la semilla:
            // con -8 dB había semillas que caían a -28,2 dBFS y no se oían en un portátil.
            'lowpass=f=%.1f,afade=t=in:st=0:d=%.3f,afade=t=out:st=0:d=%.3f:curve=exp,volume=-2dB,aformat=sample_rates=48000:channel_layouts=stereo',
            $lowpass,
            $fadeIn,
            $duration,
        );

        $this->renderSimple($path, $source, $body);
    }

    private function renderFriction(string $path, float $duration, int $seed): void
    {
        $source = sprintf(
            'anoisesrc=color=pink:seed=%d:sample_rate=48000:duration=%.3f',
            $this->noiseSeed($seed, 1),
            $duration,
        );
        $center = $this->vary($seed, 2, 1400.0, 350.0, 900.0);
        $width = $this->vary($seed, 3, 280.0, 80.0, 160.0);
        $fast = $this->vary($seed, 4, 12.0, 4.0, 8.0);
        $wobble = $this->vary($seed, 5, 3.4, 1.2, 1.6);
        $fade = min(0.06, max(0.02, $duration * 0.12));
        $fadeOutStart = max(0.0, $duration - $fade);
        $body = sprintf(
            // El bandpass estrecho se lleva casi toda la energía del ruido rosa, así que este
            // perfil necesita bastante más ganancia que los demás para oírse: con -4 dB el pico
            // se quedaba en -28,6 dBFS, por debajo del suelo de audibilidad que exige el test.
            'tremolo=f=%.2f:d=0.75,tremolo=f=%.2f:d=0.45,bandpass=f=%.1f:width_type=h:w=%.1f,afade=t=in:st=0:d=%.3f,afade=t=out:st=%.3f:d=%.3f,volume=+6dB,aformat=sample_rates=48000:channel_layouts=stereo',
            $fast,
            $wobble,
            $center,
            $width,
            $fade,
            $fadeOutStart,
            $fade,
        );

        $this->renderSimple($path, $source, $body);
    }

    private function renderSimple(string $path, string $source, string $filter): void
    {
        $this->run([
            $this->ffmpeg, '-nostdin', '-y', '-hide_banner',
            '-f', 'lavfi',
            '-i', $source,
            '-ac', '2',
            '-ar', '48000',
            '-sample_fmt', 's16',
            '-af', $filter,
            $path,
        ]);
    }

    private function bedFades(float $duration): string
    {
        $fade = min(self::FADE_SECONDS, max(0.5, $duration * 0.1));
        $fadeOutStart = max(0.0, $duration - $fade);

        return sprintf(
            'afade=t=in:st=0:d=%.3f,afade=t=out:st=%.3f:d=%.3f,aformat=sample_rates=48000:channel_layouts=stereo',
            $fade,
            $fadeOutStart,
            $fade,
        );
    }

    private function vary(int $seed, int $lane, float $base, float $spread, float $min): float
    {
        return max($min, $base + $this->unit($seed, $lane) * $spread);
    }

    private function unit(int $seed, int $lane): float
    {
        $unsigned = (int) sprintf('%u', crc32($seed.':'.$lane.':v3'));

        return (($unsigned % 2001) / 1000.0) - 1.0;
    }

    /**
     * anoisesrc sin seed coge una al azar en cada render, así que el mismo $seed no devolvía el
     * mismo audio: medido sobre el impact, el pico bailaba entre -20,8 y -26,9 dBFS de una pasada
     * a otra. Eso dejaba la caché de generate() indexando por hash un contenido que cambiaba, y
     * hacía que la comprobación de audibilidad fallara una vez de cada tantas.
     */
    private function noiseSeed(int $seed, int $lane): int
    {
        return (int) sprintf('%u', crc32($seed.':'.$lane.':noise-v1'));
    }

    /**
     * @param  list<string>  $needles
     */
    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $arguments
     */
    private function run(array $arguments): void
    {
        $process = new Process(['nice', '-n', (string) $this->nice, ...$arguments]);
        $process->setTimeout($this->timeout);
        $process->run();

        if (! $process->isSuccessful()) {
            throw FfmpegException::fromProcess($process);
        }
    }
}
