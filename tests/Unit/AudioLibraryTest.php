<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Audio\AudioLibrary;
use Illuminate\Filesystem\Filesystem;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Stringable;
use Tests\TestCase;

final class AudioLibraryTest extends TestCase
{
    private string $libraryDir;

    private string $localIndexPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->libraryDir = storage_path('app/testing/audio-library-'.bin2hex(random_bytes(4)));
        $this->localIndexPath = $this->libraryDir.DIRECTORY_SEPARATOR.'index'.DIRECTORY_SEPARATOR.'library.json';

        $config = $this->app->make('config');
        $config->set('stories.audio.library_path', $this->libraryDir);
        $config->set('stories.audio.local_index_path', $this->localIndexPath);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->libraryDir);

        parent::tearDown();
    }

    public function test_it_reads_the_union_of_both_indexes(): void
    {
        $this->writeIndex($this->coreManifestPath(), [$this->clip('core/door.wav', 'sfx')]);
        $this->writeIndex($this->localIndexPath, [$this->clip('ambience/wind-1.wav', 'ambience')]);
        $this->putWav('core/door.wav');
        $this->putWav('ambience/wind-1.wav');

        $this->assertSame(
            ['core/door.wav', 'ambience/wind-1.wav'],
            array_column($this->library()->clips(), 'file'),
        );
    }

    public function test_it_discards_indexed_clips_whose_file_is_gone(): void
    {
        $this->writeIndex($this->coreManifestPath(), [
            $this->clip('core/door.wav', 'sfx'),
            $this->clip('core/glass.wav', 'sfx'),
        ]);
        $this->writeIndex($this->localIndexPath, [$this->clip('sfx/rock-drop.wav', 'sfx')]);
        $this->putWav('core/door.wav');

        $logger = $this->fakeLogger();
        $library = $this->library();

        $this->assertSame(['core/door.wav'], array_column($library->clips(), 'file'));
        $this->assertSame(
            ['core/door.wav', 'core/glass.wav', 'sfx/rock-drop.wav'],
            array_column($library->allClips(), 'file'),
        );
        $this->assertCount(3, $library->filter('sfx', null, includeMissing: true));
        $this->assertFalse($library->fileExists('core/glass.wav'));

        $library->clips();

        $this->assertSame(
            ['La librería de audio ignora 2 clips indexados cuyo fichero ya no está en disco.'],
            $logger->warnings,
        );
    }

    public function test_a_new_clip_lands_in_the_local_index_and_not_in_the_versioned_manifest(): void
    {
        $this->writeIndex($this->coreManifestPath(), [$this->clip('core/door.wav', 'sfx')]);
        $this->putWav('core/door.wav');
        $this->putWav('sfx/rock-drop.wav');

        $manifestBefore = (string) file_get_contents($this->coreManifestPath());

        $this->library()->add($this->clip('sfx/rock-drop.wav', 'sfx'));

        $this->assertSame($manifestBefore, file_get_contents($this->coreManifestPath()));

        $local = json_decode((string) file_get_contents($this->localIndexPath), true);

        $this->assertIsArray($local);
        $this->assertSame(['sfx/rock-drop.wav'], array_column($local['clips'], 'file'));
        $this->assertSame(
            ['core/door.wav', 'sfx/rock-drop.wav'],
            array_column($this->library()->clips(), 'file'),
        );
    }

    public function test_add_marks_the_clip_as_local(): void
    {
        $this->putWav('sfx/rock-drop.wav');

        $this->library()->add($this->clip('sfx/rock-drop.wav', 'sfx'));

        $local = json_decode((string) file_get_contents($this->localIndexPath), true);

        $this->assertIsArray($local);
        $this->assertFalse($local['clips'][0]['is_core']);
    }

    public function test_add_core_writes_to_the_versioned_manifest_and_not_to_the_local_index(): void
    {
        $this->putWav('core/door.wav');

        $this->library()->addCore($this->clip('core/door.wav', 'sfx'));

        $manifest = json_decode((string) file_get_contents($this->coreManifestPath()), true);

        $this->assertIsArray($manifest);
        $this->assertSame(['core/door.wav'], array_column($manifest['clips'], 'file'));
        $this->assertTrue($manifest['clips'][0]['is_core']);
        $this->assertFileDoesNotExist($this->localIndexPath);
    }

    public function test_prune_only_drops_local_clips_whose_file_is_gone(): void
    {
        $this->writeIndex($this->coreManifestPath(), [
            ['is_core' => true] + $this->clip('core/gone.wav', 'sfx'),
        ]);
        $this->writeIndex($this->localIndexPath, [
            $this->clip('sfx/gone.wav', 'sfx'),
            $this->clip('sfx/here.wav', 'sfx'),
            $this->clip('ambience/gone.wav', 'ambience'),
        ]);
        $this->putWav('sfx/here.wav');

        $library = $this->library();

        $this->assertSame(2, $library->prune());

        $local = json_decode((string) file_get_contents($this->localIndexPath), true);

        $this->assertIsArray($local);
        $this->assertSame(['sfx/here.wav'], array_column($local['clips'], 'file'));

        $manifest = json_decode((string) file_get_contents($this->coreManifestPath()), true);

        $this->assertIsArray($manifest);
        $this->assertSame(['core/gone.wav'], array_column($manifest['clips'], 'file'));
        $this->assertSame(['core/gone.wav'], array_column($library->missingCoreClips(), 'file'));
    }

    public function test_prune_reports_nothing_when_every_file_is_on_disk(): void
    {
        $this->writeIndex($this->localIndexPath, [$this->clip('sfx/here.wav', 'sfx')]);
        $this->putWav('sfx/here.wav');

        $this->assertSame(0, $this->library()->prune());
    }

    public function test_it_refuses_to_index_a_clip_that_is_not_on_disk(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("No se indexa 'sfx/rock-drop.wav'");

        $this->library()->add($this->clip('sfx/rock-drop.wav', 'sfx'));
    }

    private function library(): AudioLibrary
    {
        return $this->app->make(AudioLibrary::class);
    }

    private function coreManifestPath(): string
    {
        return $this->libraryDir.DIRECTORY_SEPARATOR.'manifest.json';
    }

    /**
     * @param  list<array<string, mixed>>  $clips
     */
    private function writeIndex(string $path, array $clips): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists(dirname($path));
        $files->put($path, (string) json_encode(['version' => 1, 'clips' => $clips], JSON_PRETTY_PRINT));
    }

    private function putWav(string $relative): void
    {
        $absolute = $this->libraryDir.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $files = new Filesystem;
        $files->ensureDirectoryExists(dirname($absolute));
        $files->put($absolute, 'RIFF');
    }

    /**
     * @return array<string, mixed>
     */
    private function clip(string $file, string $type): array
    {
        return [
            'file' => $file,
            'type' => $type,
            'tags' => ['wind'],
            'duration' => 3.0,
            'loopable' => true,
            'source_id' => 'test-'.md5($file),
            'source_url' => 'internal://test',
            'author' => 'horror-studio',
            'license' => 'internal',
            'attribution_required' => false,
            'lufs' => -20.0,
            'sha1' => sha1($file),
        ];
    }

    private function fakeLogger(): object
    {
        $logger = new class extends AbstractLogger
        {
            /** @var list<string> */
            public array $warnings = [];

            public function log(mixed $level, string|Stringable $message, array $context = []): void
            {
                if ((string) $level === 'warning') {
                    $this->warnings[] = (string) $message;
                }
            }
        };

        $this->app->instance(LoggerInterface::class, $logger);

        return $logger;
    }
}
