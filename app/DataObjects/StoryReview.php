<?php

declare(strict_types=1);

namespace App\DataObjects;

final readonly class StoryReview
{
    /**
     * @param  list<array{text: string, issue: string, suggestion: string}>  $nonNativePhrases
     * @param  list<string>  $clichedElements
     * @param  list<array{sceneOrder: int, reason: string}>  $tensionDips
     * @param  list<string>  $ttsRisks
     */
    public function __construct(
        public array $nonNativePhrases,
        public array $clichedElements,
        public array $tensionDips,
        public array $ttsRisks,
        public int $score,
        public string $verdict,
    ) {}

    /**
     * @param  array{nonNativePhrases?: list<array{text: string, issue: string, suggestion: string}>, clichedElements?: list<string>, tensionDips?: list<array{sceneOrder: int, reason: string}>, ttsRisks?: list<string>, score: int, verdict: string}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            nonNativePhrases: $data['nonNativePhrases'] ?? [],
            clichedElements: $data['clichedElements'] ?? [],
            tensionDips: $data['tensionDips'] ?? [],
            ttsRisks: $data['ttsRisks'] ?? [],
            score: (int) $data['score'],
            verdict: $data['verdict'],
        );
    }

    /**
     * @return array{nonNativePhrases: list<array{text: string, issue: string, suggestion: string}>, clichedElements: list<string>, tensionDips: list<array{sceneOrder: int, reason: string}>, ttsRisks: list<string>, score: int, verdict: string}
     */
    public function toArray(): array
    {
        return [
            'nonNativePhrases' => $this->nonNativePhrases,
            'clichedElements' => $this->clichedElements,
            'tensionDips' => $this->tensionDips,
            'ttsRisks' => $this->ttsRisks,
            'score' => $this->score,
            'verdict' => $this->verdict,
        ];
    }
}
