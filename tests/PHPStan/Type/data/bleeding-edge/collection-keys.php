<?php

namespace BleedingEdgeCollectionKeys;

use App\User;
use Hypervel\Database\Eloquent\Collection as EloquentCollection;
use Hypervel\Support\Collection;
use Hypervel\Support\LazyCollection;

use function PHPStan\Testing\assertType;

/**
 * @param list<int>          $list
 * @param array<string, int> $map
 * @param array<mixed>       $mixed
 */
function renumberedKeys(array $list, array $map, array $mixed, User $user): void
{
    assertType('Hypervel\Support\Collection<0|1|2, 1|2|3>', collect([1, 2, 3]));

    assertType('Hypervel\Support\Collection<int, 1|2|3|4|5>', collect([1, 2, 3])->merge([4, 5]));
    assertType('Hypervel\Support\Collection<int, int>', collect([1, 2, 3])->merge($list));
    assertType("Hypervel\Support\Collection<'a'|'b'|'c', 1|2|3>", collect(['a' => 1, 'b' => 2])->merge(['c' => 3]));
    assertType('Hypervel\Support\Collection<string, int>', collect(['a' => 1, 'b' => 2])->merge($map));
    assertType("Hypervel\Support\Collection<'a'|'b'|int, 1|2|4>", collect(['a' => 1, 'b' => 2])->merge([4]));
    assertType("Hypervel\Support\Collection<int, 'x'|'y'|'z'>", collect([5 => 'x', 9 => 'y'])->merge([1 => 'z']));
    assertType('Hypervel\Support\Collection<(int|string), mixed>', collect($mixed)->merge($mixed));
    assertType('Hypervel\Support\Collection<int, 1|2|3|4>', collect([1, 2, 3])->mergeRecursive([4]));

    assertType('Hypervel\Support\Collection<int, 1|2|3|4|5>', collect([1, 2, 3])->concat([4, 5]));
    assertType("Hypervel\Support\Collection<'a'|'b'|int, 1|2|3>", collect(['a' => 1, 'b' => 2])->concat(['c' => 3]));

    assertType('Hypervel\Support\Collection<int, 1|2|3>', collect([1, 2, 3])->flatMap(fn (int $value) => [$value, $value]));
    assertType("Hypervel\Support\Collection<'k1'|'k2'|'k3', 1|2|3>", collect([1, 2, 3])->flatMap(fn (int $value) => ['k' . $value => $value]));

    assertType('Hypervel\Support\Collection<int, 1|2|3>', collect([1, 2, 3])->multiply(2));
    assertType("Hypervel\Support\Collection<int, 'x'|'y'>", collect([5 => 'x', 9 => 'y'])->shuffle());
    assertType("Hypervel\Support\Collection<int, Hypervel\Support\Collection<int, 'x'|'y'>>", collect([5 => 'x', 9 => 'y'])->split(2));
    assertType("Hypervel\Support\Collection<int, Hypervel\Support\Collection<'a'|'b', 1|2>>", collect(['a' => 1, 'b' => 2])->split(2));

    assertType('Hypervel\Support\LazyCollection<int, 1|2|3|4|5>', LazyCollection::make([1, 2, 3])->merge([4, 5]));
    assertType('Hypervel\Support\LazyCollection<int, 1|2|3|4|5>', LazyCollection::make([1, 2, 3])->concat([4, 5]));
    assertType('Hypervel\Support\LazyCollection<int, 1|2|3>', LazyCollection::make([1, 2, 3])->flatMap(fn (int $value) => [$value, $value]));
    assertType('Hypervel\Support\LazyCollection<int, 1|2|3>', LazyCollection::make([1, 2, 3])->multiply(2));

    assertType('Hypervel\Database\Eloquent\Collection<0, App\User>', new EloquentCollection([$user]));
    assertType('Hypervel\Database\Eloquent\Collection<int, App\User>', (new EloquentCollection([$user]))->merge([$user]));
    assertType('Hypervel\Database\Eloquent\Collection<int, App\User>', (new EloquentCollection([$user]))->concat([$user]));
    // Hypervel: the callback returns arrays, so Eloquent's map() and collapse() return a base collection.
    assertType('Hypervel\Support\Collection<int, App\User>', (new EloquentCollection([$user]))->flatMap(fn (User $user) => [$user, $user]));
}

function mutations(): void
{
    // REMOVED: push(), add(), prepend() and unshift() value widening. Hypervel keeps Laravel's covariant
    // TValue, and PHPStan 2.3.5 does not widen an already inferred covariant TValue to a new value.

    // Hypervel: prepends a value TValue already admits, so only the key widens.
    $prependedWithKey = collect(['a' => 1]);
    $prependedWithKey->prepend(1, 'b');
    assertType("Hypervel\Support\Collection<'a'|'b', 1>", $prependedWithKey);

    $shifted = collect([5 => 'x', 9 => 'y']);
    $shifted->shift();
    assertType("Hypervel\Support\Collection<int, 'x'|'y'>", $shifted);

    $shiftedStringKeys = collect(['a' => 1, 'b' => 2]);
    $shiftedStringKeys->shift();
    assertType("Hypervel\Support\Collection<'a'|'b', 1|2>", $shiftedStringKeys);

    $spliced = collect([5 => 'x', 9 => 'y']);
    assertType("Hypervel\Support\Collection<int, 'x'|'y'>", $spliced->splice(1));
    assertType("Hypervel\Support\Collection<int, 'x'|'y'>", $spliced);

    $put = collect(['a' => 1, 'b' => 2]);
    $put->getOrPut('c', 3);
    assertType("Hypervel\Support\Collection<'a'|'b'|'c', 1|2|3>", $put);
}
