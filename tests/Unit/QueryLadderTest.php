<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Audio\QueryLadder;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class QueryLadderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Http::preventStrayRequests();
    }

    public function test_door_creak_slow_walks_four_levels_in_order(): void
    {
        $levels = $this->ladder()->levels('door creak slow', ['door', 'creak'], 'door');

        $this->assertSame([
            ['level' => 1, 'query' => 'door creak slow'],
            ['level' => 2, 'query' => 'door creak'],
            ['level' => 3, 'query' => 'door'],
            ['level' => 4, 'query' => 'door creak slow wooden'],
        ], $levels);
    }

    public function test_single_word_query_has_no_duplicate_levels(): void
    {
        $levels = $this->ladder()->levels('door', ['door'], 'door');
        $queries = array_map(static fn (array $step): string => mb_strtolower($step['query']), $levels);

        $this->assertSame(['door', 'door creak slow wooden'], $queries);
        $this->assertSame($queries, array_values(array_unique($queries)));
        $this->assertSame(1, $levels[0]['level']);
        $this->assertSame(4, $levels[array_key_last($levels)]['level']);
    }

    public function test_unresolved_category_yields_three_levels(): void
    {
        $levels = $this->ladder()->levels('door creak slow', ['door', 'creak'], null);

        $this->assertSame([
            ['level' => 1, 'query' => 'door creak slow'],
            ['level' => 2, 'query' => 'door creak'],
            ['level' => 3, 'query' => 'door'],
        ], $levels);
        $this->assertSame([1, 2, 3], array_column($levels, 'level'));
    }

    public function test_drops_identical_levels_after_stripping_modifiers(): void
    {
        $levels = $this->ladder()->levels('door creak', ['door', 'creak'], 'door');

        $this->assertSame([
            ['level' => 1, 'query' => 'door creak'],
            ['level' => 3, 'query' => 'door'],
            ['level' => 4, 'query' => 'door creak slow wooden'],
        ], $levels);
    }

    public function test_core_prefers_the_first_relevant_noun_from_the_query(): void
    {
        $levels = $this->ladder()->levels('footsteps wooden floor', ['footsteps', 'wooden'], 'footsteps_hard');

        $byLevel = array_column($levels, 'query', 'level');

        $this->assertSame('footsteps', $byLevel[3]);
    }

    private function ladder(): QueryLadder
    {
        return $this->app->make(QueryLadder::class);
    }
}
