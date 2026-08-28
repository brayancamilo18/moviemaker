<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Video\ShotClipRenderer;
use Illuminate\Filesystem\Filesystem;
use InvalidArgumentException;
use Tests\TestCase;

final class ShotClipRendererTest extends TestCase
{
    public function test_it_refuses_to_scale_below_the_maximum_zoom(): void
    {
        config([
            'stories.video.source_upscale' => 1.1,
            'stories.video.zoom_max' => 1.18,
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('video.source_upscale (1.10) no puede ser menor que video.zoom_max (1.18)');

        $this->renderer();
    }

    public function test_it_accepts_a_scale_that_covers_the_zoom_crop(): void
    {
        config([
            'stories.video.source_upscale' => 1.25,
            'stories.video.zoom_max' => 1.18,
        ]);

        $this->assertInstanceOf(ShotClipRenderer::class, $this->renderer());
    }

    public function test_the_shipped_configuration_covers_the_zoom_crop(): void
    {
        $this->assertGreaterThanOrEqual(
            (float) config('stories.video.zoom_max'),
            (float) config('stories.video.source_upscale'),
        );
    }

    private function renderer(): ShotClipRenderer
    {
        return new ShotClipRenderer(
            $this->app->make(Filesystem::class),
            $this->app->make('config'),
        );
    }
}
