<?php

namespace CollectionHelper;

use App\User;
use Hypervel\Database\Eloquent\Collection as EloquentCollection;
use Traversable;

use function PHPStan\Testing\assertType;

/**
 * @param EloquentCollection<int, int> $ints
 * @param EloquentCollection<int, User> $users
 * @param Traversable<int, int> $traversable
 */
function test(
    EloquentCollection $ints,
    EloquentCollection $users,
    Traversable $traversable
): void {
    assertType('Hypervel\Support\Collection<(int|string), mixed>', collect());
    assertType('Hypervel\Support\Collection<0, 1>', collect(1));
    assertType('Hypervel\Support\Collection<0, \'foo\'>', collect('foo'));
    assertType('Hypervel\Support\Collection<0, 3.14>', collect(3.14));
    assertType('Hypervel\Support\Collection<0, true>', collect(true));
    assertType('Hypervel\Support\Collection<(int|string), mixed>', collect([]));
    assertType('Hypervel\Support\Collection<int, int>', collect([1, 2, 3]));
    assertType('Hypervel\Support\Collection<int, string>', collect(['foo', 'bar', 'baz']));
    assertType('Hypervel\Support\Collection<int, float>', collect([1.0, 2.0, 3.0]));
    assertType('Hypervel\Support\Collection<int, float|int|string>', collect([1, 'foo', 1.0]));
    assertType("Hypervel\Support\Collection<int, array{string, string, string}>", collect([['a', 'b', 'c']]));
    assertType("Hypervel\Support\Collection<int, array{string, string, string}>", collect([['a', 'b', 'c']])->push(array_fill(0, 3, 'x')));
    assertType("Hypervel\Support\Collection<int, App\User>", collect([new User, new User]));
    assertType("Hypervel\Support\Collection<int, array{App\User, App\User, App\User}>", collect([[new User, new User, new User]]));
    assertType('Hypervel\Support\Collection<int, int>', collect($ints));
    assertType('Hypervel\Support\Collection<int, App\User>', collect($users));
    assertType('Hypervel\Support\Collection<int, int>', collect($traversable));
}
