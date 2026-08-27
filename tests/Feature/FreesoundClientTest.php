<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\FreesoundException;
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

    public function test_a_failed_request_still_arms_the_rate_limit(): void
    {
        Http::fake([
            'freesound.org/apiv2/search/text*' => Http::sequence()
                ->push(['detail' => 'boom'], 500)
                ->push(['results' => []], 200),
        ]);

        $client = $this->app->make(FreesoundClient::class);

        try {
            $client->search('wind', 'ambience', 5);
            $this->fail('La primera búsqueda debía fallar con HTTP 500.');
        } catch (FreesoundException) {
            // Esperado: lo que se comprueba es que la siguiente petición sigue esperando.
        }

        $client->search('wind', 'ambience', 5);

        Sleep::assertSleptTimes(1);
    }

    public function test_the_preview_url_scheme_is_validated(): void
    {
        $this->expectException(FreesoundException::class);
        $this->expectExceptionMessage('no es http ni https');

        $this->app->make(FreesoundClient::class)->downloadPreview('file:///etc/passwd');
    }

    public function test_the_token_never_leaves_the_freesound_hosts(): void
    {
        Http::fake([
            'evil.example.com/*' => Http::response('bytes', 200),
        ]);

        $this->app->make(FreesoundClient::class)->downloadPreview('https://evil.example.com/preview.mp3');

        Http::assertSent(static fn ($request): bool => ! $request->hasHeader('Authorization'));
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
