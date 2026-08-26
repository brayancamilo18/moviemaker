<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Audio\FreesoundClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

final class FreesoundClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
        Http::preventStrayRequests();
    }

    public function test_search_discards_noncommercial_and_sampling_licenses(): void
    {
        Http::fake([
            'freesound.org/apiv2/search/text*' => Http::response([
                'results' => [
                    $this->sound(1, 'Creative Commons 0', 'Wind night'),
                    $this->sound(2, 'Attribution NonCommercial', 'Illegal wind'),
                    $this->sound(3, 'Attribution', 'Rain window'),
                    $this->sound(4, 'Sampling+', 'Illegal sample'),
                ],
            ], 200),
        ]);

        $sounds = $this->app->make(FreesoundClient::class)->search('wind', 'ambience', 5);

        $this->assertCount(2, $sounds);
        $this->assertSame([1, 3], array_column($sounds, 'id'));
        $this->assertSame(['Creative Commons 0', 'Attribution'], array_column($sounds, 'license'));
    }

    public function test_search_accepts_creativecommons_deed_urls(): void
    {
        Http::fake([
            'freesound.org/apiv2/search/text*' => Http::response([
                'results' => [
                    $this->sound(1, 'http://creativecommons.org/publicdomain/zero/1.0/', 'Wind night'),
                    $this->sound(2, 'https://creativecommons.org/licenses/by-nc/4.0/', 'Illegal wind'),
                    $this->sound(3, 'https://creativecommons.org/licenses/by/4.0/', 'Rain window'),
                    $this->sound(4, 'https://creativecommons.org/licenses/by-sa/4.0/', 'Share alike'),
                ],
            ], 200),
        ]);

        $sounds = $this->app->make(FreesoundClient::class)->search('wind', 'ambience', 5);

        $this->assertCount(2, $sounds);
        $this->assertSame([1, 3], array_column($sounds, 'id'));
        $this->assertSame(['Creative Commons 0', 'Attribution'], array_column($sounds, 'license'));
    }

    public function test_fetch_dry_run_prints_the_table_without_downloading(): void
    {
        putenv('COLUMNS=120');

        Http::fake([
            'freesound.org/apiv2/search/text*' => Http::response([
                'results' => [
                    $this->sound(11, 'Attribution', 'Door creak'),
                ],
            ], 200),
        ]);

        $this->artisan('audio:fetch', [
            '--type' => 'sfx',
            '--query' => 'door creak',
            '--limit' => 5,
            '--dry-run' => true,
        ])->assertSuccessful()
            ->expectsOutputToContain('Door creak')
            ->expectsOutputToContain('Simulación');

        Http::assertSentCount(1);
    }

    /**
     * @return array<string, mixed>
     */
    private function sound(int $id, string $license, string $name): array
    {
        return [
            'id' => $id,
            'name' => $name,
            'username' => 'field-recordist',
            'license' => $license,
            'duration' => 8.5,
            'avg_rating' => 4.2,
            'tags' => ['horror', 'night'],
            'url' => 'https://freesound.org/people/field-recordist/sounds/'.$id.'/',
            'previews' => [
                'preview-hq-mp3' => 'https://freesound.org/data/previews/'.$id.'.mp3',
            ],
        ];
    }
}
