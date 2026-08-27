<?php

declare(strict_types=1);

namespace App\Services\Video;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use InvalidArgumentException;

/**
 * Orquesta el SRT: lee las frases de timings.json, las segmenta, las reparte en el tiempo y las
 * escribe. El texto es siempre el del guion, nunca la fonética del TTS ni lo que transcribió
 * whisper.
 */
final class SubtitleGenerator
{
    private readonly float $minDuration;

    public function __construct(
        private CueSegmenter $segmenter,
        private CueTimer $timer,
        private SrtWriter $writer,
        private Filesystem $files,
        Repository $config,
    ) {
        $this->minDuration = (float) $config->get('stories.subtitles.min_duration', 1.2);
    }

    /**
     * @param  array{sentences?: list<array<string, mixed>>}|list<array<string, mixed>>  $timings
     */
    public function generate(array $timings, string $outputPath): string
    {
        $sentences = $this->sentences($timings);

        if ($sentences === []) {
            throw new InvalidArgumentException('timings.json no tiene frases para subtitular.');
        }

        $cues = [];

        foreach ($sentences as $sentence) {
            $parts = $this->segmenter->segment($sentence['text']);

            foreach ($this->timer->allocate($parts, $sentence['start'], $sentence['end'], $sentence['sentence']) as $cue) {
                $cues[] = $cue;
            }
        }

        $srt = $this->writer->render($this->timer->applyRules($cues));

        $this->files->ensureDirectoryExists(dirname($outputPath));
        $this->files->put($outputPath, $srt);

        if (! $this->files->isFile($outputPath) || $this->files->size($outputPath) < 1) {
            throw new InvalidArgumentException('No se pudo escribir el SRT.');
        }

        return $outputPath;
    }

    /**
     * @param  array{sentences?: list<array<string, mixed>>}|list<array<string, mixed>>  $timings
     * @return list<array{text: string, start: float, end: float, sentence: int}>
     */
    private function sentences(array $timings): array
    {
        $raw = $timings['sentences'] ?? $timings;

        if (! is_array($raw)) {
            return [];
        }

        $sentences = [];

        foreach ($raw as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            $text = $this->originalText($row);

            if ($text === '') {
                continue;
            }

            $start = round((float) ($row['start'] ?? 0), 3);
            $end = round((float) ($row['end'] ?? $start), 3);

            // Una frase sin duración medible se queda con el mínimo legible. El parche puede
            // empujar su final por delante del inicio de la siguiente: el solape lo resuelve
            // CueTimer, no se maquilla aquí.
            if ($end <= $start) {
                $end = round($start + $this->minDuration, 3);
            }

            $sentences[] = [
                'text' => $text,
                'start' => $start,
                'end' => $end,
                'sentence' => (int) ($row['order'] ?? $index + 1),
            ];
        }

        return $sentences;
    }

    /**
     * El SRT usa el texto del guion, no Whisper ni la fonética del TTS.
     *
     * @param  array<string, mixed>  $sentence
     */
    private function originalText(array $sentence): string
    {
        $text = trim((string) ($sentence['text'] ?? $sentence['original'] ?? ''));
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }
}
