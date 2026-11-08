<?php

namespace CollectionGenericStaticMethods;

use App\Transaction;
use App\TransactionCollection;
use App\User;
use App\UserCollection;
use Hypervel\Database\Eloquent\Collection as EloquentCollection;
use Hypervel\Support\Collection as SupportCollection;
use Hypervel\Support\Enumerable;
use Hypervel\Support\LazyCollection;

use function PHPStan\Testing\assertType;

/**
 * @param EloquentCollection<int, User>           $collection
 * @param SupportCollection<string, int>          $items
 * @param TransactionCollection<int, Transaction> $customEloquentCollection
 * @param UserCollection                          $secondCustomEloquentCollection
 * @param LazyCollection<int, User>               $lazyCollection
 * @param User                                    $user
 * @param Enumerable<int, User>                   $enumerableIntUsers
 * @param Enumerable<string, User>                $enumerableStringUsers
 */
function test(
    EloquentCollection $collection, SupportCollection $items,
    TransactionCollection $customEloquentCollection, UserCollection $secondCustomEloquentCollection,
    LazyCollection $lazyCollection, User $user,
    Enumerable $enumerableIntUsers, Enumerable $enumerableStringUsers
): void {
    assertType('Hypervel\Database\Eloquent\Collection<int, int>', EloquentCollection::range(1, 10));
    assertType('Hypervel\Support\LazyCollection<int, int>', LazyCollection::range(1, 10));

    assertType('Hypervel\Support\Collection<int, mixed>', $collection->collapse());
    assertType('Hypervel\Support\Collection<int, mixed>', $items->collapse());

    assertType('Hypervel\Database\Eloquent\Collection<int, array<int, App\User|int>>', $collection->crossJoin([1]));

    assertType('Hypervel\Database\Eloquent\Collection<int, App\User>', $collection->find($items));
    assertType('Hypervel\Database\Eloquent\Collection<int, App\User>', $collection->find([1]));
    assertType('App\User|null', $collection->find($user));
    assertType('App\User|null', $collection->find(1));
    assertType('App\User|false', $collection->find(1, false));

    assertType('App\TransactionCollection<int, App\Transaction>', $customEloquentCollection->find($items));
    assertType('App\TransactionCollection<int, App\Transaction>', $customEloquentCollection->find([1]));
    assertType('App\Transaction|null', $customEloquentCollection->find($user));
    assertType('App\Transaction|null', $customEloquentCollection->find(1));
    assertType('App\Transaction|false', $customEloquentCollection->find(1, false));

    assertType('App\UserCollection', $secondCustomEloquentCollection->find($items));
    assertType('App\UserCollection', $secondCustomEloquentCollection->find([1]));
    assertType('App\User|null', $secondCustomEloquentCollection->find($user));
    assertType('App\User|null', $secondCustomEloquentCollection->find(1));
    assertType('App\User|false', $secondCustomEloquentCollection->find(1, false));

    assertType('Hypervel\Support\Collection<int, mixed>', $collection->flatten());
    assertType('Hypervel\Support\Collection<int, mixed>', $items->flatten());

    // Hypervel: flip() keys are array keys: values that cannot be keys are skipped, and numeric strings become integers.
    assertType('Hypervel\Support\Collection<(int|string), int>', $collection->flip());
    assertType('Hypervel\Support\Collection<(int|string), string>', $items->flip());

    assertType('Hypervel\Database\Eloquent\Collection<(int|string), Hypervel\Database\Eloquent\Collection<int, App\User>>', $collection->groupBy('id'));
    assertType('Hypervel\Support\Collection<(int|string), Hypervel\Support\Collection<int, int>>', $items->groupBy('id'));

    assertType('Hypervel\Database\Eloquent\Collection<string, App\User>', $collection->keyBy(fn (User $user, int $key): string => $user->email));

    assertType('Hypervel\Support\Collection<int, int>', $collection->keys());
    assertType('Hypervel\Support\Collection<int, string>', $items->keys());

    assertType('Hypervel\Support\Collection<(int|string), mixed>', $collection->pluck(['email']));
    assertType('Hypervel\Support\Collection<(int|string), mixed>', $items->pluck('1'));

    assertType('Hypervel\Support\Collection<int, int>', $customEloquentCollection->map(fn (Transaction $transaction): int => $transaction->id));
    // Hypervel: $user->id is int here and below, from the incrementing key's implicit cast.
    assertType('Hypervel\Support\Collection<int, int>', $secondCustomEloquentCollection->map(fn (User $user): int => $user->id));
    assertType('Hypervel\Support\Collection<int, int>', $collection->map(fn (User $user): int => $user->id));
    assertType('Hypervel\Support\Collection<int, App\User|null>', $collection->map(fn (User $user): ?User => $user->id > 5 ? $user : null));
    assertType('Hypervel\Support\Collection<string, int>', $items->map(fn (int $value, string $key): int => $value));

    assertType('App\TransactionCollection<int, App\Transaction>', $customEloquentCollection->map(fn (Transaction $transaction): Transaction => $transaction));
    assertType('App\UserCollection', $secondCustomEloquentCollection->map(fn (User $user): User => $user));
    assertType('Hypervel\Database\Eloquent\Collection<int, App\User>', $collection->map(fn (User $user): User => $user));

    assertType('Hypervel\Database\Eloquent\Collection<int, array<int, int>>', $collection->mapToDictionary(fn (User $u) => [$u->id => $u->id]));
    assertType('App\TransactionCollection<string, array<int, int>>', $customEloquentCollection->mapToDictionary(fn (Transaction $t) => ['foo'=> $t->id]));
    // Hypervel: known PHPStan limitation. A static<...> return keeps the fixed int keys and User items of a class
    // like UserCollection, so results marked with this limitation do not match runtime. This one holds arrays under
    // string keys.
    assertType('App\UserCollection', $secondCustomEloquentCollection->mapToDictionary(fn (User $t) => ['foo'=> $t->id]));
    assertType('Hypervel\Support\Collection<string, array<int, int>>', $items->mapToDictionary(fn (int $v) => ['foo' => $v]));

    assertType('Hypervel\Support\Collection<int, string>', $customEloquentCollection->mapWithKeys(fn (Transaction $transaction): array => [$transaction->id => 'foo']));
    assertType('Hypervel\Support\Collection<int, string>', $secondCustomEloquentCollection->mapWithKeys(fn (User $user): array => [$user->id => 'foo']));
    assertType('Hypervel\Support\Collection<int, int>', $collection->mapWithKeys(fn (User $user): array => [$user->id => $user->id]));
    assertType('Hypervel\Support\Collection<string, int>', $items->mapWithKeys(fn (int $value, string $key): array => ['foo' => $value]));

    assertType('App\TransactionCollection<int, App\Transaction>', $customEloquentCollection->mapWithKeys(fn (Transaction $transaction): array => [$transaction->id => $transaction]));
    // Hypervel: known PHPStan limitation; this holds string keys.
    assertType('App\UserCollection', $secondCustomEloquentCollection->mapWithKeys(fn (User $user): array => [$user->email => $user]));
    assertType('Hypervel\Database\Eloquent\Collection<string, App\User>', $collection->mapWithKeys(fn (User $user): array => [$user->name => $user]));

    assertType('Hypervel\Database\Eloquent\Collection<int, App\User|string>', $collection->mergeRecursive([2 => 'foo']));
    assertType('App\TransactionCollection<int, App\Transaction>', $customEloquentCollection->mergeRecursive([1 => new Transaction()]));
    assertType('App\UserCollection', $secondCustomEloquentCollection->mergeRecursive([1 => new User()]));
    // Hypervel: mergeRecursive() turns values under a repeated string key into arrays.
    assertType('Hypervel\Support\Collection<string, array<mixed>|int>', $items->mergeRecursive(['foo' => 2]));

    assertType('Hypervel\Database\Eloquent\Collection<(int|string), int>', $collection->combine([1]));
    assertType('App\TransactionCollection<(int|string), int>', $customEloquentCollection->combine([1]));
    // Hypervel: known PHPStan limitation; this holds the given values.
    assertType('App\UserCollection', $secondCustomEloquentCollection->combine([1]));
    // Hypervel: combine() keys keep the values' type when it is an array-key type.
    assertType('Hypervel\Support\Collection<int, string>', $items->combine(['foo']));

    assertType('App\User|null', $collection->pop(1));
    assertType('App\TransactionCollection<int, App\Transaction>', $customEloquentCollection->pop(2));
    assertType('App\UserCollection', $secondCustomEloquentCollection->pop(2));
    assertType('Hypervel\Support\Collection<int, int>', $items->pop(3));

    // Hypervel: BelongsToMany results carry their pivot.
    assertType('App\Role&object{pivot: Hypervel\Database\Eloquent\Relations\Pivot}', User::firstOrFail(1)->roles->random());
    assertType('App\RoleCollection<int, App\Role&object{pivot: Hypervel\Database\Eloquent\Relations\Pivot}>', User::firstOrFail(1)->roles->random(1));
    assertType('Hypervel\Database\Eloquent\Collection<int, App\User>', $collection->random(1));
    assertType('App\TransactionCollection<int, App\Transaction>', $customEloquentCollection->random(2));
    assertType('App\UserCollection', $secondCustomEloquentCollection->random(2));
    assertType('Hypervel\Support\Collection<int, int>', $items->random(3));

    assertType('App\User|null', $collection->shift(1));
    assertType('App\TransactionCollection<int, App\Transaction>', $customEloquentCollection->shift(2));
    assertType('App\UserCollection', $secondCustomEloquentCollection->shift(2));
    assertType('Hypervel\Support\Collection<int, int>', $items->shift(3));

    // Hypervel: sliding() builds its windows through map(), which returns a base collection of collections.
    assertType('Hypervel\Support\Collection<int, Hypervel\Database\Eloquent\Collection<int, App\User>>', $collection->sliding(1));
    assertType('Hypervel\Support\Collection<int, App\TransactionCollection<int, App\Transaction>>', $customEloquentCollection->sliding(2));
    assertType('Hypervel\Support\Collection<int, App\UserCollection>', $secondCustomEloquentCollection->sliding(2));
    assertType('Hypervel\Support\Collection<int, Hypervel\Support\Collection<string, int>>', $items->sliding(3));

    assertType('Hypervel\Database\Eloquent\Collection<int, Hypervel\Database\Eloquent\Collection<int, App\User>>', $collection->split(1));
    assertType('App\TransactionCollection<int, App\TransactionCollection<int, App\Transaction>>', $customEloquentCollection->split(2));
    // Hypervel: known PHPStan limitation; this holds UserCollection chunks.
    assertType('App\UserCollection', $secondCustomEloquentCollection->split(2));
    assertType('Hypervel\Support\Collection<int, Hypervel\Support\Collection<string, int>>', $items->split(3));

    assertType('Hypervel\Database\Eloquent\Collection<int, Hypervel\Database\Eloquent\Collection<int, App\User>>', $collection->splitIn(1));
    assertType('App\TransactionCollection<int, App\TransactionCollection<int, App\Transaction>>', $customEloquentCollection->splitIn(2));
    // Hypervel: known PHPStan limitation; this holds UserCollection chunks.
    assertType('App\UserCollection', $secondCustomEloquentCollection->splitIn(2));
    assertType('Hypervel\Support\Collection<int, Hypervel\Support\Collection<string, int>>', $items->splitIn(3));

    assertType('Hypervel\Database\Eloquent\Collection<int, Hypervel\Database\Eloquent\Collection<int, App\User>>', $collection->chunk(1));
    assertType('App\TransactionCollection<int, App\TransactionCollection<int, App\Transaction>>', $customEloquentCollection->chunk(2));
    // Hypervel: known PHPStan limitation; this holds UserCollection chunks.
    assertType('App\UserCollection', $secondCustomEloquentCollection->chunk(2));
    assertType('Hypervel\Support\Collection<int, Hypervel\Support\Collection<string, int>>', $items->chunk(3));

    assertType('Hypervel\Database\Eloquent\Collection<int, Hypervel\Database\Eloquent\Collection<int, App\User>>', $collection->chunkWhile(fn (User $u) => $u->id > 5));
    assertType('App\TransactionCollection<int, App\TransactionCollection<int, App\Transaction>>', $customEloquentCollection->chunkWhile(fn (Transaction $t) => $t->id > 5));
    // Hypervel: known PHPStan limitation; this holds UserCollection chunks.
    assertType('App\UserCollection', $secondCustomEloquentCollection->chunkWhile(fn (User $t) => $t->id > 5));
    assertType('Hypervel\Support\Collection<int, Hypervel\Support\Collection<string, int>>', $items->chunkWhile(fn ($v) => $v > 5));

    assertType('Hypervel\Database\Eloquent\Collection<int, App\User>', $collection->values());
    assertType('App\TransactionCollection<int, App\Transaction>', $customEloquentCollection->values());
    assertType('App\UserCollection', $secondCustomEloquentCollection->values());
    assertType('Hypervel\Support\Collection<int, int>', $items->values());

    assertType('Hypervel\Support\Collection<int, Hypervel\Support\Collection<int, App\User|int>>', $collection->zip([1]));
    assertType('Hypervel\Support\Collection<int, Hypervel\Support\Collection<int, App\Transaction|string>>', $customEloquentCollection->zip(['foo']));
    assertType('Hypervel\Support\Collection<int, Hypervel\Support\Collection<int, App\User|string>>', $secondCustomEloquentCollection->zip(['foo']));
    assertType('Hypervel\Support\Collection<int, Hypervel\Support\Collection<int, int|string>>', $items->zip(['foo', 'bar']));

    assertType('Hypervel\Support\Collection<int<0, 1>, Hypervel\Support\Collection<string, int>>', $items->partition('foo'));

    assertType('Hypervel\Support\Collection<int, App\User|int>', $collection->pad(10, 10));
    assertType('Hypervel\Support\Collection<int, App\Transaction|string>', $customEloquentCollection->pad(10, 'foo'));
    assertType('Hypervel\Support\Collection<int, App\User|string>', $secondCustomEloquentCollection->pad(10, 'foo'));
    // Hypervel: pad() keeps string keys, as array_pad() does.
    assertType('Hypervel\Support\Collection<int|string, int|string>', $items->pad(1, 'bar'));

    assertType('Hypervel\Support\Collection<(int|string), int>', $collection->countBy('email'));
    assertType('Hypervel\Support\Collection<(int|string), int>', $customEloquentCollection->countBy('foo'));
    assertType('Hypervel\Support\Collection<(int|string), int>', $secondCustomEloquentCollection->countBy('foo'));
    assertType('Hypervel\Support\Collection<(int|string), int>', $items->countBy('bar'));

    assertType('Hypervel\Database\Eloquent\Collection<int, App\User>', $collection->concat([new User()]));
    assertType('App\TransactionCollection<int, App\Transaction|App\User>', $customEloquentCollection->concat([new User()]));
    assertType('App\UserCollection', $secondCustomEloquentCollection->concat([new User()]));
    assertType('Hypervel\Support\Collection<int|string, App\User|int>', $items->concat([new User()]));

     ////////////////////////////
     // EnumeratesValues Trait //
     ////////////////////////////

    assertType('Hypervel\Database\Eloquent\Collection<int, App\User>', EloquentCollection::make([new User()]));
    assertType('App\TransactionCollection<int, App\Transaction>', TransactionCollection::make([new Transaction()]));
    assertType('Hypervel\Support\Collection<int, int>', SupportCollection::make([1, 2, 3]));

    assertType('Hypervel\Database\Eloquent\Collection<(int|string), App\User>', EloquentCollection::wrap([new User()]));
    assertType('App\TransactionCollection<(int|string), App\Transaction>', TransactionCollection::wrap([new Transaction()]));
    assertType('Hypervel\Support\Collection<(int|string), int>', SupportCollection::wrap([1, 2, 3]));

    // Hypervel: times() maps through map(), which can return a base collection, so it declares the base collection.
    assertType('Hypervel\Support\Collection<int, App\User>', EloquentCollection::times(10, fn ($int) => new User));
    assertType('Hypervel\Support\Collection<int, App\Transaction>', TransactionCollection::times(10, fn ($int) => new Transaction));
    assertType('Hypervel\Support\Collection<int, int>', SupportCollection::times(10, fn ($int) => 5));
    assertType('Hypervel\Support\LazyCollection<int, int>', LazyCollection::times(10, fn ($int) => 5));

     // Hypervel: matches the runtime, where map() returns a base collection of collections.
    assertType(
         'Hypervel\Support\Collection<string, Hypervel\Database\Eloquent\Collection<int, int>>',
         $collection->mapToGroups(fn (User $user, int $key): array => ['foo' => $user->id])
     );

     // Hypervel: flatMap() collapses a map() of arrays, which is a base collection.
    assertType(
         'Hypervel\Support\Collection<int, int>',
         $collection->flatMap(function (User $user, int $id) {
             return [$user->id];
         })
     );

    assertType(
         'Hypervel\Support\Collection<int, int>',
         $items->flatMap(function (int $int) {
             return [$int * 2];
         })
     );

    assertType(
         'Hypervel\Support\LazyCollection<int, int>',
         $lazyCollection->flatMap(function (User $user, int $id) {
             return [$user->id];
         })
     );

    assertType(
         'Hypervel\Support\LazyCollection<int, int>',
         LazyCollection::times(10, fn ($int) => 5)->flatMap(fn (int $i) => [$i * 2]),
     );

    assertType('Hypervel\Support\Collection<(int|string), Hypervel\Support\Collection<int, array{id: int, type: string}>>', collect([
         [
             'id'   => 1,
             'type' => 'A',
         ],
         [
             'id'   => 1,
             'type' => 'B',
         ],
     ])->groupBy('type'));

    assertType('int|false', $enumerableIntUsers->search(fn(User $user) => $user->id === 1));
    assertType('string|false', $enumerableStringUsers->search(fn(User $user) => $user->id === 1));
}
