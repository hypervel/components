<?php

namespace CollectionOfType;

use Hypervel\Database\Eloquent\Collection;
use Hypervel\Database\Eloquent\Model;
use function PHPStan\Testing\assertType;

/**
 * @param collection-of<\App\User> $param
 * @param collection-of<\App\Role> $param2
 * @param collection-of<\App\User|\App\Role> $union
 */
function test($param, Collection $param2, Collection $union): void
{
    assertType('Hypervel\Database\Eloquent\Collection<(int|string), App\User>', $param);
    assertType('App\RoleCollection<(int|string), App\Role>', $param2);
    assertType('App\RoleCollection<(int|string), App\Role>|Hypervel\Database\Eloquent\Collection<(int|string), App\User>', $union);

    assertType('Hypervel\Database\Eloquent\Collection<(int|string), App\User>', genericMethod(\App\User::class));
    assertType('App\RoleCollection<(int|string), App\Role>', genericMethod(\App\Role::class));
}

/**
 * @template T of Model
 *
 * @param class-string<T> $class
 *
 * @return collection-of<T>
 */
function genericMethod(string $class): Collection
{
    return (new $class)->newCollection();
}

/**
 * @param collection-of<mixed> $unknown
 * @param \Hypervel\Database\Eloquent\Relations\Relation<*, *, *> $relation
 * @param collection-of<ConstantCollectedModel> $constantCollected
 */
function unknownModels($unknown, \Hypervel\Database\Eloquent\Relations\Relation $relation, $constantCollected): void
{
    // Unlike upstream, which resolves to never, an unknown model has the base model's collection.
    assertType('Hypervel\Database\Eloquent\Collection<(int|string), Hypervel\Database\Eloquent\Model>', $unknown);
    assertType('Hypervel\Database\Eloquent\Collection<int, Hypervel\Database\Eloquent\Model>', $relation->get());

    // Unlike upstream, a class constant's value names the collection, as instantiating the attribute reads it.
    assertType('CollectionOfType\ConstantCollection<(int|string), CollectionOfType\ConstantCollectedModel>', $constantCollected);
}

class CollectionNames
{
    public const string COLLECTION = ConstantCollection::class;
}

/**
 * @template TKey of array-key
 * @template TModel of Model
 *
 * @extends Collection<TKey, TModel>
 */
class ConstantCollection extends Collection
{
}

#[\Hypervel\Database\Eloquent\Attributes\CollectedBy(CollectionNames::COLLECTION)]
class ConstantCollectedModel extends Model
{
}
