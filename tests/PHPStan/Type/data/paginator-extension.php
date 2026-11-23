<?php

declare(strict_types=1);

namespace PaginatorExtension;

use App\Account;
use App\AccountCollection;
use App\ModelWithNonGenericCollection;
use App\ModelWithOnlyValueGenericCollection;
use App\User;
use Hypervel\Pagination\CursorPaginator;
use Hypervel\Pagination\LengthAwarePaginator;
use Hypervel\Pagination\Paginator;
use Hypervel\Support\Collection;

use function PHPStan\Testing\assertType;

function test(): void
{
    assertType('Hypervel\Pagination\LengthAwarePaginator<int, App\User, Hypervel\Database\Eloquent\Collection<int, App\User>>', User::paginate());
    assertType('array<int, App\User>', User::paginate()->all());
    assertType('array<int, App\User>', User::paginate()->items());
    assertType('App\User|null', User::paginate()[0]);

    assertType('Hypervel\Pagination\Paginator<int, App\User, Hypervel\Database\Eloquent\Collection<int, App\User>>', User::simplePaginate());
    assertType('array<int, App\User>', User::simplePaginate()->all());
    assertType('array<int, App\User>', User::simplePaginate()->items());
    assertType('App\User|null', User::simplePaginate()[0]);

    assertType('Hypervel\Pagination\CursorPaginator<int, App\User, Hypervel\Database\Eloquent\Collection<int, App\User>>', User::cursorPaginate());
    assertType('array<int, App\User>', User::cursorPaginate()->all());
    assertType('array<int, App\User>', User::cursorPaginate()->items());
    assertType('App\User|null', User::cursorPaginate()[0]);

    assertType('ArrayIterator<int, App\User>', User::query()->paginate()->getIterator());

    // HasMany
    assertType('Hypervel\Pagination\LengthAwarePaginator<int, App\Account, App\AccountCollection<int, App\Account>>', (new User())->accounts()->paginate());

    // BelongsToMany
    assertType('Hypervel\Pagination\LengthAwarePaginator<int, App\Post&object{pivot: Hypervel\Database\Eloquent\Relations\Pivot}, Hypervel\Database\Eloquent\Collection<int, App\Post&object{pivot: Hypervel\Database\Eloquent\Relations\Pivot}>>', (new User())->posts()->paginate());
}

function paginateCollections(): void
{
    assertType('Hypervel\Database\Eloquent\Collection<int, App\User>', User::query()->paginate()->getCollection());
    assertType('App\AccountCollection<int, App\Account>', Account::query()->paginate()->getCollection());
    assertType('App\NonGenericCollection', ModelWithNonGenericCollection::query()->paginate()->getCollection());
    assertType('App\OnlyValueGenericCollection<App\ModelWithOnlyValueGenericCollection>', ModelWithOnlyValueGenericCollection::query()->paginate()->getCollection());
    assertType('Hypervel\Support\Collection<int, stdClass>', (new LengthAwarePaginator([new \stdClass()], 1, 15))->getCollection());

    assertType('array<int, App\User>', User::query()->paginate()->getCollection()->all());

    $paginator = User::query()->paginate();
    $paginator->setCollection(new AccountCollection(['account' => new Account()]));
    assertType('App\AccountCollection<string, App\Account>', $paginator->getCollection());
    assertType('Hypervel\Pagination\LengthAwarePaginator<string, App\Account, App\AccountCollection<string, App\Account>>', $paginator);
    assertType('App\AccountCollection<string, App\Account>', $paginator->getCollection()->filterByActive());

    // Unlike upstream, the value keeps its literal type: Hypervel keeps Laravel's covariant TValue,
    // which PHPStan does not generalize.
    $paginator->setCollection(new Collection(['name' => 'Taylor']));
    assertType("Hypervel\Support\Collection<string, 'Taylor'>", $paginator->getCollection());

    // through() replaces the values in place, so the stored collection is only known to be a
    // collection of the new values.
    $accounts = Account::query()->paginate();
    $accounts->through(static fn (Account $account): array => ['account' => $account]);
    assertType('Hypervel\Pagination\LengthAwarePaginator<int, array{account: App\Account}, Hypervel\Support\Collection<int, array{account: App\Account}>>', $accounts);
    assertType('Hypervel\Support\Collection<int, array{account: App\Account}>', $accounts->getCollection());
}

function simplePaginateCollections(): void
{
    assertType('Hypervel\Database\Eloquent\Collection<int, App\User>', User::query()->simplePaginate()->getCollection());
    assertType('App\AccountCollection<int, App\Account>', Account::query()->simplePaginate()->getCollection());
    assertType('App\NonGenericCollection', ModelWithNonGenericCollection::query()->simplePaginate()->getCollection());
    assertType('App\OnlyValueGenericCollection<App\ModelWithOnlyValueGenericCollection>', ModelWithOnlyValueGenericCollection::query()->simplePaginate()->getCollection());
    assertType('Hypervel\Support\Collection<int, stdClass>', (new Paginator([new \stdClass()], 15))->getCollection());

    $paginator = User::query()->simplePaginate();
    $paginator->setCollection(new AccountCollection(['account' => new Account()]));
    assertType('App\AccountCollection<string, App\Account>', $paginator->getCollection());
    assertType('Hypervel\Pagination\Paginator<string, App\Account, App\AccountCollection<string, App\Account>>', $paginator);
    assertType('App\AccountCollection<string, App\Account>', $paginator->getCollection()->filterByActive());

    // Unlike upstream, the value keeps its literal type: Hypervel keeps Laravel's covariant TValue,
    // which PHPStan does not generalize.
    $paginator->setCollection(new Collection(['name' => 'Taylor']));
    assertType("Hypervel\Support\Collection<string, 'Taylor'>", $paginator->getCollection());

    $accounts = Account::query()->simplePaginate();
    $accounts->through(static fn (Account $account): array => ['account' => $account]);
    assertType('Hypervel\Pagination\Paginator<int, array{account: App\Account}, Hypervel\Support\Collection<int, array{account: App\Account}>>', $accounts);
    assertType('Hypervel\Support\Collection<int, array{account: App\Account}>', $accounts->getCollection());
}

