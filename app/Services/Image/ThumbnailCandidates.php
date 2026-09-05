<?php

declare(strict_types=1);

namespace App\Services\Image;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Filesystem\Filesystem;

/**
 * Propone los mejores planos de una historia como portada del vídeo.
 *
 * El criterio no es inventado: sale de las cinco portadas elegidas a mano para «The Miller's
 * Debt» (planos 95, 99, 100, 107 y 128 de 171). Las cinco eran subject=threat, las cinco eran
 * threatStage=presence —ninguna de las tres reveal que había— y las cinco eran medium shot o
 * low angle, entre el 55 % y el 75 % de la historia. A eso se le suma lo que la ficha del plano
 * no sabe y el fichero sí: una portada se ve a 168 píxeles de ancho en la lista de YouTube, y
 * ahí lo único que sobrevive es el contraste.
 *
 * Las candidatas se copian al directorio de la historia. En la caché viven bajo el hash de su
 * prompt y story:prune las borra en cuanto existe el MP4; una portada que desaparece cuando la
 * historia está lista para publicarse no sirve de nada.
 */
final class ThumbnailCandidates
{
    /** Cuántas portadas se proponen. */
    public const LIMIT = 10;

    /** Submuestreo al medir: un píxel de cada ocho por eje basta para media y desviación. */
    private const SAMPLE_STEP = 8;

    /**
     * Encuadres, por lo que aguanta una silueta reducida a 168 píxeles. Los planos cerrados
     * quedan fuera de hecho: a ese tamaño un detalle no se lee, y el director tiene prohibido
     * usarlos para la amenaza de todas formas.
     */
    private const FRAMING_SCORE = [
        'medium shot' => 1.0,
        'low angle' => 1.0,
        'wide establishing' => 0.6,
        'close detail' => 0.1,
        'extreme close up' => 0.1,
    ];

    /**
     * Presence por encima de reveal a propósito: de las tres reveal disponibles no se eligió
     * ninguna. Una amenaza incompleta intriga; una revelada entera ya contó el vídeo.
     */
    private const STAGE_SCORE = [
        'presence' => 1.0,
        'reveal' => 0.85,
        'hint' => 0.35,
    ];

    /**
     * Luminancia media fuera de la cual la portada no se lee: negro cerrado o quemado.
     *
     * El suelo está bajo a propósito. El material es low key y los planos de amenaza de una
     * historia cualquiera se mueven entre 22 y 84 de media, así que un umbral "razonable"
     * marcaría como defectuosa justo la silueta a contraluz que se busca. Lo que descarta
     * aquí es el fotograma negro; que una imagen oscura se lea o no lo decide el contraste.
     */
    private const DARK_FLOOR = 15.0;

    private const BRIGHT_CEILING = 170.0;

    /** Desviación típica de luminancia a partir de la cual el contraste ya no suma más. */
    private const CONTRAST_TARGET = 55.0;

    private readonly string $storiesDirectory;

    public function __construct(
        private Filesystem $files,
        private ContactSheet $sheet,
        Config $config,
    ) {
        $this->storiesDirectory = storage_path('app/'.$config->get('stories.output_path'));
    }

