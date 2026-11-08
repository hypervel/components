<?php

namespace CollectionGenericStaticMethods;

use App\Transaction;
use App\TransactionCollection;
use App\User;
use App\UserCollection;
use Hypervel\Database\Eloquent\Collection as EloquentCollection;

use function PHPStan\Testing\assertType;

/**
 * @param EloquentCollection<int, User>           $collection
 * @param TransactionCollection<int, Transaction> $customEloquentCollection
 */
function test(
    EloquentCollection $collection,
    TransactionCollection $customEloquentCollection,
    UserCollection $secondCustomEloquentCollection,
): void {
    assertType('Hypervel\Support\Collection<int<0, 1>, Hypervel\Database\Eloquent\Collection<int, App\User>>', $collection->partition('foo'));
    assertType('Hypervel\Support\Collection<int<0, 1>, App\TransactionCollection<int, App\Transaction>>', $customEloquentCollection->partition('foo'));
    assertType('Hypervel\Support\Collection<int<0, 1>, App\UserCollection>', $secondCustomEloquentCollection->partition('foo'));
}
