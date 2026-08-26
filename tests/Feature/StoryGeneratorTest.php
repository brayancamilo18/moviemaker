<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\DataObjects\Story;
use App\Exceptions\InvalidStoryException;
use App\Exceptions\LlmGenerationException;
use App\Services\Story\StoryGenerator;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

final class StoryGeneratorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
        Http::preventStrayRequests();
    }

    public function test_valid_response_returns_a_story_with_ordered_scenes(): void
    {
        $fixture = $this->fixture();
        $this->fakeGemini($this->geminiEnvelope($fixture));

        $story = $this->generator()->generate('una casa que deja de respirar');

        $this->assertInstanceOf(Story::class, $story);
        $this->assertSame($fixture['title'], $story->title);
        $this->assertCount(8, $story->scenes);
        $this->assertSame(
            [1, 2, 3, 4, 5, 6, 7, 8],
            array_map(static fn ($scene): int => $scene->order, $story->scenes),
        );
        $this->assertSame($fixture['scenes'][0]['narration'], $story->scenes[0]->narration);
        $this->assertSame($fixture['scenes'][2]['visualSummary'], $story->scenes[2]->visualSummary);
    }

    public function test_unordered_scenes_are_sorted_by_order(): void
    {
        $fixture = $this->fixture();
        $firstNarration = $fixture['scenes'][0]['narration'];
        $lastNarration = $fixture['scenes'][7]['narration'];
        $fixture['scenes'] = array_reverse($fixture['scenes']);

        $this->fakeGemini($this->geminiEnvelope($fixture));

        $story = $this->generator()->generate();

        $this->assertSame(
            [1, 2, 3, 4, 5, 6, 7, 8],
            array_map(static fn ($scene): int => $scene->order, $story->scenes),
        );
        $this->assertSame($firstNarration, $story->scenes[0]->narration);
        $this->assertSame($lastNarration, $story->scenes[7]->narration);
    }

    public function test_gapped_scene_orders_are_renumbered_instead_of_failing(): void
    {
        $fixture = $this->fixture();
        $firstNarration = $fixture['scenes'][0]['narration'];

        foreach (array_keys($fixture['scenes']) as $index) {
            $fixture['scenes'][$index]['order'] = ($index + 1) * 10;
        }

        $this->fakeGemini($this->geminiEnvelope($fixture));

        $story = $this->generator()->generate();

        $this->assertSame(
            [1, 2, 3, 4, 5, 6, 7, 8],
            array_map(static fn ($scene): int => $scene->order, $story->scenes),
        );
        $this->assertSame($firstNarration, $story->scenes[0]->narration);
    }

    public function test_too_few_scenes_throws_invalid_story_exception(): void
    {
        $fixture = $this->fixture();
        $fixture['scenes'] = array_slice($fixture['scenes'], 0, 2);
        $this->fakeGemini($this->geminiEnvelope($fixture));

        $this->expectException(InvalidStoryException::class);
        $this->expectExceptionMessage('escenas');

        $this->generator()->generate();
    }

    public function test_empty_narration_throws_invalid_story_exception(): void
    {
        $fixture = $this->fixture();
        $fixture['scenes'][0]['narration'] = '';
        $this->fakeGemini($this->geminiEnvelope($fixture));

        $this->expectException(InvalidStoryException::class);
        $this->expectExceptionMessage('narración vacía');

        $this->generator()->generate();
    }

    public function test_short_narration_throws_invalid_story_exception(): void
    {
        $fixture = $this->fixture();
        $fixture['scenes'][0]['narration'] = implode(' ', array_fill(0, 12, 'word'));
        $this->fakeGemini($this->geminiEnvelope($fixture));

        $this->expectException(InvalidStoryException::class);
        $this->expectExceptionMessage('La escena 1 tiene 12 palabras; el mínimo es 30.');

        $this->generator()->generate();
    }

    public function test_word_count_outside_forty_percent_throws_invalid_story_exception(): void
    {
        $fixture = $this->fixture();
        $narration = implode(' ', array_fill(0, 40, 'word'));

        foreach (array_keys($fixture['scenes']) as $index) {
            $fixture['scenes'][$index]['narration'] = $narration;
        }

        $this->fakeGemini($this->geminiEnvelope($fixture));

        $this->expectException(InvalidStoryException::class);
        $this->expectExceptionMessage('El guion tiene 320 palabras; el objetivo es 1600 (±40%: mínimo 960, máximo 2240).');

        $this->generator()->generate();
    }

    public function test_title_over_seventy_characters_throws_invalid_story_exception(): void
    {
        $fixture = $this->fixture();
        $fixture['title'] = str_repeat('a', 71);
        $this->fakeGemini($this->geminiEnvelope($fixture));

        $this->expectException(InvalidStoryException::class);
        $this->expectExceptionMessage('El título tiene 71 caracteres; el máximo es 70.');

        $this->generator()->generate();
    }

    public function test_retries_after_429_and_succeeds_on_200(): void
    {
        $fixture = $this->fixture();

        Http::fake([
            '*' => Http::sequence()
                ->push(['error' => ['message' => 'Too Many Requests']], 429)
                ->push($this->geminiEnvelope($fixture), 200),
        ]);

        $story = $this->generator()->generate();

        $this->assertSame($fixture['title'], $story->title);
        Http::assertSentCount(2);
    }

    public function test_safety_finish_reason_throws_llm_generation_exception(): void
    {
        $this->fakeGemini($this->geminiEnvelope($this->fixture(), 'SAFETY'));

        $this->expectException(LlmGenerationException::class);
        $this->expectExceptionMessage('SAFETY');

        $this->generator()->generate();
    }

    private function generator(): StoryGenerator
    {
        return $this->app->make(StoryGenerator::class);
    }

    /**
     * @return array<string, mixed>
     */
    private function fixture(): array
    {
        $json = file_get_contents(base_path('tests/Fixtures/story-response.json'));

        $this->assertNotFalse($json);

        /** @var array<string, mixed> $data */
        $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        return $data;
    }

    /**
     * @param  array<string, mixed>  $story
     * @return array<string, mixed>
     */
    private function geminiEnvelope(array $story, string $finishReason = 'STOP'): array
    {
        return [
            'candidates' => [
                [
                    'finishReason' => $finishReason,
                    'content' => [
                        'parts' => [
                            ['text' => json_encode($story, JSON_UNESCAPED_UNICODE)],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $envelope
     */
    private function fakeGemini(array $envelope): void
    {
        Http::fake([
            '*' => Http::response($envelope, 200),
        ]);
    }
}
