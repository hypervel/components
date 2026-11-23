<?php

namespace CollectionReject;

use App\Account;
use App\User;
use Hypervel\Database\Eloquent\Collection as EloquentCollection;
use Hypervel\Support\Collection as SupportCollection;

use function PHPStan\Testing\assertType;

function convertToAccount(User $user): ?Account
{ }

function dummyReject($value)
{
    if ($value instanceof User) {
        return true;
    }

    return random_int(0, 1) > 1;
}

/** @param EloquentCollection<int, User> $users */
function test(User $user, SupportCollection $users): void
{
    assertType("Hypervel\Support\Collection<(int|string), 0|0.0|''|'0'|array{}|false|null>", collect()->reject());

    assertType("Hypervel\Support\Collection<int, ''|'0'|null>", collect(['foo', null, '', 'bar', null])->reject());

    assertType('Hypervel\Support\Collection<int, int<min, 2>>', collect([1, 2, 3, 4, 5, 6])->reject(function (int $value) {
        return $value > 2;
    }));
    assertType('Hypervel\Support\Collection<int, int<min, 2>>', collect([1, 2, 3, 4, 5, 6])->reject(fn (int $value) => $value > 2));

    assertType("Hypervel\Database\Eloquent\Collection<int, App\User>", $users->reject(function (User $user): bool {
        return ! $user->blocked;
    }));
    assertType("Hypervel\Database\Eloquent\Collection<int, App\User>", $users->reject(fn (User $user) => ! $user->blocked));

    assertType(
        'Hypervel\Support\Collection<int, null>',
        collect($users->all())
        ->map(function (User $attachment): ?Account {
            return convertToAccount($attachment);
        })
        ->reject()
    );

    $accounts = $user->accounts()->active()->get();
    assertType('App\AccountCollection<int, App\Account>', $accounts);

    // Hypervel: calls this file's helper; upstream calls a CollectionStubs function that does not exist.
    assertType('App\AccountCollection<int, App\Account>', $accounts->reject(function ($account) {
        return dummyReject($account);
    }));

    $accounts->reject(function ($account) {
        return dummyReject($account);
    })
    ->map(function ($account) {
        assertType('App\Account', $account);
    });
}
