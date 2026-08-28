<?php

declare(strict_types=1);

namespace App\Services\Audio;

use App\DataObjects\DirectedSfx;
use App\DataObjects\NarrationWord;
use App\DataObjects\Shot;

/**
 * Único sitio donde se decide dónde suena un golpe. Lo consultan el director (para no dirigir un
 * efecto cuya palabra no está en la narración del plano), el colocador (para colgarlo del instante
 * de esa palabra) y el validador (para contar cuántos efectos tienen ancla de verdad).
 *
 * La palabra manda porque en un vídeo de imágenes fijas no hay evento visual que sincronizar: el
 * reloj del espectador es la voz, y el golpe se oye a tiempo cuando cae sobre la palabra que lo
 * nombra.
 */
final class SfxAnchor
{
    /** Holgura en los bordes del plano, solo para absorber el redondeo a milisegundos. */
    private const EDGE_TOLERANCE = 0.05;

    /**
     * Prefijo común que basta para dar dos tokens por el mismo cuando no hay coincidencia exacta:
     * whisper escribe «creaking» donde el guion dice «creaked». Es más estricto que el de la
     * alineación porque aquí equivocarse no pierde un ancla, coloca el sonido en otra palabra.
     */
    private const FUZZY_PREFIX_LENGTH = 5;

    /**
     * ¿La palabra ancla está en esta narración? Se compara por tokens normalizados porque el modelo
     * devuelve la palabra con la puntuación pegada más veces de las que reconoce.
     */
    public function mentions(string $narration, string $anchorWord): bool
    {
        $anchor = $this->tokens($anchorWord);

        if ($anchor === []) {
            return false;
        }

        $tokens = $this->tokens($narration);

        foreach ($anchor as $token) {
            if (! in_array($token, $tokens, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Instante en el que se quiere oír el golpe, o null si su palabra no aparece en la ventana del
     * plano. Devolver null es una respuesta legítima: sin ancla el efecto no se coloca.
     *
     * @param  list<NarrationWord>  $words
     */
    public function resolve(Shot $shot, DirectedSfx $effect, array $words): ?float
    {
        $anchor = $this->tokens($effect->anchorWord);

        if ($anchor === []) {
            return null;
        }

        $window = $this->window($shot, $words);
        $hits = $this->hits($window, $anchor, fuzzy: false);

        if ($hits === []) {
            $hits = $this->hits($window, $anchor, fuzzy: true);
        }

        if ($hits === []) {
            return null;
        }

        // Cuando la palabra se repite dentro del plano, el offsetRatio elige cuál de las dos. Es lo
        // único que decide ya: para desempatar entre candidatos reales una estimación vale.
        $estimate = $shot->start + $effect->offsetRatio * max(0.0, $shot->end - $shot->start);

        usort(
            $hits,
            static fn (float $left, float $right): int => abs($left - $estimate) <=> abs($right - $estimate),
        );

        return round($hits[0], 3);
    }

    /**
     * @param  list<NarrationWord>  $words
     * @return list<NarrationWord>
     */
    private function window(Shot $shot, array $words): array
    {
        $window = [];

        foreach ($words as $word) {
            if ($word->start < $shot->start - self::EDGE_TOLERANCE) {
                continue;
            }

            if ($word->start > $shot->end + self::EDGE_TOLERANCE) {
                continue;
            }

            $window[] = $word;
        }

        return $window;
    }

    /**
     * Inicios de cada aparición de la secuencia de tokens dentro de la ventana.
     *
     * @param  list<NarrationWord>  $window
     * @param  list<string>  $anchor
     * @return list<float>
     */
    private function hits(array $window, array $anchor, bool $fuzzy): array
    {
        $hits = [];
        $count = count($window);
        $needed = count($anchor);

        for ($index = 0; $index + $needed <= $count; $index++) {
            $matched = true;

            for ($position = 0; $position < $needed; $position++) {
                if (! $this->same($window[$index + $position]->token, $anchor[$position], $fuzzy)) {
                    $matched = false;
                    break;
                }
            }

            if ($matched) {
                $hits[] = $window[$index]->start;
            }
        }

        return $hits;
    }

    private function same(string $token, string $expected, bool $fuzzy): bool
    {
        if ($token === $expected) {
            return true;
        }

        if (! $fuzzy) {
            return false;
        }

        if (mb_strlen($token) < self::FUZZY_PREFIX_LENGTH || mb_strlen($expected) < self::FUZZY_PREFIX_LENGTH) {
            return false;
        }

        return mb_substr($token, 0, self::FUZZY_PREFIX_LENGTH) === mb_substr($expected, 0, self::FUZZY_PREFIX_LENGTH);
    }

    /**
     * @return list<string>
     */
    private function tokens(string $text): array
    {
        $parts = preg_split("/[^\p{L}\p{N}']+/u", mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY);

        return is_array($parts) ? array_values($parts) : [];
    }
}
