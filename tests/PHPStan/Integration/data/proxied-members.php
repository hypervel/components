<?php

namespace ProxiedMembers;

use Hypervel\Support\Collection;

class Score
{
    protected int $rank = 0;

    public function points(): int
    {
        return $this->secret();
    }

    private function secret(): int
    {
        return $this->rank;
    }
}

/**
 * @param Collection<int, Score> $scores
 *
 * @return list<mixed>
 */
function test(Collection $scores): array
{
    return [
        $scores->map->secret(),
        $scores->map->rank,
    ];
}
