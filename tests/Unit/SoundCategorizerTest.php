<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Audio\SoundCategorizer;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class SoundCategorizerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Http::preventStrayRequests();
    }

    public function test_loads_twenty_four_categories_with_expected_profiles(): void
    {
        $categories = $this->categorizer()->all();

        $this->assertCount(24, $categories);

        $bySlug = [];

        foreach ($categories as $category) {
            $bySlug[$category['slug']] = $category;
        }

        $this->assertSame('wind', $bySlug['wind_exterior']['synthProfile']);
        $this->assertSame('room', $bySlug['room_tone_interior']['synthProfile']);
        $this->assertSame('drone', $bySlug['drone_dread']['synthProfile']);
        $this->assertSame('impact', $bySlug['impact_dull']['synthProfile']);
        $this->assertSame('impact', $bySlug['impact_sharp']['synthProfile']);
        $this->assertSame('friction', $bySlug['wood_stress']['synthProfile']);
        $this->assertSame('none', $bySlug['door']['synthProfile']);
        $this->assertSame('none', $bySlug['breath_human']['synthProfile']);
        $this->assertSame('ambience', $bySlug['forest_night']['type']);
        $this->assertSame('sfx', $bySlug['water_drip']['type']);
    }

    public function test_categorizes_query_and_tags_above_threshold(): void
    {
        $categorizer = $this->categorizer();

        $this->assertSame('wind_exterior', $categorizer->categorize(['wind'], 'wind howling night'));
        $this->assertSame('room_tone_interior', $categorizer->categorize(['house'], 'empty room tone'));
        $this->assertSame('water_drip', $categorizer->categorize(['stone'], 'water drip stone'));
        $this->assertSame('water_river', $categorizer->categorize([], 'river stream stone alcove'));
        $this->assertSame('door', $categorizer->categorize(['door'], 'door creak slow'));
        $this->assertSame('footsteps_hard', $categorizer->categorize([], 'footsteps wooden floor'));
        $this->assertSame('footsteps_soft', $categorizer->categorize([], 'footsteps gravel dirt'));
        $this->assertSame('wood_stress', $categorizer->categorize(['wood'], 'wood crack single'));
    }

    public function test_returns_null_below_threshold(): void
    {
        $this->assertNull($this->categorizer()->categorize([], 'totallyunknownfx xyz'));
    }

    private function categorizer(): SoundCategorizer
    {
        return $this->app->make(SoundCategorizer::class);
    }
}
