<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Story;
use App\Services\Llm\SpendReport;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SpendReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-08-15 12:00:00'));
    }

    public function test_stories_from_other_months_are_left_out_of_the_current_total(): void
    {
        Story::factory()->create(['llm_cost_usd' => 10.50]);
        Story::factory()->create([
            'llm_cost_usd' => 4.32,
            'created_at' => now()->subMonth(),
        ]);

        $report = $this->app->make(SpendReport::class)->forMonth();

        $this->assertEqualsWithDelta(10.50, $report['usd'], 0.0001);
        $this->assertNotEqualsWithDelta(14.82, $report['usd'], 0.0001);
    }

    public function test_the_displayed_total_uses_a_comma_decimal_and_the_euro_sign(): void
    {
        Story::factory()->create(['llm_cost_usd' => 10.50]);
        Story::factory()->create(['llm_cost_usd' => 4.32]);

        $this->app->make('config')->set('stories.llm.usd_to_eur', 1);

        $report = $this->app->make(SpendReport::class)->forMonth();

        $this->assertSame('14,82 €', $report['euro']);
        $this->assertSame(1, preg_match('/^\d+,\d{2} €$/', $report['euro']));
    }
}
