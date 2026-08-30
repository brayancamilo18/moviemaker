<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Image\ShotPlanner;
use App\Services\Story\StoryValidator;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class ValidateStoryTest extends TestCase
{
    private string $storiesDir;

    private string $slug = 'validate-fixture';

    protected function setUp(): void
    {
        parent::setUp();

        $this->storiesDir = 'testing/validate-stories-'.bin2hex(random_bytes(4));

        config([
            'stories.output_path' => $this->storiesDir,
            'stories.audio.tail_seconds' => 0.0,
        ]);
        $this->app->forgetInstance(StoryValidator::class);

        (new Filesystem)->ensureDirectoryExists($this->storyDirectory());
        $this->writeFixture();
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory(storage_path('app/'.$this->storiesDir));

        parent::tearDown();
    }

    public function test_validate_passes_a_coherent_story(): void
    {
        $this->artisan('story:validate', ['file' => $this->storyFile()])
            ->expectsOutputToContain('sin bloqueantes')
            ->assertSuccessful();
    }

    public function test_validate_fails_when_the_timings_drifted_from_the_master(): void
    {
        // El habla acaba en 2 s de un máster de 8 s y casi nada ancló por texto: es la firma de la
        // deriva del alineador, y con ella shots.json y sounds.json van desplazados.
        $this->writeTimings([
            ['start' => 0.0, 'end' => 1.0, 'alignment' => 'sequential'],
            ['start' => 1.0, 'end' => 2.0, 'alignment' => 'sequential'],
        ]);

        $this->artisan('story:validate', ['file' => $this->storyFile()])
            ->expectsOutputToContain('hay bloqueantes')
            ->expectsOutputToContain('frases anclaron por texto')
            ->assertFailed();
    }

    public function test_validate_warns_but_does_not_block_when_there_are_no_timings(): void
    {
        (new Filesystem)->delete($this->storyDirectory().DIRECTORY_SEPARATOR.'timings.json');

        $this->artisan('story:validate', ['file' => $this->storyFile()])
            ->expectsOutputToContain('No hay timings.json')
            ->expectsOutputToContain('sin bloqueantes')
            ->assertSuccessful();
    }

    public function test_validate_fails_when_a_shot_is_longer_than_the_ceiling(): void
    {
        $dir = $this->storyDirectory();
        $ceiling = (float) config('stories.shots.max_duration') + (float) config('stories.shots.max_hold_slack');
        $duration = $ceiling + 2.0;

        $this->writeWav($dir.DIRECTORY_SEPARATOR.'narration.wav', $duration);
        $this->writeWav($dir.DIRECTORY_SEPARATOR.'narration_mix.wav', $duration);
        $this->writeTimings([
            ['start' => 0.0, 'end' => $duration / 2, 'alignment' => 'text'],
            ['start' => $duration / 2, 'end' => $duration, 'alignment' => 'text'],
        ]);
        $this->writeShots([
            $this->shot(1, 0.0, $duration, $dir.DIRECTORY_SEPARATOR.'shot-1.jpg', 'A dim hallway'),
        ]);

        $this->artisan('story:validate', ['file' => $this->storyFile()])
            ->expectsOutputToContain('hay bloqueantes')
            ->expectsOutputToContain('Planos demasiado largos')
            ->assertFailed();
    }

    public function test_validate_fails_when_a_shot_has_no_description(): void
    {
        $dir = $this->storyDirectory();
        $this->writeShots([
            $this->shot(1, 0.0, 4.0, $dir.DIRECTORY_SEPARATOR.'shot-1.jpg', ''),
            $this->shot(2, 4.0, 8.0, $dir.DIRECTORY_SEPARATOR.'shot-2.jpg', 'Fog over the road'),
        ]);

        $this->artisan('story:validate', ['file' => $this->storyFile()])
            ->expectsOutputToContain('hay bloqueantes')
            ->expectsOutputToContain('Sin description')
            ->assertFailed();
    }

    /**
     * El efecto cuya palabra no está alineada no se coloca en la mezcla, así que el validador tiene
     * que decir cuántos golpes van a sonar de verdad. Es aviso: quedarse sin uno no rompe el vídeo.
     */
    public function test_it_counts_the_effects_that_hang_from_their_word(): void
    {
        $this->writeTimings([
            [
                'start' => 0.0,
                'end' => 4.0,
                'alignment' => 'text',
                'words' => [
                    ['token' => 'the', 'start' => 0.0, 'end' => 0.4],
                    ['token' => 'door', 'start' => 0.4, 'end' => 0.9],
                    ['token' => 'creaked', 'start' => 0.9, 'end' => 1.6],
                ],
            ],
            [
                'start' => 4.0,
                'end' => 8.0,
                'alignment' => 'text',
                'words' => [
                    ['token' => 'nobody', 'start' => 4.0, 'end' => 4.6],
                    ['token' => 'answered', 'start' => 4.6, 'end' => 5.2],
                ],
            ],
        ]);
        $this->writeSounds([
            [
                'shotIndex' => 1,
                'anchorWord' => 'creaked',
                'offsetRatio' => 0.2,
                'query' => 'door creak',
                'tags' => ['door', 'creak'],
                'importance' => 'key',
            ],
            [
                'shotIndex' => 2,
                'anchorWord' => 'slammed',
                'offsetRatio' => 0.5,
                'query' => 'door slam',
                'tags' => ['door', 'slam'],
                'importance' => 'texture',
            ],
        ]);

        $this->artisan('story:validate', ['file' => $this->storyFile()])
            ->expectsOutputToContain('1 de 2 efecto(s) anclados. No van a sonar: plano 2')
            ->assertSuccessful();
    }

    public function test_a_disabled_outro_warns_and_does_not_block(): void
    {
        config(['stories.story.outro.enabled' => false]);
        $this->app->forgetInstance(StoryValidator::class);

        $this->artisan('story:validate', ['file' => $this->storyFile()])
            ->expectsOutputToContain('El outro está desactivado')
            ->expectsOutputToContain('sin bloqueantes')
            ->assertSuccessful();
    }

    public function test_an_enabled_outro_without_scene_9000_is_blocking(): void
    {
        $this->enableOutro();

        $this->artisan('story:validate', ['file' => $this->storyFile()])
            ->expectsOutputToContain('hay bloqueantes')
            ->expectsOutputToContain('El outro no llegó al audio')
            ->assertFailed();
    }

    public function test_an_outro_with_half_the_words_is_blocking(): void
    {
        $this->enableOutro();
        $tokens = $this->outroTokens();
        $half = array_slice($tokens, 0, (int) floor(count($tokens) / 2));

        $this->writeOutroArtifacts($half, outroShots: 1);

        $this->artisan('story:validate', ['file' => $this->storyFile()])
            ->expectsOutputToContain('hay bloqueantes')
            ->expectsOutputToContain('El outro se sintetizó a medias')
            ->assertFailed();
    }

    public function test_two_outro_shots_are_blocking(): void
    {
        $this->enableOutro();
        $this->writeOutroArtifacts($this->outroTokens(), outroShots: 2);

        $this->artisan('story:validate', ['file' => $this->storyFile()])
            ->expectsOutputToContain('hay bloqueantes')
            ->expectsOutputToContain('exactamente un plano de cierre')
            ->assertFailed();
    }

    private function enableOutro(): void
    {
        config(['stories.story.outro.enabled' => true]);
        $this->app->forgetInstance(StoryValidator::class);
    }

    /**
     * @return list<string>
     */
    private function outroTokens(): array
    {
        $normalized = mb_strtolower((string) config('stories.story.outro.text'));
        $normalized = str_replace(["'", '’', '‘'], '', $normalized);
        $normalized = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $normalized) ?? $normalized;
        $normalized = trim((string) preg_replace('/\s+/u', ' ', $normalized));

        return $normalized === '' ? [] : explode(' ', $normalized);
    }

    /**
     * @param  list<string>  $heard
     */
    private function writeOutroArtifacts(array $heard, int $outroShots): void
    {
        $dir = $this->storyDirectory();
        $words = [];

        foreach ($heard as $index => $token) {
            $words[] = [
                'token' => $token,
                'start' => 4.0 + ($index * 0.05),
                'end' => 4.0 + (($index + 1) * 0.05),
            ];
        }

        $this->writeTimings([
            [
                'start' => 0.0,
                'end' => 4.0,
                'alignment' => 'text',
                'sceneOrder' => 1,
                'text' => 'The door closed behind me.',
            ],
            [
                'start' => 4.0,
                'end' => 8.0,
                'alignment' => 'text',
                'sceneOrder' => 9000,
                'text' => (string) config('stories.story.outro.text'),
                'words' => $words,
            ],
        ]);

        $shots = [
            $this->shot(1, 0.0, 4.0, $dir.DIRECTORY_SEPARATOR.'shot-1.jpg', 'A dim hallway'),
        ];

        for ($index = 0; $index < $outroShots; $index++) {
            $shots[] = $this->shot(
                2 + $index,
                4.0,
                8.0,
                $dir.DIRECTORY_SEPARATOR.'shot-2.jpg',
                'empty dark room',
                isOutro: true,
            );
        }

        $this->writeShots($shots);
    }

    /**
     * @param  list<array<string, mixed>>  $directedSfx
     */
    private function writeSounds(array $directedSfx): void
    {
        file_put_contents($this->storyDirectory().DIRECTORY_SEPARATOR.'sounds.json', json_encode([
            'version' => 1,
            'slug' => pathinfo($this->storyFile(), PATHINFO_FILENAME),
            'cues' => [],
            'directedSfx' => $directedSfx,
        ], JSON_THROW_ON_ERROR)."\n");
    }

    private function writeFixture(): void
    {
        $dir = $this->storyDirectory();
        $this->writeJpeg($dir.DIRECTORY_SEPARATOR.'shot-1.jpg');
        $this->writeJpeg($dir.DIRECTORY_SEPARATOR.'shot-2.jpg');
        $this->writeWav($dir.DIRECTORY_SEPARATOR.'narration.wav', 8.0);
        $this->writeWav($dir.DIRECTORY_SEPARATOR.'narration_mix.wav', 8.0);
        $this->writeShots([
            $this->shot(1, 0.0, 4.0, $dir.DIRECTORY_SEPARATOR.'shot-1.jpg', 'A dim hallway'),
            $this->shot(2, 4.0, 8.0, $dir.DIRECTORY_SEPARATOR.'shot-2.jpg', 'Fog over the road'),
        ]);
        $this->writeTimings([
            ['start' => 0.0, 'end' => 4.0, 'alignment' => 'text'],
            ['start' => 4.0, 'end' => 8.0, 'alignment' => 'text'],
        ]);

        file_put_contents($this->storyFile(), json_encode([
            'title' => 'Validate fixture',
            'hook' => 'The door closed.',
            'description' => 'A two-shot fixture for story:validate.',
            'tags' => ['test'],
            'thumbnailPrompt' => 'An empty hallway',
            'scenes' => [[
                'order' => 1,
                'narration' => 'The door closed behind me.',
                'imagePrompt' => 'A dim hallway',
                'visualSummary' => 'A dim hallway vanishing into fog at dusk',
            ]],
            'pronunciations' => [],
        ], JSON_THROW_ON_ERROR)."\n");
    }

    /**
     * @param  list<array<string, mixed>>  $shots
     */
    private function writeShots(array $shots): void
    {
        file_put_contents($this->storyDirectory().DIRECTORY_SEPARATOR.'shots.json', json_encode([
            'version' => 1,
            'plannerVersion' => ShotPlanner::VERSION,
            'shots' => $shots,
        ], JSON_THROW_ON_ERROR)."\n");
    }

    /**
     * @param  list<array{start: float, end: float, alignment: string, sceneOrder?: int, text?: string, words?: list<array{token: string, start: float, end: float}>}>  $sentences
     */
    private function writeTimings(array $sentences): void
    {
        $rows = [];

        foreach ($sentences as $index => $sentence) {
            $rows[] = [
                'order' => $index + 1,
                'sceneOrder' => (int) ($sentence['sceneOrder'] ?? 1),
                'text' => $sentence['text'] ?? ('Fixture sentence '.($index + 1).'.'),
                'start' => $sentence['start'],
                'end' => $sentence['end'],
                'pauseAfter' => 0.0,
                'alignment' => $sentence['alignment'],
                'words' => $sentence['words'] ?? [],
            ];
        }

        file_put_contents($this->storyDirectory().DIRECTORY_SEPARATOR.'timings.json', json_encode([
            'version' => 1,
            'sentences' => $rows,
            'scenes' => [[
                'order' => 1,
                'start' => $rows[0]['start'],
                'end' => $rows[array_key_last($rows)]['end'],
                'duration' => $rows[array_key_last($rows)]['end'] - $rows[0]['start'],
                'sentenceCount' => count($rows),
            ]],
        ], JSON_THROW_ON_ERROR)."\n");
    }

    /**
     * @return array<string, mixed>
     */
    private function shot(
        int $order,
        float $start,
        float $end,
        string $image,
        string $description,
        bool $isOutro = false,
    ): array {
        return [
            'order' => $order,
            'sceneOrder' => $isOutro ? 9000 : 1,
            'start' => $start,
            'end' => $end,
            'sourceText' => 'Fixture shot '.$order,
            'framing' => 'medium shot',
            'motion' => 'static',
            'subject' => 'environment',
            'threatStage' => null,
            'description' => $description,
            'characterSlugs' => [],
            'imagePath' => $image,
            'placeholder' => false,
            'isOutro' => $isOutro,
        ];
    }

    private function writeJpeg(string $path): void
    {
        $process = new Process([
            'ffmpeg', '-nostdin', '-y', '-hide_banner',
            '-f', 'lavfi',
            '-i', 'color=c=gray:s=640x360',
            '-frames:v', '1',
            $path,
        ]);
        $process->setTimeout(30);
        $process->mustRun();
    }

    private function writeWav(string $path, float $duration): void
    {
        $process = new Process([
            'ffmpeg', '-nostdin', '-y', '-hide_banner',
            '-f', 'lavfi',
            '-i', sprintf('sine=frequency=220:sample_rate=48000:duration=%.3f', $duration),
            '-ac', '2',
            '-ar', '48000',
            '-sample_fmt', 's16',
            $path,
        ]);
        $process->setTimeout(30);
        $process->mustRun();
    }

    private function storyFile(): string
    {
        return storage_path('app/'.$this->storiesDir.DIRECTORY_SEPARATOR.$this->slug.'.json');
    }

    private function storyDirectory(): string
    {
        return storage_path('app/'.$this->storiesDir.DIRECTORY_SEPARATOR.$this->slug);
    }
}
