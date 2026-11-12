<?php

namespace Arrayable;

use App\User;
use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Support\Collection;

use function PHPStan\Testing\assertType;

// Hypervel: Arrayable takes the array type as its single template argument.

/** @implements Arrayable<array<string, int>> */
class Counts implements Arrayable
{
    public function toArray(): array
    {
        return ['total' => 1];
    }
}

/** @implements Arrayable<array<string, int>> */
class Totals implements Arrayable
{
    /** @return array{total: int} */
    public function toArray(): array
    {
        return ['total' => 1];
    }
}

/**
 * @param Arrayable<array<int, string>> $arrayable
 * @param Collection<int, User> $users
 */
function test(Counts $counts, Totals $totals, Arrayable $arrayable, Collection $users): void
{
    assertType('array<string, int>', $counts->toArray());
    assertType('array{total: int}', $totals->toArray());
    assertType('array<int, string>', $arrayable->toArray());
    assertType('array<int, mixed>', $users->toArray());
}
