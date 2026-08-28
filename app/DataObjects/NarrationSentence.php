<?php

declare(strict_types=1);

namespace App\DataObjects;

final readonly class NarrationSentence
{
    /**
     * @param  string  $text  Frase del guion, tal cual se publica en timings.json y en los subtítulos.
     * @param  string  $ttsText  La misma frase con la fonética aplicada, que es lo que se sintetiza.
     */
    public function __construct(
        public int $order,
        public int $sceneOrder,
        public string $text,
        public float $pauseAfter,
        public string $ttsText = '',
    ) {}

    /**
     * Texto que se manda al TTS y contra el que se alinea la transcripción.
     */
    public function forTts(): string
    {
        return $this->ttsText !== '' ? $this->ttsText : $this->text;
    }
}
