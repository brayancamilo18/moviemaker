<?php

declare(strict_types=1);

namespace App\Contracts;

interface TextToSpeech
{
    /**
     * @param  array<string, mixed>  $options
     */
    public function synthesize(string $text, array $options = []): string;

    /**
     * @param  array<string, mixed>  $options
     */
    public function isCached(string $text, array $options = []): bool;

    /**
     * Lo que ocupa en disco el audio cacheado de una frase, 0 si no está. Existe para
     * que una depuración en seco pueda decir cuánto liberaría sin tener que borrarlo
     * primero para averiguarlo.
     *
     * @param  array<string, mixed>  $options
     */
    public function cachedBytes(string $text, array $options = []): int;

    /**
     * Suelta el audio cacheado de una frase y devuelve los bytes liberados. Una frase
     * ya narrada solo se reutiliza dentro de su propia historia —salvo la careta y el
     * cierre, que son texto fijo—, así que al terminar el MP4 deja de valer lo que ocupa.
     *
     * @param  array<string, mixed>  $options
     */
    public function forget(string $text, array $options = []): int;

    public function isAvailable(): bool;
}
