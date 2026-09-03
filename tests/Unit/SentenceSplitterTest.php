<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\DataObjects\NarrationSentence;
use App\DataObjects\Story;
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
        $this->assertSame($this->pause('sentence'), $sentences[0]->pauseAfter);
        $this->assertSame($this->pause('sentence'), $sentences[1]->pauseAfter);
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
        $this->assertSame($this->pause('ellipsis'), $sentences[0]->pauseAfter);
        $this->assertSame($this->pause('sentence'), $sentences[1]->pauseAfter);
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
        $this->assertSame($this->pause('question_or_exclamation'), $sentences[0]->pauseAfter);
        $this->assertSame($this->pause('sentence'), $sentences[1]->pauseAfter);
    }

    public function test_single_sentence_without_terminal_punctuation(): void
    {
        $sentences = $this->splitter()->split('The well was open');

        $this->assertCount(1, $sentences);
        $this->assertSame('The well was open', $sentences[0]->text);
        $this->assertSame(1, $sentences[0]->order);
        $this->assertSame(1, $sentences[0]->sceneOrder);
        $this->assertSame($this->pause('sentence'), $sentences[0]->pauseAfter);
    }

    public function test_empty_string_returns_no_sentences(): void
    {
        $this->assertSame([], $this->splitter()->split(''));
        $this->assertSame([], $this->splitter()->split('   '));
    }

    public function test_each_sentence_carries_its_own_phonetics_next_to_the_script_text(): void
    {
        $story = Story::fromArray([
            'title' => 'Splitter fixture',
            'hook' => 'The spring went bad.',
            'description' => 'A fixture for the phonetic pairing.',
            'tags' => ['test'],
            'thumbnailPrompt' => 'A dry spring',
            'scenes' => [
                ['order' => 1, 'narration' => 'The Culebrón was not destroyed. It waited.', 'imagePrompt' => 'x', 'visualSummary' => 'x'],
                ['order' => 2, 'narration' => 'Doña Herminia knew.', 'imagePrompt' => 'x', 'visualSummary' => 'x'],
            ],
            'pronunciations' => [
                ['term' => 'Culebrón', 'phonetic' => 'koo-leh-BROHN'],
                ['term' => 'Doña Herminia', 'phonetic' => 'DOH-nyah er-MEE-nee-ah'],
            ],
        ]);

        $sentences = $this->splitter()->splitScenes(
            $story->scenesForNarration(),
            static fn (string $sentence): string => $story->textForTts($sentence),
        );

        $this->assertSame(
            [
                'The Culebrón was not destroyed.',
                'It waited.',
                'Doña Herminia knew.',
            ],
            $this->texts($sentences),
        );
        $this->assertSame('The koo-leh-BROHN was not destroyed.', $sentences[0]->forTts());
        $this->assertSame('It waited.', $sentences[1]->forTts());
        $this->assertSame('DOH-nyah er-MEE-nee-ah knew.', $sentences[2]->forTts());
        $this->assertSame([1, 1, 2], array_map(
            static fn (NarrationSentence $sentence): int => $sentence->sceneOrder,
            $sentences,
        ));
        // La pausa entre escenas se decide sobre el texto del guion, no sobre la fonética.
        $this->assertSame($this->pause('between_scenes'), $sentences[1]->pauseAfter);
    }

    public function test_a_sentence_without_phonetics_speaks_the_script_text(): void
    {
        $sentences = $this->splitter()->split('The well was open.');

        $this->assertSame('The well was open.', $sentences[0]->text);
        $this->assertSame('The well was open.', $sentences[0]->forTts());
    }

    public function test_an_enabled_outro_appends_the_configured_scene(): void
    {
        config(['stories.story.outro.enabled' => true]);

        $story = $this->twoSceneStory();
        $base = $story->scenesForNarration();
        $scenes = $story->scenesForNarrationWithOutro(
            trim((string) config('stories.story.outro.text')),
            (int) config('stories.story.outro.scene_order'),
        );

        $this->assertCount(count($base) + 1, $scenes);
        $outro = $scenes[array_key_last($scenes)];
        $this->assertSame((int) config('stories.story.outro.scene_order'), $outro['order']);
        $this->assertSame(trim((string) config('stories.story.outro.text')), $outro['text']);
    }

    public function test_a_disabled_outro_leaves_the_scene_count_unchanged(): void
    {
        $this->assertFalse((bool) config('stories.story.outro.enabled'));

        $story = $this->twoSceneStory();
        $scenes = $story->scenesForNarration();

        $this->assertCount(count($story->scenes), $scenes);
        $this->assertSame([1, 2], array_column($scenes, 'order'));
        $this->assertSame([], array_values(array_filter(
            $this->splitter()->splitScenes($scenes),
            static fn (NarrationSentence $sentence): bool => $sentence->isOutro,
        )));
    }

    public function test_only_outro_sentences_are_flagged_as_outro(): void
    {
        config(['stories.story.outro.enabled' => true]);
        $splitter = $this->splitter();
        $outroOrder = (int) config('stories.story.outro.scene_order');
        $sentences = $splitter->splitScenes($this->narrationScenes($this->twoSceneStory()));

        $this->assertNotEmpty(array_filter(
            $sentences,
            static fn (NarrationSentence $sentence): bool => $sentence->isOutro,
        ));

        foreach ($sentences as $sentence) {
            $this->assertSame($sentence->sceneOrder === $outroOrder, $sentence->isOutro);
        }
    }

    public function test_the_pause_before_the_outro_is_the_lead_pause_not_the_scene_gap(): void
    {
        config(['stories.story.outro.enabled' => true]);
        $splitter = $this->splitter();
        $lead = (float) config('stories.story.outro.lead_pause');
        $betweenScenes = (float) config('stories.tts.pauses.between_scenes');
        $outroOrder = (int) config('stories.story.outro.scene_order');
        $sentences = $splitter->splitScenes($this->narrationScenes($this->twoSceneStory()));

        // Lo que la prueba necesita es que las dos pausas no coincidan; si coincidieran, el assert
        // del final pasaría sin distinguir la de salida al outro del corte normal entre escenas.
        $this->assertNotSame($lead, $betweenScenes);

        $beforeOutro = null;

        foreach ($sentences as $sentence) {
            if ($sentence->sceneOrder === $outroOrder) {
                break;
            }

            $beforeOutro = $sentence;
        }

        $this->assertInstanceOf(NarrationSentence::class, $beforeOutro);
        $this->assertFalse($beforeOutro->isOutro);
        $this->assertSame($lead, $beforeOutro->pauseAfter);
        $this->assertNotEquals($betweenScenes, $beforeOutro->pauseAfter);
    }

    public function test_the_opening_narrates_the_cold_open_then_the_careta_then_the_story(): void
    {
        $sentences = $this->splitter()->splitScenes($this->openingScenes($this->openingStory()));
        $orders = [];

        foreach ($sentences as $sentence) {
            if ($orders === [] || end($orders) !== $sentence->sceneOrder) {
                $orders[] = $sentence->sceneOrder;
            }
        }

        $this->assertSame(
            [
                (int) config('stories.story.cold_open.scene_order'),
                (int) config('stories.story.intro.scene_order'),
                1,
                2,
                (int) config('stories.story.outro.scene_order'),
            ],
            $orders,
        );
    }

    public function test_the_careta_is_the_fixed_channel_text_plus_the_hook_line_of_this_story(): void
    {
        $story = $this->openingStory();
        $introOrder = (int) config('stories.story.intro.scene_order');
        $scenes = $this->openingScenes($story);
        $intro = null;

        foreach ($scenes as $scene) {
            if ($scene['order'] === $introOrder) {
                $intro = $scene;
            }
        }

        $this->assertNotNull($intro);
        $this->assertStringStartsWith(trim((string) config('stories.story.intro.text')), $intro['text']);
        $this->assertStringEndsWith($story->hookLine, $intro['text']);
    }

    public function test_only_careta_sentences_are_flagged_as_intro(): void
    {
        $introOrder = (int) config('stories.story.intro.scene_order');
        $sentences = $this->splitter()->splitScenes($this->openingScenes($this->openingStory()));

        $this->assertNotEmpty(array_filter(
            $sentences,
            static fn (NarrationSentence $sentence): bool => $sentence->isIntro,
        ));

        foreach ($sentences as $sentence) {
            $this->assertSame($sentence->sceneOrder === $introOrder, $sentence->isIntro);
            $this->assertFalse($sentence->isIntro && $sentence->isOutro);
        }
    }

    public function test_each_opening_block_ends_on_its_trail_pause_not_on_the_scene_gap(): void
    {
        $coldOpenPause = (float) config('stories.story.cold_open.trail_pause');
        $introPause = (float) config('stories.story.intro.trail_pause');
        $betweenScenes = (float) config('stories.tts.pauses.between_scenes');
        $sentences = $this->splitter()->splitScenes($this->openingScenes($this->openingStory()));

        $this->assertNotSame($coldOpenPause, $betweenScenes);
        $this->assertNotSame($introPause, $betweenScenes);
        $this->assertSame(
            $coldOpenPause,
            $this->lastOfScene($sentences, (int) config('stories.story.cold_open.scene_order'))->pauseAfter,
        );
        $this->assertSame(
            $introPause,
            $this->lastOfScene($sentences, (int) config('stories.story.intro.scene_order'))->pauseAfter,
        );
    }

    /**
     * @param  list<NarrationSentence>  $sentences
     */
    private function lastOfScene(array $sentences, int $sceneOrder): NarrationSentence
    {
        $found = null;

        foreach ($sentences as $sentence) {
            if ($sentence->sceneOrder === $sceneOrder) {
                $found = $sentence;
            }
        }

        $this->assertInstanceOf(NarrationSentence::class, $found);

        return $found;
    }

    /**
     * @return list<array{order: int, text: string}>
     */
    private function openingScenes(Story $story): array
    {
        return $story->scenesForNarrationWithBookends(
            (int) config('stories.story.cold_open.scene_order'),
            trim((string) config('stories.story.intro.text')),
            (int) config('stories.story.intro.scene_order'),
            trim((string) config('stories.story.outro.text')),
            (int) config('stories.story.outro.scene_order'),
        );
    }

    private function openingStory(): Story
    {
        return Story::fromArray([
            'title' => 'Opening splitter fixture',
            'hook' => 'The spring went bad.',
            'description' => 'A fixture for the spoken channel opening.',
            'tags' => ['test'],
            'thumbnailPrompt' => 'A dry spring',
            'coldOpen' => [
                'narration' => 'It walked past the door. I did not move.',
                'visualSummary' => 'A closed door seen from a dark room at night',
            ],
            'hookLine' => 'How long would you have stayed in that room?',
            'scenes' => [
                ['order' => 1, 'narration' => 'The door closed. I kept walking.', 'imagePrompt' => 'x', 'visualSummary' => 'x'],
                ['order' => 2, 'narration' => 'Then the whistle came closer.', 'imagePrompt' => 'x', 'visualSummary' => 'x'],
            ],
            'pronunciations' => [],
        ]);
    }

    private function splitter(): SentenceSplitter
    {
        return $this->app->make(SentenceSplitter::class);
    }

    /**
     * Las pausas se leen de config, no se repiten a mano: el propio config avisa de que hay que
     * ajustarlas a menudo, y estas pruebas comprueban qué pausa se aplica a cada frase, no cuánto
     * mide cada una.
     */
    private function pause(string $kind): float
    {
        return (float) config('stories.tts.pauses.'.$kind);
    }

    private function twoSceneStory(): Story
    {
        return Story::fromArray([
            'title' => 'Outro splitter fixture',
            'hook' => 'The spring went bad.',
            'description' => 'A fixture for the spoken channel outro.',
            'tags' => ['test'],
            'thumbnailPrompt' => 'A dry spring',
            'scenes' => [
                ['order' => 1, 'narration' => 'The door closed. I kept walking.', 'imagePrompt' => 'x', 'visualSummary' => 'x'],
                ['order' => 2, 'narration' => 'Then the whistle came closer.', 'imagePrompt' => 'x', 'visualSummary' => 'x'],
            ],
            'pronunciations' => [],
        ]);
    }

    /**
     * @return list<array{order: int, text: string}>
     */
    private function narrationScenes(Story $story): array
    {
        return $story->scenesForNarrationWithOutro(
            trim((string) config('stories.story.outro.text')),
            (int) config('stories.story.outro.scene_order'),
        );
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
