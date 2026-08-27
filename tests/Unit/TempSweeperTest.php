<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Storage\TempSweeper;
use Illuminate\Filesystem\Filesystem;
use InvalidArgumentException;
use Tests\TestCase;

final class TempSweeperTest extends TestCase
{
    private const DAY = 86400;

    private string $bucket;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bucket = 'testing/sweeper-'.bin2hex(random_bytes(8));
        $this->directory = storage_path('app/'.$this->bucket);
        $this->files()->ensureDirectoryExists($this->directory);

        $this->app->make('config')->set('stories.temp.max_age_seconds', self::DAY);
        $this->app->make('config')->set('stories.temp.buckets', [$this->bucket.'/*']);
    }

    protected function tearDown(): void
    {
        $this->files()->deleteDirectory($this->directory);

        parent::tearDown();
    }

    public function test_it_leaves_alone_what_a_running_job_just_wrote(): void
    {
        $file = $this->writeFile('reciente.wav', 2048);
        $workDir = $this->writeDirectory('mixer-reciente');

        $swept = $this->app->make(TempSweeper::class)->sweep();

        $this->assertSame(['entries' => 0, 'bytes' => 0], $swept);
        $this->assertFileExists($file);
        $this->assertDirectoryExists($workDir);
    }

    public function test_it_deletes_the_orphans_older_than_the_configured_age(): void
    {
        $file = $this->writeFile('huerfano.wav', 2048);
        $workDir = $this->writeDirectory('mixer-huerfano');
        $this->age($file);
        $this->age($workDir);

        $swept = $this->app->make(TempSweeper::class)->sweep();

        $this->assertSame(2, $swept['entries']);
        $this->assertSame(2048 + 1024, $swept['bytes']);
        $this->assertFileDoesNotExist($file);
        $this->assertDirectoryDoesNotExist($workDir);
    }

    public function test_it_refuses_a_bucket_that_escapes_storage_app(): void
    {
        $this->app->make('config')->set('stories.temp.buckets', ['../'.$this->bucket.'/*']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('solo puede tocar rutas dentro de');

        $this->app->make(TempSweeper::class)->sweep();
    }

    public function test_it_refuses_a_bucket_that_points_at_the_stories_tree(): void
    {
        $this->app->make('config')->set('stories.output_path', $this->bucket);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('no puede tocar');

        $this->app->make(TempSweeper::class)->sweep();
    }

    private function writeFile(string $name, int $bytes): string
    {
        $path = $this->directory.DIRECTORY_SEPARATOR.$name;
        $this->files()->put($path, str_repeat('0', $bytes));

        return $path;
    }

    private function writeDirectory(string $name): string
    {
        $path = $this->directory.DIRECTORY_SEPARATOR.$name;
        $this->files()->ensureDirectoryExists($path);
        $this->files()->put($path.DIRECTORY_SEPARATOR.'filter.txt', str_repeat('0', 1024));

        return $path;
    }

    /**
     * Envejece el mtime más allá del umbral. En un directorio se toca después de llenarlo: cada
     * entrada nueva lo pone al día.
     */
    private function age(string $path): void
    {
        touch($path, time() - self::DAY - 60);
    }

    private function files(): Filesystem
    {
        return $this->app->make(Filesystem::class);
    }
}
