<?php

namespace CollectionMake;

use App\User;
use Hypervel\Database\Eloquent\Collection as EloquentCollection;
use Hypervel\Support\Collection as SupportCollection;
use Traversable;

use function PHPStan\Testing\assertType;

/**
 * @param EloquentCollection<int, \App\User> $users
 * @param Traversable<int, int> $traversable
 */
function test(EloquentCollection $users, Traversable $traversable): void
{
    // assertType('Hypervel\Support\Collection<int|string, mixed>', SupportCollection::make([]));
    assertType('Hypervel\Support\Collection<int, int>', SupportCollection::make([1, 2, 3]));
    assertType('Hypervel\Support\Collection<int, string>', SupportCollection::make(['foo', 'bar', 'baz']));
    assertType('Hypervel\Support\Collection<int, float>', SupportCollection::make([1.0, 2.0, 3.0]));
    assertType('Hypervel\Support\Collection<int, float|int|string>', SupportCollection::make([1, 'foo', 1.0]));

    assertType('Hypervel\Support\Collection<int, App\User>', SupportCollection::make($users));

    /** @var EloquentCollection<int, User> $users */
    assertType('Hypervel\Support\Collection<int, App\User>', SupportCollection::make($users));

    assertType('Hypervel\Support\Collection<int, int>', SupportCollection::make($traversable));
}
