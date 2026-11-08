<?php

namespace BleedingEdgeCollectionUsageInference;

use App\User;
use Hypervel\Support\Collection;

use function PHPStan\Testing\assertType;

/** @param Collection<int, int> $ints */
function takeInts(Collection $ints): void
{
}

/**
 * @param iterable<User>          $users
 * @param Collection<int, string> $names
 */
function emptyCollections(iterable $users, Collection $names, bool $flag): void
{
    $sent = collect();
    assertType('Hypervel\Support\Collection<int, int>', $sent);
    takeInts($sent);

    $sentEmptyArray = collect([]);
    assertType('Hypervel\Support\Collection<int, int>', $sentEmptyArray);
    takeInts($sentEmptyArray);

    $pushed = collect();
    foreach ($users as $user) {
        $pushed->push($user->name);
    }

    assertType('Hypervel\Support\Collection<int, string>', $pushed);

    $put = collect([]);
    $put->put('a', 1);
    $put->put('b', 2);
    assertType("Hypervel\Support\Collection<'a'|'b', 1|2>", $put);

    assertType('Hypervel\Support\Collection<int, string>', collect()->merge($names));
    assertType('Hypervel\Support\Collection<int, string>', $flag ? $names : collect());
}

function queries(int $id, string $role): void
{
    // REMOVED: contains(), partition(), some() and reject() value widening. Upstream's stubs redeclare
    // TValue invariant; Hypervel keeps Laravel's covariant TValue, which PHPStan 2.3.5 does not widen
    // to a queried value.
    $ids = collect([1, 2, 3]);
    assertType('bool', $ids->contains($id));

    $roles = collect(['admin', 'editor']);
    assertType('bool', $roles->contains($role));
    assertType("Hypervel\Support\Collection<0|1, 'admin'|'editor'>", $roles);
}
