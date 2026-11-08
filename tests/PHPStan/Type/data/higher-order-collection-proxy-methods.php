<?php

namespace HigherOrderCollectionProxyMethods;

use App\Importer;
use App\ModelWithOnlyValueGenericCollection;
use App\NonGenericCollection;
use App\OnlyValueGenericCollection;
use App\Transaction;
use App\TransactionCollection;
use App\User;
use Hypervel\Database\Eloquent\Collection as EloquentCollection;
use Hypervel\Support\Collection as SupportCollection;

use function PHPStan\Testing\assertType;

/**
 * @param  EloquentCollection<int, User>  $users
 * @param  SupportCollection<int, Importer>  $collection
 * @param  SupportCollection<int, User>  $supportCollectionWithModels
 * @param  OnlyValueGenericCollection<ModelWithOnlyValueGenericCollection>  $onlyValueGenericCollection
 * @param  TransactionCollection<int, Transaction>  $transactionCollection
 */
function test(
    EloquentCollection $users,
    User $user,
    SupportCollection $collection,
    SupportCollection $supportCollectionWithModels,
    NonGenericCollection $nonGenericCollection,
    OnlyValueGenericCollection $onlyValueGenericCollection,
    TransactionCollection $transactionCollection,
): void {
    // Unlike upstream, these follow Hypervel's collection types: averages may be integers, an empty
    // collection has no maximum or minimum, an integer sum can overflow to a float, map() keeps the
    // collection's keys, and partition() returns the base collection keyed 0 and 1.
    assertType('float|int', $users->avg->id() + $users->average->id());
    assertType('bool', $users->contains->isActive());
    assertType('Hypervel\Database\Eloquent\Collection<int, App\User>', $users->each->delete());
    assertType('bool', $users->every->isActive());
    assertType('Hypervel\Database\Eloquent\Collection<int, App\User>', $users->filter->isActive());
    assertType('App\User|null', $users->first->isActive());
    assertType('Hypervel\Support\Collection<(int|string), mixed>', $users->flatMap->isActive());
    assertType('Hypervel\Database\Eloquent\Collection<int, Hypervel\Database\Eloquent\Collection<int, App\User>>', $users->groupBy->isActive());
    assertType('Hypervel\Database\Eloquent\Collection<string, Hypervel\Database\Eloquent\Collection<int, App\User>>', $users->groupBy->getAllCapsName());
    assertType('Hypervel\Database\Eloquent\Collection<(int|string), App\User>', $users->keyBy->isActive());
    assertType('Hypervel\Support\Collection<int, bool>', $users->map->isActive());
    assertType('Hypervel\Support\Collection<int, Hypervel\Database\Eloquent\Relations\HasMany<App\Account, App\User>>', $users->map->accounts());
    assertType('Hypervel\Support\Collection<int, int>', $users->map->id());
//    assertType('array<int, array>', $user->accounts->map->getAttributes()->all());
    assertType('int|null', $users->max->id());
    assertType('int|null', $users->min->id());
    assertType('Hypervel\Support\Collection<int<0, 1>, Hypervel\Database\Eloquent\Collection<int, App\User>>', $users->partition->isActive());
    assertType('Hypervel\Database\Eloquent\Collection<int, App\User>', $users->reject->isActive());
    assertType('Hypervel\Database\Eloquent\Collection<int, App\User>', $users->skipUntil->isActive());
    assertType('Hypervel\Database\Eloquent\Collection<int, App\User>', $users->skipWhile->isActive());
    assertType('float|int', $users->sum->id());
    assertType('Hypervel\Database\Eloquent\Collection<int, App\User>', $users->takeUntil->isActive());
    assertType('Hypervel\Database\Eloquent\Collection<int, App\User>', $users->takeWhile->isActive());
    assertType('Hypervel\Database\Eloquent\Collection<int, App\User>', $users->unique->isActive());
    assertType('Hypervel\Support\Collection<int, bool>', $collection->map->import());
    assertType('Hypervel\Support\Collection<int, App\Importer>', $collection->each->import());
    assertType('Hypervel\Support\Collection<int, App\Importer>', $collection->filter->isImported());

    // Unlike upstream, ids read int on the SQLite test connection, dates read CarbonInterface, and
    // User::email() is an accessor Eloquent applies, so email reads as its untyped callback.
    assertType('float|int', $users->avg->id + $users->average->id);
    assertType('bool', $users->contains->email);
    assertType('bool', $users->every->email);
    assertType('Hypervel\Database\Eloquent\Collection<int, App\User>', $users->each->email);
    assertType('Hypervel\Database\Eloquent\Collection<int, App\User>', $users->filter->email);
    assertType('Hypervel\Support\Collection<(int|string), mixed>', $users->flatMap->email);
    assertType('App\User|null', $users->first->email);
    assertType('Hypervel\Database\Eloquent\Collection<(int|string), Hypervel\Database\Eloquent\Collection<int, App\User>>', $users->groupBy->email);
    assertType('Hypervel\Database\Eloquent\Collection<int, Hypervel\Database\Eloquent\Collection<int, App\User>>', $users->groupBy->id);
    assertType('Hypervel\Database\Eloquent\Collection<(int|string), App\User>', $users->keyBy->email);
    assertType('Hypervel\Support\Collection<int, mixed>', $users->map->email);
    assertType('Hypervel\Support\Collection<int, int>', $users->map->id);
    assertType('Hypervel\Support\Collection<(int|string), int<0, max>>', $users->groupBy('status')->map->count());
    assertType('Hypervel\Support\Collection<int, Carbon\CarbonInterface|null>', $users->map->created_at);
    assertType('mixed', $users->max->email);
    assertType('int|null', $users->max->id);
    assertType('mixed', $users->min->email);
    assertType('int|null', $users->min->id);
    assertType('Hypervel\Support\Collection<int<0, 1>, Hypervel\Database\Eloquent\Collection<int, App\User>>', $users->partition->email);
    assertType('Hypervel\Database\Eloquent\Collection<int, App\User>', $users->reject->email);
    assertType('Hypervel\Database\Eloquent\Collection<int, App\User>', $users->skipUntil->email);
    assertType('Hypervel\Database\Eloquent\Collection<int, App\User>', $users->skipWhile->email);
    assertType('float|int', $users->sum->id);
    assertType('float|int', $users->sum->unsigned_integer);
    assertType('Hypervel\Database\Eloquent\Collection<int, App\User>', $users->takeUntil->email);
    assertType('Hypervel\Database\Eloquent\Collection<int, App\User>', $users->takeWhile->email);

    assertType('Hypervel\Support\Collection<int, App\User>', $supportCollectionWithModels->reject->isActive());

    // Custom collections
    assertType('App\NonGenericCollection', $nonGenericCollection->each->count());
    assertType('App\NonGenericCollection', $nonGenericCollection->reject->id);
    assertType('App\OnlyValueGenericCollection<App\ModelWithOnlyValueGenericCollection>', $onlyValueGenericCollection->each->count());
    assertType('App\OnlyValueGenericCollection<App\ModelWithOnlyValueGenericCollection>', $onlyValueGenericCollection->reject->id);
    assertType('App\TransactionCollection<int, App\Transaction>', $transactionCollection->each->count());
    assertType('App\TransactionCollection<int, App\Transaction>', $transactionCollection->reject->id);
}