    /**
     * Las mejores portadas posibles, de más a menos, ya copiadas al directorio de la historia.
     *
     * @return list<array<string, mixed>>
     */
    public function propose(string $slug): array
    {
        $shots = $this->sheet->shots($slug);

        if ($shots === null) {
            return [];
        }

        $scored = [];

        foreach ($shots as $shot) {
            if (! $shot['hasImage'] || $shot['isIntro'] || $shot['isOutro'] || $shot['placeholder']) {
                continue;
            }

            $path = $this->sheet->imagePath($slug, $shot['order']);

            if ($path === null) {
                continue;
            }

            $measured = $this->measure($path);

            if ($measured === null) {
                continue;
            }

            $scored[] = $this->score($shot, $measured, $path);
        }

        usort($scored, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        $best = array_slice($scored, 0, self::LIMIT);

        foreach ($best as $index => $candidate) {
            $best[$index]['preserved'] = $this->preserve($slug, $candidate['order'], $candidate['path']);
            unset($best[$index]['path']);
        }

        return array_values($best);
    }

    /**
     * @param  array<string, mixed>  $shot
     * @param  array{mean: float, contrast: float}  $measured
     * @return array<string, mixed>
     */
    private function score(array $shot, array $measured, string $path): array
    {
        $isThreat = $shot['subject'] === 'threat';
        $framing = self::FRAMING_SCORE[$shot['framing']] ?? 0.4;
        $stage = self::STAGE_SCORE[$shot['threatStage']] ?? 0.2;
        $position = $this->positionScore((float) $shot['progress']);
        $contrast = min(1.0, $measured['contrast'] / self::CONTRAST_TARGET);
        $exposure = $this->exposureScore($measured['mean']);

        // La figura es la mitad de la nota. Una portada de terror sin la cosa que da miedo
        // puede ser una imagen bonita, pero no es la portada de este vídeo.
        $score = ($isThreat ? 0.50 : 0.0)
            + 0.12 * $framing
            + 0.12 * ($isThreat ? $stage : 0.0)
            + 0.08 * $position
            + 0.12 * $contrast
            + 0.06 * $exposure;

        return [
            'order' => $shot['order'],
            'score' => round($score, 4),
            'path' => $path,
            'subject' => $shot['subject'],
            'framing' => $shot['framing'],
            'threatStage' => $shot['threatStage'],
            'progress' => $shot['progress'],
            'seconds' => $shot['start'],
            'line' => $shot['line'],
            'description' => $shot['description'],
            'mean' => round($measured['mean'], 1),
            'contrast' => round($measured['contrast'], 1),
            'reasons' => $this->reasons($shot, $measured, $isThreat),
        ];
    }

    /**
     * Por qué está propuesta. Una nota sola no se puede discutir; estas frases sí.
     *
     * @param  array<string, mixed>  $shot
     * @param  array{mean: float, contrast: float}  $measured
     * @return list<string>
     */
    private function reasons(array $shot, array $measured, bool $isThreat): array
    {
        $reasons = [];

        if ($isThreat) {
            $reasons[] = $shot['threatStage'] === null
                ? 'la figura en cuadro'
                : 'la figura en cuadro, '.$shot['threatStage'];
        } else {
            $reasons[] = 'sin figura';
        }

        $framing = self::FRAMING_SCORE[$shot['framing']] ?? 0.4;

        if ($framing >= 1.0) {
            $reasons[] = $shot['framing'].', se lee en pequeño';
        } elseif ($framing <= 0.1) {
            $reasons[] = $shot['framing'].', se pierde en pequeño';
        }

        if ($measured['contrast'] >= self::CONTRAST_TARGET) {
            $reasons[] = 'mucho contraste';
        } elseif ($measured['contrast'] < self::CONTRAST_TARGET / 2) {
            $reasons[] = 'contraste plano';
        }

        if ($measured['mean'] < self::DARK_FLOOR) {
            $reasons[] = 'demasiado oscura';
        } elseif ($measured['mean'] > self::BRIGHT_CEILING) {
            $reasons[] = 'quemada';
        }

        $percent = (int) round(((float) $shot['progress']) * 100);
        $reasons[] = 'al '.$percent.' % de la historia';

        return $reasons;
    }

    /**
     * La portada sale de la segunda mitad, donde la amenaza ya está montada. Antes del tercio
     * inicial no hay nada que enseñar todavía; el final del todo es el desenlace y contarlo en
     * la portada es regalarlo.
     */
    private function positionScore(float $progress): float
    {
        if ($progress < 0.33) {
            return $progress / 0.33 * 0.4;
        }

        if ($progress <= 0.85) {
            return 1.0;
        }

        return max(0.4, 1.0 - ($progress - 0.85) / 0.15 * 0.6);
    }

    private function exposureScore(float $mean): float
    {
        if ($mean < self::DARK_FLOOR) {
            return max(0.0, $mean / self::DARK_FLOOR);
        }

        if ($mean > self::BRIGHT_CEILING) {
            return max(0.0, 1.0 - ($mean - self::BRIGHT_CEILING) / 85.0);
        }

        return 1.0;
    }

    /**
     * Media y desviación típica de la luminancia. Es lo que decide si algo se distingue a 168
     * píxeles: la desviación es el contraste, y la media dice si hay luz con la que verlo.
     *
     * @return array{mean: float, contrast: float}|null
     */
    private function measure(string $path): ?array
    {
        $image = @imagecreatefromjpeg($path);

        if ($image === false) {
            return null;
        }

        $width = imagesx($image);
        $height = imagesy($image);
        $sum = 0.0;
        $squares = 0.0;
        $count = 0;

        for ($y = 0; $y < $height; $y += self::SAMPLE_STEP) {
            for ($x = 0; $x < $width; $x += self::SAMPLE_STEP) {
                $rgb = imagecolorat($image, $x, $y);
                $luminance = 0.299 * (($rgb >> 16) & 255)
                    + 0.587 * (($rgb >> 8) & 255)
                    + 0.114 * ($rgb & 255);

                $sum += $luminance;
                $squares += $luminance * $luminance;
                $count++;
            }
        }

        imagedestroy($image);

        if ($count === 0) {
            return null;
        }

        $mean = $sum / $count;

        return [
            'mean' => $mean,
            'contrast' => sqrt(max(0.0, $squares / $count - $mean * $mean)),
        ];
    }

    /**
     * Copia la imagen al directorio de la historia si aún no está. Idempotente: se llama cada
     * vez que se abre la pantalla y solo escribe la primera.
     */
    private function preserve(string $slug, int $order, string $source): bool
    {
        $directory = $this->storiesDirectory.DIRECTORY_SEPARATOR.$slug.DIRECTORY_SEPARATOR.'miniaturas';
        $target = $directory.DIRECTORY_SEPARATOR.'plano-'.sprintf('%03d', $order).'.jpg';

        if ($this->files->isFile($target)) {
            return true;
        }

        $this->files->ensureDirectoryExists($directory);

        return $this->files->copy($source, $target);
    }

    /**
     * Ruta de la copia conservada de un plano, o null si no se guardó.
     */
    public function preservedPath(string $slug, int $order): ?string
    {
        $path = $this->storiesDirectory.DIRECTORY_SEPARATOR.$slug
            .DIRECTORY_SEPARATOR.'miniaturas'
            .DIRECTORY_SEPARATOR.'plano-'.sprintf('%03d', $order).'.jpg';

        return $this->files->isFile($path) ? $path : null;
    }
}
