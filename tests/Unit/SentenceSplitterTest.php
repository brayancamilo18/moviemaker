<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\DataObjects\NarrationSentence;
use App\Services\Audio\SentenceSplitter;
use Tests\TestCase;

final class SentenceSplitterTest extends TestCase
{
    public function test_it_does_not_split_on_common_abbreviations(): void
    {
        $sentences = $this->splitter()->split(
            'Dr. Ruiz met Mr. Hale and Mrs. Vela vs. St. John Jr. later. Then it started.',
        );

        $this->assertSame(
            [
                'Dr. Ruiz met Mr. Hale and Mrs. Vela vs. St. John Jr. later.',
                'Then it started.',
            ],
            $this->texts($sentences),
        );
        $this->assertSame(0.45, $sentences[0]->pauseAfter);
        $this->assertSame(0.45, $sentences[1]->pauseAfter);
    }

    public function test_it_does_not_split_on_initials(): void
    {
        $sentences = $this->splitter()->split(
            'J. R. R. Tolkien never wrote this. The plains were empty.',
        );

        $this->assertSame(
            [
                'J. R. R. Tolkien never wrote this.',
                'The plains were empty.',
            ],
            $this->texts($sentences),
        );
    }

    public function test_ellipsis_stays_inside_the_sentence_and_raises_the_pause(): void
    {
        $sentences = $this->splitter()->split(
            'She waited... Then the whistle answered. The night went still.',
        );

        $this->assertSame(
            [
                'She waited... Then the whistle answered.',
                'The night went still.',
            ],
            $this->texts($sentences),
        );
        $this->assertSame(1.1, $sentences[0]->pauseAfter);
        $this->assertSame(0.45, $sentences[1]->pauseAfter);
    }

    public function test_quoted_dialogue_keeps_inner_punctuation(): void
    {
        $sentences = $this->splitter()->split(
            'He said, "Stay. Do not move!" Then the lamp died.',
        );

        $this->assertSame(
            [
                'He said, "Stay. Do not move!"',
                'Then the lamp died.',
            ],
            $this->texts($sentences),
        );
        $this->assertSame(0.7, $sentences[0]->pauseAfter);
        $this->assertSame(0.45, $sentences[1]->pauseAfter);
    }

    public function test_single_sentence_without_terminal_punctuation(): void
    {
        $sentences = $this->splitter()->split('The well was open');

        $this->assertCount(1, $sentences);
        $this->assertSame('The well was open', $sentences[0]->text);
        $this->assertSame(1, $sentences[0]->order);
        $this->assertSame(1, $sentences[0]->sceneOrder);
        $this->assertSame(0.45, $sentences[0]->pauseAfter);
    }

    public function test_empty_string_returns_no_sentences(): void
    {
        $this->assertSame([], $this->splitter()->split(''));
        $this->assertSame([], $this->splitter()->split('   '));
    }

    private function splitter(): SentenceSplitter
    {
        return $this->app->make(SentenceSplitter::class);
    }

    /**
     * @param  list<NarrationSentence>  $sentences
     * @return list<string>
     */
    private function texts(array $sentences): array
    {
        return array_map(static fn (NarrationSentence $sentence): string => $sentence->text, $sentences);
    }
}
