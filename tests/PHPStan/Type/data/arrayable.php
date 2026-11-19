<?php

namespace ArrayableTypes;

use App\User;
use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Pagination\Paginator;
use Hypervel\Support\Collection;

use function PHPStan\Testing\assertType;

// Hypervel: Arrayable takes the array type as its single template argument, so upstream's Laravel 13
// Arrayable<TKey, TValue> annotations are written as Arrayable<array<TKey, TValue>>.

/** @implements Arrayable<array{score: int, label?: string}> */
final class Scores implements Arrayable
{
    public function toArray(): array
    {
        return ['score' => 42];
    }
}

// REMOVED: LegacyScores, upgrade() and compatible(); they cover normalizing the Laravel 13 form, which Hypervel's contract does not accept.

/**
 * @param Arrayable<array<string, int>> $keyed
 * @param Arrayable<array{score: int, label?: string}> $shaped
 * @param Arrayable<list<string>> $list
 */
function types(Arrayable $keyed, Arrayable $shaped, Arrayable $list): void
{
    assertType('array<string, int>', $keyed->toArray());
    assertType('array{score: int, label?: string}', $shaped->toArray());
    assertType('list<string>', $list->toArray());
    assertType('array{score: int, label?: string}', (new Scores())->toArray());
    assertType('Hypervel\Support\Collection<string, int>', collect($keyed));
    assertType('Hypervel\Support\Collection<string, int|string>', collect($shaped));
    assertType('Hypervel\Support\Collection<int, string>', collect($list));
    assertType('Hypervel\Support\Collection<string, int>', new Collection($keyed));
    assertType('Hypervel\Support\Collection<string, int|string>', new Collection($shaped));
    // Unlike upstream, combine() keys the result by this collection's values when they are array keys.
    assertType('Hypervel\Support\Collection<string, int>', collect(['score'])->combine($keyed));
}

// REMOVED: the Arrayable<*, int> and Arrayable<string, *> boundaries; they use the Laravel 13 form.

/**
 * @param Arrayable $untyped
 * @param Arrayable<array{score: int}>|null $nullable
 */
function boundaries(Arrayable $untyped, ?Arrayable $nullable): void
{
    assertType('array<mixed>', $untyped->toArray());
    assertType('array{score: int}|null', $nullable?->toArray());
    assertType('array<int, mixed>', collect([new Scores()])->toArray());
    // Unlike upstream, a paginator's array is keyed by its metadata names.
    assertType('array<string, mixed>', (new Paginator([new Scores()], 10))->toArray());
    $objects = collect([new Scores()]);
    assertType('Hypervel\Support\Collection<int, ArrayableTypes\Scores>', collect($objects));
    assertType('Hypervel\Support\Collection<int, ArrayableTypes\Scores>', new Collection($objects));
    assertType('Hypervel\Support\Collection<int, ArrayableTypes\Scores>', Collection::make($objects));
}

/** @implements Arrayable<array<string, int>> */
final class Counts implements Arrayable
{
    public function toArray(): array
    {
        return ['total' => 1];
    }
}

/** @implements Arrayable<array<string, int>> */
final class Totals implements Arrayable
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
function implementations(Counts $counts, Totals $totals, Arrayable $arrayable, Collection $users): void
{
    assertType('array<string, int>', $counts->toArray());
    assertType('array{total: int}', $totals->toArray());
    assertType('array<int, string>', $arrayable->toArray());
    assertType('array<int, mixed>', $users->toArray());
}
