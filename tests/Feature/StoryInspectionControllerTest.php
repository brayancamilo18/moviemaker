<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Story;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class StoryInspectionControllerTest extends TestCase
{
    use RefreshDatabase;

    private string $storiesDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storiesDirectory = storage_path('app/testing/inspection-'.bin2hex(random_bytes(4)));
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->storiesDirectory);
        $this->app->make('config')->set(
            'stories.output_path',
            'testing/'.basename($this->storiesDirectory),
        );
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->storiesDirectory);

        parent::tearDown();
    }

    public function test_a_story_with_a_script_on_disk_returns_the_scenes(): void
    {
        $story = Story::factory()->create([
            'slug' => '2026-08-30-the-mill-chain',
            'title' => 'The mill chain',
        ]);

        $this->writeScript($story->slug, [
            'title' => 'The mill chain',
            'hook' => 'The whistle receded.',
            'description' => 'A walker follows a sound that never arrives.',
            'tags' => ['horror', 'folklore'],
            'thumbnailPrompt' => 'should not appear',
            'visualBible' => ['setting' => 'should not appear'],
            'shots' => [['order' => 1, 'prompt' => 'should not appear']],
            'pronunciations' => [
                ['term' => 'Silbón', 'phonetic' => 'seel-BON'],
            ],
            'review' => [
                'verdict' => 'publish',
                'score' => 8,
                'nonNativePhrases' => [],
                'clichedElements' => ['the door creaked'],
                'tensionDips' => [],
                'ttsRisks' => ['Silbón'],
            ],
            'scenes' => [
                [
                    'order' => 2,
                    'narration' => 'The mill answered with a chain.',
                    'visualSummary' => 'A dark mill at night',
                    'imagePrompt' => 'should not appear',
                ],
                [
                    'order' => 1,
                    'narration' => 'The door closed.',
                    'visualSummary' => 'A shut door in fog',
                    'imagePrompt' => 'should not appear',
                ],
            ],
        ]);

        $this->get(route('stories.inspection.script', $story))
            ->assertOk()
            ->assertJsonPath('available', true)
            ->assertJsonPath('title', 'The mill chain')
            ->assertJsonPath('hook', 'The whistle receded.')
            ->assertJsonPath('description', 'A walker follows a sound that never arrives.')
            ->assertJsonPath('tags', ['horror', 'folklore'])
            ->assertJsonPath('scenes.0.order', 1)
            ->assertJsonPath('scenes.0.narration', 'The door closed.')
            ->assertJsonPath('scenes.0.visualSummary', 'A shut door in fog')
            ->assertJsonPath('scenes.1.order', 2)
            ->assertJsonPath('pronunciations.0.term', 'Silbón')
            ->assertJsonPath('pronunciations.0.phonetic', 'seel-BON')
            ->assertJsonPath('review.verdict', 'publish')
            ->assertJsonPath('review.score', 8)
            ->assertJsonPath('wordCount', 9)
            ->assertJsonPath('estimatedSeconds', 4)
            ->assertJsonMissingPath('visualBible')
            ->assertJsonMissingPath('shots')
            ->assertJsonMissingPath('scenes.0.imagePrompt')
            ->assertJsonMissingPath('thumbnailPrompt');
    }

    public function test_a_story_without_a_script_file_returns_404_not_500(): void
    {
        $story = Story::factory()->create([
            'slug' => '2026-08-30-missing-script',
        ]);

        $this->get(route('stories.inspection.script', $story))
            ->assertNotFound()
            ->assertJsonPath('available', false)
            ->assertJsonPath('reason', 'El guion todavía no se ha generado.');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function writeScript(string $slug, array $payload): void
    {
        (new Filesystem)->put(
            $this->storiesDirectory.DIRECTORY_SEPARATOR.$slug.'.json',
            (string) json_encode($payload, JSON_THROW_ON_ERROR),
        );
    }
}
