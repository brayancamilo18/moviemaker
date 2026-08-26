<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Video\SubtitleGenerator;
use Illuminate\Filesystem\Filesystem;
use Tests\TestCase;

final class SubtitleGeneratorTest extends TestCase
{
    private string $workDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workDir = storage_path('app/testing/subtitles-'.bin2hex(random_bytes(4)));
        $this->app->make(Filesystem::class)->ensureDirectoryExists($this->workDir);
    }

    protected function tearDown(): void
    {
        $this->app->make(Filesystem::class)->deleteDirectory($this->workDir);

        parent::tearDown();
    }

    public function test_ninety_character_sentence_splits_into_two_cues_with_proportional_time(): void
    {
        $text = 'The road went on, and the fog thickened, and the whistle came closer than before.';
        $text .= str_repeat('x', 90 - mb_strlen($text));
        $this->assertSame(90, mb_strlen($text));

        $cues = $this->cues([
            ['order' => 1, 'text' => $text, 'start' => 0.0, 'end' => 10.0],
        ]);

        $this->assertCount(2, $cues);

        $weight0 = mb_strlen($this->plain($cues[0]['text']));
        $weight1 = mb_strlen($this->plain($cues[1]['text']));
        $split = 10.0 * ($weight0 / ($weight0 + $weight1));

        $this->assertEqualsWithDelta($split, $cues[0]['end'] + 0.04, 0.25);
        $this->assertEqualsWithDelta($split, $cues[1]['start'] - 0.04, 0.25);
        $this->assertGreaterThanOrEqual(0.079, $cues[1]['start'] - $cues[0]['end']);
    }

    public function test_short_sentence_extends_to_minimum_without_overlapping_the_next(): void
    {
        $cues = $this->cues([
            ['order' => 1, 'text' => 'Hi there.', 'start' => 0.0, 'end' => 0.4],
            ['order' => 2, 'text' => 'The rest of the hall stayed quiet.', 'start' => 2.0, 'end' => 5.0],
        ]);

        $this->assertEqualsWithDelta(0.0, $cues[0]['start'], 0.001);
        $this->assertEqualsWithDelta(1.2, $cues[0]['end'], 0.001);
        $this->assertGreaterThanOrEqual(1.28, $cues[1]['start'] - 0.001);
        $this->assertGreaterThanOrEqual(0.079, $cues[1]['start'] - $cues[0]['end']);
    }

    public function test_no_cue_exceeds_two_lines_or_forty_two_characters(): void
    {
        $cues = $this->cues([
            [
                'order' => 1,
                'text' => 'The road went on, and the fog thickened, and the whistle came closer than before.'.str_repeat('x', 9),
                'start' => 0.0,
                'end' => 10.0,
            ],
            [
                'order' => 2,
                'text' => 'The door closed behind me in the empty hall while the boards kept creaking under each step.',
                'start' => 10.5,
                'end' => 16.0,
            ],
        ]);

        $this->assertNotEmpty($cues);

        foreach ($cues as $cue) {
            $lines = preg_split("/\n/", $cue['text']) ?: [];
            $this->assertLessThanOrEqual(2, count($lines), $cue['text']);

            foreach ($lines as $line) {
                $this->assertLessThanOrEqual(42, mb_strlen($line), $line);
            }
        }
    }

    public function test_subtitles_use_original_narration_not_phonetics(): void
    {
        $cues = $this->cues([
            [
                'order' => 1,
                'text' => 'Sacamantecas waited by the spring of Ucieda.',
                'ttsText' => 'sah-kah-mahn-TEH-kahs waited by the spring of oo-SYEH-dah.',
                'start' => 0.0,
                'end' => 4.0,
            ],
        ]);

        $body = $this->plain($cues[0]['text']);

        $this->assertStringContainsString('Sacamantecas', $body);
        $this->assertStringContainsString('Ucieda', $body);
        $this->assertStringNotContainsString('sah-kah-mahn-TEH-kahs', $body);
        $this->assertStringNotContainsString('oo-SYEH-dah', $body);
    }

    /**
     * @param  list<array<string, mixed>>  $sentences
     * @return list<array{start: float, end: float, text: string}>
     */
    private function cues(array $sentences): array
    {
        $path = $this->workDir.DIRECTORY_SEPARATOR.'subtitles.srt';
        $this->app->make(SubtitleGenerator::class)->generate(['sentences' => $sentences], $path);

        return $this->parseSrt((string) file_get_contents($path));
    }

    /**
     * @return list<array{start: float, end: float, text: string}>
     */
    private function parseSrt(string $srt): array
    {
        $cues = [];
        $blocks = preg_split("/\n\n+/", trim($srt)) ?: [];

        foreach ($blocks as $block) {
            if (! preg_match('/(\d{2}:\d{2}:\d{2},\d{3}) --> (\d{2}:\d{2}:\d{2},\d{3})\n([\s\S]+)/', $block, $matches)) {
                continue;
            }

            $cues[] = [
                'start' => $this->timestamp($matches[1]),
                'end' => $this->timestamp($matches[2]),
                'text' => trim($matches[3]),
            ];
        }

        return $cues;
    }

    private function timestamp(string $value): float
    {
        sscanf($value, '%d:%d:%d,%d', $hours, $minutes, $seconds, $millis);

        return ((int) $hours * 3600) + ((int) $minutes * 60) + (int) $seconds + ((int) $millis / 1000);
    }

    private function plain(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }
}
