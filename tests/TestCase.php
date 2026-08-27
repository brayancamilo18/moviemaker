<?php

namespace Tests;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    private ?string $audioIndexPath = null;

    protected function setUp(): void
    {
        parent::setUp();

        // Cada test escribe su propio índice local de audio: la librería de la máquina no se ensucia
        // y ningún test hereda los clips que indexó el anterior.
        $this->audioIndexPath = storage_path('app/testing/audio-index/'.bin2hex(random_bytes(8)).'.json');
        $this->app->make('config')->set('stories.audio.local_index_path', $this->audioIndexPath);
    }

    protected function tearDown(): void
    {
        if ($this->audioIndexPath !== null) {
            (new Filesystem)->delete($this->audioIndexPath);
        }

        parent::tearDown();
    }
}
