<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Audio\QueryLadder;
use Tests\TestCase;

final class QueryLadderTest extends TestCase
{
    public function test_returns_four_distinct_levels_for_a_modified_query(): void
    {
        $levels = $this->ladder()->levels('door creak slow', ['door', 'creak'], 'door');

        $this->assertSame([
            ['level' => 1, 'query' => 'door creak slow'],
            ['level' => 2, 'query' => 'door creak'],
            ['level' => 3, 'query' => 'door'],
            ['level' => 4, 'query' => 'door creak slow wooden'],
        ], $levels);
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

    public function test_omits_the_curated_query_when_the_category_is_null(): void
    {
        $levels = $this->ladder()->levels('door creak slow', ['door', 'creak'], null);

        $this->assertSame([
            ['level' => 1, 'query' => 'door creak slow'],
            ['level' => 2, 'query' => 'door creak'],
            ['level' => 3, 'query' => 'door'],
        ], $levels);
        $this->assertSame([1, 2, 3], array_column($levels, 'level'));
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