function cursorPaginateCollections(): void
{
    assertType('Hypervel\Database\Eloquent\Collection<int, App\User>', User::query()->cursorPaginate()->getCollection());
    assertType('App\AccountCollection<int, App\Account>', Account::query()->cursorPaginate()->getCollection());
    assertType('App\NonGenericCollection', ModelWithNonGenericCollection::query()->cursorPaginate()->getCollection());
    assertType('App\OnlyValueGenericCollection<App\ModelWithOnlyValueGenericCollection>', ModelWithOnlyValueGenericCollection::query()->cursorPaginate()->getCollection());
    assertType('Hypervel\Support\Collection<int, stdClass>', (new CursorPaginator([new \stdClass()], 15))->getCollection());

    $paginator = User::query()->cursorPaginate();
    $paginator->setCollection(new AccountCollection(['account' => new Account()]));
    assertType('App\AccountCollection<string, App\Account>', $paginator->getCollection());
    assertType('Hypervel\Pagination\CursorPaginator<string, App\Account, App\AccountCollection<string, App\Account>>', $paginator);
    assertType('App\AccountCollection<string, App\Account>', $paginator->getCollection()->filterByActive());

    // Unlike upstream, the value keeps its literal type: Hypervel keeps Laravel's covariant TValue,
    // which PHPStan does not generalize.
    $paginator->setCollection(new Collection(['name' => 'Taylor']));
    assertType("Hypervel\Support\Collection<string, 'Taylor'>", $paginator->getCollection());

    $accounts = Account::query()->cursorPaginate();
    $accounts->through(static fn (Account $account): array => ['account' => $account]);
    assertType('Hypervel\Pagination\CursorPaginator<int, array{account: App\Account}, Hypervel\Support\Collection<int, array{account: App\Account}>>', $accounts);
    assertType('Hypervel\Support\Collection<int, array{account: App\Account}>', $accounts->getCollection());
}

/**
 * @param LengthAwarePaginator<string, array{id: int}> $arrays
 * @param Paginator<int, int> $integers
 * @param CursorPaginator<string, string> $strings
 * @param LengthAwarePaginator<int, Account|null> $nullable
 * @param CursorPaginator<int, Account|User> $union
 * @param Paginator<string, Account&\Countable> $intersection
 */
function collectionValueTypes(LengthAwarePaginator $arrays, Paginator $integers, CursorPaginator $strings, LengthAwarePaginator $nullable, CursorPaginator $union, Paginator $intersection): void
{
    assertType('Hypervel\Support\Collection<string, array{id: int}>', $arrays->getCollection());
    assertType('Hypervel\Support\Collection<int, int>', $integers->getCollection());
    assertType('Hypervel\Support\Collection<string, string>', $strings->getCollection());
    assertType('App\Account|null', $nullable->getCollection()->first());

    // Unlike upstream, model values do not imply the model's collection: a paginator annotated
    // with two arguments can be built from any collection, so it keeps the base collection.
    assertType('Hypervel\Support\Collection<int, App\Account|App\User>', $union->getCollection());
    assertType('Hypervel\Support\Collection<string, App\Account&Countable>', $intersection->getCollection());
}

/**
 * @extends CursorPaginator<int, Account>
 */
class AccountCursorPaginator extends CursorPaginator
{
}

/**
 * @param array<string, Account> $accounts
 */
function cursorPaginatorKeys(array $accounts): void
{
    // Unlike upstream, the keys are integers whatever the input's keys: setItems() renumbers them.
    $paginator = new CursorPaginator($accounts, 15);
    assertType('Hypervel\Pagination\CursorPaginator<int, App\Account, Hypervel\Support\Collection<(int|string), mixed>>', $paginator);

    $paginator->setCollection(new Collection(['first' => new Account()]));
    assertType('Hypervel\Pagination\CursorPaginator<string, App\Account, Hypervel\Support\Collection<string, App\Account>>', $paginator);

    assertType('PaginatorExtension\AccountCursorPaginator', new AccountCursorPaginator($accounts, 15));
    // Without items to infer the value type from, the declaration's bounds apply.
    assertType('Hypervel\Pagination\CursorPaginator<(int|string), mixed, Hypervel\Support\Collection<(int|string), mixed>>', new CursorPaginator(null, 15));
}

function perPageCallback(): void
{
    assertType('Hypervel\Pagination\LengthAwarePaginator<int, App\User, Hypervel\Database\Eloquent\Collection<int, App\User>>', User::query()->paginate(static function ($total) {
        assertType('int', $total);

        return 15;
    }));
}
