<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Storage\RenderedStoryPurger;
use Illuminate\Filesystem\Filesystem;
use InvalidArgumentException;
use Tests\TestCase;

final class RenderedStoryPurgerTest extends TestCase
{
    private const SLUG = '2026-01-01-el-molino';

    private string $storiesDirectory;

    private string $storyDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storiesDirectory = 'testing/purge-'.bin2hex(random_bytes(4));
        $this->storyDirectory = storage_path('app/'.$this->storiesDirectory).'/'.self::SLUG;

        $this->app->make('config')->set('stories.output_path', $this->storiesDirectory);
        $this->app->make(Filesystem::class)->ensureDirectoryExists($this->storyDirectory);
    }

    protected function tearDown(): void
    {
        $this->app->make(Filesystem::class)->deleteDirectory(storage_path('app/testing'));

        parent::tearDown();
    }

    public function test_the_audio_goes_and_the_video_and_the_diagnosis_stay(): void
    {
        $this->write([
            'narration.wav', 'narration.mp3', 'narration_mix.wav', 'narration_mix.mp3',
            'contact-sheet-1.jpg', 'contact-sheet-2.jpg',
            'video.mp4', 'subtitles.srt', 'credits.txt', 'timings.json', 'shots.json', 'sounds.json',
        ]);

        $purged = $this->purger()->purge(self::SLUG);

        $this->assertSame(6, $purged['files']);
        $this->assertGreaterThan(0, $purged['bytes']);

        foreach (['narration.wav', 'narration.mp3', 'narration_mix.wav', 'narration_mix.mp3',
            'contact-sheet-1.jpg', 'contact-sheet-2.jpg'] as $gone) {
            $this->assertFileDoesNotExist($this->storyDirectory.'/'.$gone);
        }

        foreach (['video.mp4', 'subtitles.srt', 'credits.txt', 'timings.json', 'shots.json',
            'sounds.json'] as $kept) {
            $this->assertFileExists($this->storyDirectory.'/'.$kept);
        }
    }

    public function test_purging_twice_is_harmless(): void
    {
        $this->write(['narration.wav', 'video.mp4']);

        $this->purger()->purge(self::SLUG);
        $second = $this->purger()->purge(self::SLUG);

        $this->assertSame(0, $second['files']);
        $this->assertFileExists($this->storyDirectory.'/video.mp4');
    }

    public function test_a_configured_artifact_that_escapes_the_story_directory_is_rejected(): void
    {
        $this->app->make('config')->set('stories.purge.artifacts', ['../../secreto.txt']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('solo acepta nombres de fichero');

        $this->purger();
    }

    public function test_a_pattern_that_would_match_the_video_is_rejected(): void
    {
        $this->write(['video.mp4']);
        $this->app->make('config')->set('stories.purge.artifacts', []);
        $this->app->make('config')->set('stories.purge.patterns', ['video.*']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('no puede borrar video.mp4');

        try {
            $this->purger()->purge(self::SLUG);
        } finally {
            $this->assertFileExists($this->storyDirectory.'/video.mp4');
        }
    }

    public function test_a_slug_that_is_not_a_slug_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('no sirve para depurar artefactos');

        $this->purger()->purge('../../..');
    }

    /**
     * @param  list<string>  $names
     */
    private function write(array $names): void
    {
        $files = $this->app->make(Filesystem::class);

        foreach ($names as $name) {
            $files->put($this->storyDirectory.'/'.$name, str_repeat('x', 32));
        }
    }

    private function purger(): RenderedStoryPurger
    {
        $this->app->forgetInstance(RenderedStoryPurger::class);

        return $this->app->make(RenderedStoryPurger::class);
    }
}
