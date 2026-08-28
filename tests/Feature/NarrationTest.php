<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\TextToSpeech;
use App\DataObjects\NarrationSentence;
use App\Exceptions\TtsUnavailableException;
use App\Services\Audio\TranscriptTimer;
use App\Services\Tts\KokoroTts;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

final class NarrationTest extends TestCase
{
    private string $storiesDirectory;

    private string $cacheDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
        Http::preventStrayRequests();

        $this->storiesDirectory = storage_path('app/testing/stories');
        $this->cacheDirectory = storage_path('app/testing/tts-cache');

        $files = $this->app->make(Filesystem::class);
        $files->deleteDirectory(storage_path('app/testing'));
        $files->ensureDirectoryExists($this->storiesDirectory);
        $files->ensureDirectoryExists($this->cacheDirectory);

        config([
            'stories.output_path' => 'testing/stories',
            'stories.tts.cache_path' => 'testing/tts-cache',
            'stories.tts.base_url' => 'http://127.0.0.1:8020',
            'stories.tts.voice' => 'af_heart',
            'stories.tts.speed' => 1.0,
            'stories.whisper.model' => '',
        ]);

        $this->app->forgetInstance(TextToSpeech::class);
        $this->app->singleton(TextToSpeech::class, function (): TextToSpeech {
            return new KokoroTts(
                http: $this->app->make(Factory::class),
                files: $this->app->make(Filesystem::class),
                baseUrl: 'http://127.0.0.1:8020',
                voice: 'af_heart',
                speed: 1.0,
                timeout: 5,
                cacheDirectory: $this->cacheDirectory,
            );
        });
    }

    protected function tearDown(): void
    {
        $this->app->make(Filesystem::class)->deleteDirectory(storage_path('app/testing'));

        parent::tearDown();
    }

    public function test_three_sentence_story_calls_tts_three_times_and_writes_the_master(): void
    {
        $this->fakeSidecar();
        $storyFile = $this->writeStory();

        $this->artisan('story:narrate', [
            'file' => $storyFile,
            '--skip-timings' => true,
        ])->assertSuccessful();

        $this->assertSame(3, $this->synthesizeCount());
        $this->assertMasterExists('three-sentences');
        $this->assertSame(3, $this->cachedWavCount());

        $payload = $this->readJson($storyFile);
        $this->assertSame(3, $payload['audio']['sentenceCount']);
        $this->assertIsFloat($payload['audio']['durationSeconds']);
        $this->assertGreaterThan(0, $payload['audio']['durationSeconds']);
        $this->assertSame('af_heart', $payload['audio']['voice']);
        $this->assertFileExists($payload['audio']['wav']);
        $this->assertFileExists($payload['audio']['mp3']);
        $this->assertNull($payload['audio']['timings']);
    }

    public function test_second_identical_run_uses_only_the_cache(): void
    {
        $this->fakeSidecar();
        $storyFile = $this->writeStory();

        $this->artisan('story:narrate', [
            'file' => $storyFile,
            '--skip-timings' => true,
        ])->assertSuccessful();

        $this->assertSame(3, $this->synthesizeCount());

        $this->artisan('story:narrate', [
            'file' => $storyFile,
            '--skip-timings' => true,
        ])
            ->expectsOutputToContain('Caché: 3/3')
            ->assertSuccessful();

        $this->assertSame(3, $this->synthesizeCount());
        $this->assertSame(3, $this->cachedWavCount());
    }

    public function test_no_cache_calls_tts_again(): void
    {
        $this->fakeSidecar();
        $storyFile = $this->writeStory();

        $this->artisan('story:narrate', [
            'file' => $storyFile,
            '--skip-timings' => true,
        ])->assertSuccessful();

        $this->artisan('story:narrate', [
            'file' => $storyFile,
            '--skip-timings' => true,
            '--no-cache' => true,
        ])
            ->expectsOutputToContain('Caché: 0/3')
            ->assertSuccessful();

        $this->assertSame(6, $this->synthesizeCount());
        $this->assertSame(3, $this->cachedWavCount());
    }

    public function test_down_sidecar_throws_and_leaves_no_partial_files(): void
    {
        $this->fakeSidecar(available: false);
        $storyFile = $this->writeStory();

        $this->artisan('story:narrate', [
            'file' => $storyFile,
            '--skip-timings' => true,
        ])
            ->expectsOutputToContain('El sidecar de Kokoro no está levantado.')
            ->expectsOutputToContain(KokoroTts::START_COMMAND)
            ->assertFailed();

        $this->assertMasterMissing('three-sentences');
        $this->assertSame(0, $this->cachedWavCount());
        $this->assertArrayNotHasKey('audio', $this->readJson($storyFile));

        try {
            $this->app->make(TextToSpeech::class)->synthesize('The door closed.');
            $this->fail('Se esperaba TtsUnavailableException.');
        } catch (TtsUnavailableException $exception) {
            $this->assertStringContainsString(KokoroTts::START_COMMAND, $exception->getMessage());
        }

        $this->assertMasterMissing('three-sentences');
        $this->assertSame(0, $this->cachedWavCount());
    }

    public function test_changing_voice_uses_a_new_cache_hash_and_resynthesizes(): void
    {
        $this->fakeSidecar();
        $storyFile = $this->writeStory();

        $this->artisan('story:narrate', [
            'file' => $storyFile,
            '--skip-timings' => true,
        ])->assertSuccessful();

        $defaultHashes = $this->cachedHashes();
        $this->assertCount(3, $defaultHashes);

        $this->artisan('story:narrate', [
            'file' => $storyFile,
            '--skip-timings' => true,
            '--voice' => 'am_adam',
        ])->assertSuccessful();

        $this->assertSame(6, $this->synthesizeCount());

        $allHashes = $this->cachedHashes();
        $this->assertCount(6, $allHashes);
        $this->assertCount(3, array_diff($allHashes, $defaultHashes));

        $payload = $this->readJson($storyFile);
        $this->assertSame('am_adam', $payload['audio']['voice']);
    }

    public function test_the_alignment_report_measures_text_anchoring_and_uncovered_master(): void
    {
        $report = $this->timer()->alignmentReport([
            ['start' => 0.0, 'end' => 1.0, 'pauseAfter' => 0.1, 'alignment' => 'text'],
            ['start' => 1.1, 'end' => 2.8, 'pauseAfter' => 0.2, 'alignment' => 'text'],
        ], $this->silenceWavPath());

        $this->assertSame(2, $report['sentences']);
        $this->assertSame(2, $report['textAligned']);
        $this->assertSame(0, $report['sequential']);
        $this->assertSame(1.0, $report['textRatio']);
        $this->assertEqualsWithDelta(2.8, $report['speechEnd'], 0.001);
        $this->assertEqualsWithDelta(3.0, $report['narrationEnd'], 0.01);
        $this->assertEqualsWithDelta(0.2, $report['uncovered'], 0.01);
        $this->assertSame([], $this->timer()->alignmentProblems($report));
    }

    public function test_alignment_problems_name_the_sequential_ratio_and_the_uncovered_tail(): void
    {
        config([
            'stories.whisper.alignment.min_text_ratio' => 0.6,
            'stories.whisper.alignment.max_uncovered_seconds' => 1.0,
        ]);

        $timer = $this->timer();
        $report = $timer->alignmentReport([
            ['start' => 0.0, 'end' => 0.4, 'pauseAfter' => 0.0, 'alignment' => 'text'],
            ['start' => 0.4, 'end' => 0.7, 'pauseAfter' => 0.0, 'alignment' => 'sequential'],
            ['start' => 0.7, 'end' => 0.9, 'pauseAfter' => 0.0, 'alignment' => 'sequential'],
        ], $this->silenceWavPath());

        $this->assertSame(1, $report['textAligned']);
        $this->assertSame(2, $report['sequential']);
        $this->assertEqualsWithDelta(0.3333, $report['textRatio'], 0.001);
        $this->assertEqualsWithDelta(2.1, $report['uncovered'], 0.01);

        $problems = $timer->alignmentProblems($report);

        $this->assertCount(2, $problems);
        $this->assertStringContainsString('1 de 3 frases', $problems[0]);
        $this->assertStringContainsString('2.100 s sin cubrir', $problems[1]);
    }

    public function test_an_alignment_without_sentences_reports_no_problems(): void
    {
        $timer = $this->timer();

        $this->assertSame([], $timer->alignmentProblems($timer->alignmentReport([], $this->silenceWavPath())));
    }

    public function test_a_sentence_that_does_not_anchor_stays_inside_its_hole(): void
    {
        // Antes, la frase que no anclaba se comía tantas palabras de whisper como tokens tenía, el
        // cursor se descuadraba y ninguna frase posterior volvía a anclar.
        $words = $this->whisperWords(['the', 'door', 'then', 'the', 'whistle', 'came', 'closer',
            'nobody', 'answered', 'the', 'call', 'the', 'road', 'stayed', 'empty']);

        $aligned = $this->timer()->alignToSentences($words, $this->fourSentences(), 9.0);

        $this->assertSame(
            ['sequential', 'text', 'text', 'text'],
            array_column($aligned, 'alignment'),
        );
        $this->assertSame(0.0, $aligned[0]['start']);
        $this->assertSame($aligned[1]['start'], $aligned[0]['end']);
        $this->assertEqualsWithDelta(1.0, $aligned[1]['start'], 0.001);
        $this->assertEqualsWithDelta(7.5, $aligned[3]['end'], 0.001);
        $this->assertMonotonic($aligned);
    }

    public function test_tokens_that_whisper_writes_differently_still_anchor(): void
    {
        $words = $this->whisperWords(['the', 'door', 'closed', 'behin', 'me', 'then', 'the',
            'whistle', 'came', 'close', 'nobody', 'answered', 'the', 'call', 'the', 'road',
            'staied', 'empty']);

        $aligned = $this->timer()->alignToSentences($words, $this->fourSentences(), 10.0);

        $this->assertSame(
            ['text', 'text', 'text', 'text'],
            array_column($aligned, 'alignment'),
        );
        $this->assertMonotonic($aligned);
    }

    public function test_an_alignment_that_never_anchors_is_stretched_to_the_master_minus_the_last_pause(): void
    {
        $aligned = $this->timer()->alignToSentences(
            $this->whisperWords(['music', 'plays', 'softly']),
            $this->fourSentences(),
            10.0,
        );

        $this->assertSame(
            ['sequential', 'sequential', 'sequential', 'sequential'],
            array_column($aligned, 'alignment'),
        );
        $this->assertSame(0.0, $aligned[0]['start']);
        // 10 s de máster menos la pausa final de 1.8 s: el silencio de cola no es habla.
        $this->assertEqualsWithDelta(8.2, $aligned[3]['end'], 0.001);
        $this->assertMonotonic($aligned);
    }

    public function test_the_aligned_sentences_publish_the_script_text_and_the_phonetics(): void
    {
        $aligned = $this->timer()->alignToSentences(
            $this->whisperWords(['the', 'koo', 'leh', 'brohn', 'waited']),
            [new NarrationSentence(
                order: 1,
                sceneOrder: 1,
                text: 'The Culebrón waited.',
                pauseAfter: 0.45,
                ttsText: 'The koo-leh-BROHN waited.',
            )],
            3.0,
        );

        $this->assertCount(1, $aligned);
        $this->assertSame('The Culebrón waited.', $aligned[0]['text']);
        $this->assertSame('The koo-leh-BROHN waited.', $aligned[0]['ttsText']);
        $this->assertSame('text', $aligned[0]['alignment']);
    }

    public function test_an_anchored_sentence_publishes_its_words_with_their_own_window(): void
    {
        $aligned = $this->timer()->alignToSentences(
            $this->whisperWords(['the', 'door', 'closed', 'behind', 'me', 'then', 'the', 'whistle',
                'came', 'closer', 'nobody', 'answered', 'the', 'call', 'the', 'road', 'stayed',
                'empty']),
            $this->fourSentences(),
            10.0,
        );

        $this->assertSame(
            ['the', 'door', 'closed', 'behind', 'me'],
            array_column($aligned[0]['words'], 'token'),
        );
        // Medio segundo por palabra: «closed» es la tercera.
        $this->assertEqualsWithDelta(1.0, $aligned[0]['words'][2]['start'], 0.001);
        $this->assertEqualsWithDelta(1.5, $aligned[0]['words'][2]['end'], 0.001);

        foreach ($aligned as $row) {
            foreach ($row['words'] as $word) {
                $this->assertGreaterThanOrEqual($row['start'], $word['start']);
                $this->assertLessThanOrEqual($row['end'], $word['end']);
            }
        }
    }

    public function test_a_sentence_placed_by_position_publishes_no_words(): void
    {
        $aligned = $this->timer()->alignToSentences(
            $this->whisperWords(['the', 'door', 'then', 'the', 'whistle', 'came', 'closer',
                'nobody', 'answered', 'the', 'call', 'the', 'road', 'stayed', 'empty']),
            $this->fourSentences(),
            9.0,
        );

        $this->assertSame('sequential', $aligned[0]['alignment']);
        $this->assertSame([], $aligned[0]['words']);
        $this->assertSame('text', $aligned[1]['alignment']);
        $this->assertNotSame([], $aligned[1]['words']);
    }

    /**
     * @return list<NarrationSentence>
     */
    private function fourSentences(): array
    {
        $texts = [
            'The door closed behind me.',
            'Then the whistle came closer.',
            'Nobody answered the call.',
            'The road stayed empty.',
        ];
        $sentences = [];

        foreach ($texts as $index => $text) {
            $sentences[] = new NarrationSentence(
                order: $index + 1,
                sceneOrder: 1,
                text: $text,
                pauseAfter: $index === count($texts) - 1 ? 1.8 : 0.45,
                ttsText: $text,
            );
        }

        return $sentences;
    }

    /**
     * Una palabra por segmento y medio segundo cada una, que es lo que devuelve whisper.cpp con
     * --max-len 1.
     *
     * @param  list<string>  $tokens
     * @return list<array{start: float, end: float, text: string}>
     */
    private function whisperWords(array $tokens, float $step = 0.5): array
    {
        $segments = [];

        foreach ($tokens as $index => $token) {
            $segments[] = [
                'start' => round($index * $step, 3),
                'end' => round(($index + 1) * $step, 3),
                'text' => $token,
            ];
        }

        return $segments;
    }

    /**
     * @param  list<array{start: float, end: float}>  $aligned
     */
    private function assertMonotonic(array $aligned): void
    {
        $previous = 0.0;

        foreach ($aligned as $index => $row) {
            $this->assertGreaterThanOrEqual($previous - 0.0005, $row['start'], "La frase {$index} empieza antes de que acabe la anterior.");
            $this->assertGreaterThanOrEqual($row['start'] - 0.0005, $row['end'], "La frase {$index} acaba antes de empezar.");
            $previous = $row['end'];
        }
    }

    private function timer(): TranscriptTimer
    {
        $this->app->forgetInstance(TranscriptTimer::class);

        return $this->app->make(TranscriptTimer::class);
    }

    private function silenceWavPath(): string
    {
        return base_path('tests/Fixtures/silence.wav');
    }

    private function fakeSidecar(bool $available = true): void
    {
        $wav = $this->silenceWav();

        Http::fake(function (Request $request) use ($available, $wav) {
            if (str_contains($request->url(), '/health')) {
                if (! $available) {
                    return Http::response(['status' => 'down'], 503);
                }

                return Http::response(['status' => 'ok', 'model_loaded' => true]);
            }

            if (str_contains($request->url(), '/synthesize')) {
                if (! $available) {
                    return Http::response('', 503);
                }

                return Http::response($wav, 200, ['Content-Type' => 'audio/wav']);
            }

            return Http::response('unexpected', 404);
        });
    }

    private function writeStory(): string
    {
        $path = $this->storiesDirectory.DIRECTORY_SEPARATOR.'three-sentences.json';
        $json = json_encode($this->storyPayload(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $this->assertNotFalse($json);
        $this->app->make(Filesystem::class)->put($path, $json."\n");

        return $path;
    }

    /**
     * @return array<string, mixed>
     */
    private function storyPayload(): array
    {
        return [
            'title' => 'Three Sentences',
            'hook' => 'The door closed.',
            'description' => 'A short script used to test narration.',
            'tags' => ['test'],
            'thumbnailPrompt' => 'An empty hallway',
            'scenes' => [
                [
                    'order' => 1,
                    'narration' => 'The door closed. Then the whistle came. Who was there?',
                    'imagePrompt' => 'A closed wooden door',
                    'visualSummary' => 'A closed wooden door in a dim hallway',
                ],
            ],
            'pronunciations' => [],
        ];
    }

    private function silenceWav(): string
    {
        $path = base_path('tests/Fixtures/silence.wav');
        $wav = file_get_contents($path);
        $this->assertNotFalse($wav);

        return $wav;
    }

    private function synthesizeCount(): int
    {
        return Http::recorded(
            static fn (Request $request): bool => $request->method() === 'POST'
                && str_contains($request->url(), '/synthesize'),
        )->count();
    }

    private function cachedWavCount(): int
    {
        return count($this->cachedHashes());
    }

    /**
     * @return list<string>
     */
    private function cachedHashes(): array
    {
        $files = glob($this->cacheDirectory.DIRECTORY_SEPARATOR.'*.wav');

        if ($files === false) {
            return [];
        }

        $hashes = array_map(static fn (string $path): string => basename($path, '.wav'), $files);
        sort($hashes);

        return array_values($hashes);
    }

    private function assertMasterExists(string $slug): void
    {
        $directory = $this->storiesDirectory.DIRECTORY_SEPARATOR.$slug;
        $this->assertFileExists($directory.DIRECTORY_SEPARATOR.'narration.wav');
        $this->assertFileExists($directory.DIRECTORY_SEPARATOR.'narration.mp3');
        $this->assertFileDoesNotExist($directory.DIRECTORY_SEPARATOR.'timings.json');
    }

    private function assertMasterMissing(string $slug): void
    {
        $directory = $this->storiesDirectory.DIRECTORY_SEPARATOR.$slug;
        $this->assertFileDoesNotExist($directory.DIRECTORY_SEPARATOR.'narration.wav');
        $this->assertFileDoesNotExist($directory.DIRECTORY_SEPARATOR.'narration.mp3');
        $this->assertFileDoesNotExist($directory.DIRECTORY_SEPARATOR.'timings.json');
    }

    /**
     * @return array<string, mixed>
     */
    private function readJson(string $path): array
    {
        $json = file_get_contents($path);
        $this->assertNotFalse($json);

        /** @var array<string, mixed> $payload */
        $payload = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        return $payload;
    }
}
