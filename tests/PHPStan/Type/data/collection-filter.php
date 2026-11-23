<?php

namespace CollectionFilter;

use App\Account;
use App\User;
use Hypervel\Database\Eloquent\Collection as EloquentCollection;
use Hypervel\Support\Collection as SupportCollection;

use function PHPStan\Testing\assertType;

function convertToAccount(User $user): ?Account
{ }

function dummyFilter($value)
{
    if ($value instanceof User) {
        return true;
    }

    return random_int(0, 1) > 1;
}

/**
 * @param EloquentCollection<int, User> $users
 * @param SupportCollection<array-key, mixed> $mixedCollection
 */
function test(User $user, SupportCollection $users, SupportCollection $mixedCollection): void
{
    assertType("Hypervel\Support\Collection<(int|string), mixed~(0|0.0|''|'0'|array{}|false|null)>", collect()->filter());

    assertType('Hypervel\Support\Collection<int, non-falsy-string>', collect(['foo', null, '', 'bar', null])->filter());

    assertType('Hypervel\Support\Collection<int, int<3, max>>', collect([1, 2, 3, 4, 5, 6])->filter(function (int $value) {
        return $value > 2;
    }));
    assertType('Hypervel\Support\Collection<int, int<3, max>>', collect([1, 2, 3, 4, 5, 6])->filter(fn (int $value) => $value > 2));

    assertType("Hypervel\Database\Eloquent\Collection<int, App\User>", $users->filter(function (User $user): bool {
        return ! $user->blocked;
    }));
    assertType("Hypervel\Database\Eloquent\Collection<int, App\User>", $users->filter(fn (User $user) => ! $user->blocked));

    assertType(
        'Hypervel\Support\Collection<int, App\Account>',
        collect($users->all())
        ->map(function (User $attachment): ?Account {
            return convertToAccount($attachment);
        })
        ->filter()
    );

    $accounts = $user->accounts()->active()->get();
    assertType('App\AccountCollection<int, App\Account>', $accounts);

    // Hypervel: calls this file's helper; upstream calls a CollectionStubs function that does not exist.
    assertType('App\AccountCollection<int, App\Account>', $accounts->filter(function ($account) {
        return dummyFilter($account);
    }));

    $accounts->filter(function ($account) {
        return dummyFilter($account);
    })
    ->map(function ($account) {
        assertType('App\Account', $account);
    });

    assertType("Hypervel\Support\Collection<(int|string), mixed~(0|0.0|''|'0'|array{}|false|null)>", $mixedCollection->pluck('foo')->filter());
}
