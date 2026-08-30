<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Story;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class StoryMediaTest extends TestCase
{
    use RefreshDatabase;

    private const FILE_BYTES = 200;

    private string $directory;

    private string $payload;

    private Story $story;

    protected function setUp(): void
    {
        parent::setUp();

        $this->payload = random_bytes(self::FILE_BYTES);
        $this->story = Story::factory()->create([
            'slug' => 'test-media-'.bin2hex(random_bytes(4)),
        ]);
        $this->directory = $this->story->directory();

        $files = $this->app->make(Filesystem::class);
        $files->ensureDirectoryExists($this->directory);
        $files->put($this->directory.DIRECTORY_SEPARATOR.'video.mp4', $this->payload);
    }

    protected function tearDown(): void
    {
        $this->app->make(Filesystem::class)->deleteDirectory($this->directory);

        parent::tearDown();
    }

    public function test_without_range_it_returns_the_whole_file(): void
    {
        $response = $this->get($this->url());

        $response->assertOk();
        $response->assertHeader('Accept-Ranges', 'bytes');
        $response->assertHeader('Content-Type', 'video/mp4');
        $response->assertHeader('Content-Length', (string) self::FILE_BYTES);
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertSame($this->payload, $response->streamedContent());
    }

    public function test_a_closed_range_returns_partial_content(): void
    {
        $response = $this->withHeaders(['Range' => 'bytes=0-99'])->get($this->url());

        $response->assertStatus(206);
        $response->assertHeader('Content-Length', '100');
        $response->assertHeader('Content-Range', 'bytes 0-99/'.self::FILE_BYTES);
        $this->assertSame(substr($this->payload, 0, 100), $response->streamedContent());
    }

    public function test_an_open_ended_range_reads_from_that_byte_to_the_end(): void
    {
        $response = $this->withHeaders(['Range' => 'bytes=100-'])->get($this->url());

        $response->assertStatus(206);
        $response->assertHeader('Content-Length', '100');
        $response->assertHeader('Content-Range', 'bytes 100-199/'.self::FILE_BYTES);
        $this->assertSame(substr($this->payload, 100), $response->streamedContent());
    }

    public function test_a_range_past_the_file_returns_416(): void
    {
        $response = $this->withHeaders(['Range' => 'bytes=99999999-'])->get($this->url());

        $response->assertStatus(416);
        $response->assertHeader('Content-Range', 'bytes */'.self::FILE_BYTES);
    }

    public function test_an_artifact_outside_the_whitelist_returns_404(): void
    {
        $this->get($this->url('shots.json'))->assertNotFound();
        $this->get($this->url('narration.wav'))->assertNotFound();
    }

    public function test_a_dot_dot_artifact_returns_404(): void
    {
        $this->get('/media/'.$this->story->slug.'/..')->assertNotFound();
    }

    private function url(string $artifact = 'video.mp4'): string
    {
        return route('story.media', [
            'story' => $this->story,
            'artifact' => $artifact,
        ]);
    }
}
