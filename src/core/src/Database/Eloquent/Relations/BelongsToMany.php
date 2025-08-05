<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent\Relations;

use Closure;
use Hyperf\Database\Model\Relations\BelongsToMany as BaseBelongsToMany;
use Hypervel\Database\Eloquent\Relations\Concerns\WithoutAddConstraints;
use Hypervel\Database\Eloquent\Relations\Contracts\Relation as RelationContract;

/**
 * @template TRelatedModel of \Hypervel\Database\Eloquent\Model
 * @template TParentModel of \Hypervel\Database\Eloquent\Model
 * @template TPivotModel of \Hypervel\Database\Eloquent\Relations\Pivot = \Hypervel\Database\Eloquent\Relations\Pivot
 * @template TAccessor of string = 'pivot'
 *
 * @implements RelationContract<TRelatedModel, TParentModel, \Hypervel\Database\Eloquent\Collection<int, object{pivot: TPivotModel}&TRelatedModel>>
 *
 * @method \Hypervel\Database\Eloquent\Collection<int, object{pivot: TPivotModel}&TRelatedModel> get(array|string $columns = ['*'])
 * @method object{pivot: TPivotModel}&TRelatedModel make(array $attributes = [])
 * @method object{pivot: TPivotModel}&TRelatedModel create(array $attributes = [])
 * @method object{pivot: TPivotModel}&TRelatedModel firstOrNew(array $attributes = [], array $values = [])
 * @method object{pivot: TPivotModel}&TRelatedModel firstOrCreate(array $attributes = [], array $values = [])
 * @method object{pivot: TPivotModel}&TRelatedModel updateOrCreate(array $attributes, array $values = [])
 * @method object{pivot: TPivotModel}&TRelatedModel createOrFirst(array $attributes = [], array $values = [])
 * @method null|(object{pivot: TPivotModel}&TRelatedModel) first(array|string $columns = ['*'])
 * @method object{pivot: TPivotModel}&TRelatedModel firstOrFail(array|string $columns = ['*'])
 * @method object{pivot: TPivotModel}&TRelatedModel save(\Hypervel\Database\Eloquent\Model $model, array $pivotAttributes = [])
 * @method \Hypervel\Database\Eloquent\Collection<int, object{pivot: TPivotModel}&TRelatedModel> findMany(mixed $ids, array|string $columns = ['*'])
 * @method object{pivot: TPivotModel}&TRelatedModel forceCreate(array $attributes)
 * @method array<int, object{pivot: TPivotModel}&TRelatedModel> createMany(array $records)
 * @method \Hypervel\Database\Eloquent\Builder<TRelatedModel> getQuery()
 * @method void attach(mixed $id, array $attributes = [], bool $touch = true)
 * @method int detach(mixed $ids = null, bool $touch = true)
 * @method array{attached: array, detached: array, updated: array} sync(array|\Hypervel\Support\Collection|\Hypervel\Database\Eloquent\Collection $ids, bool $detaching = true)
 * @method array{attached: array, detached: array, updated: array} syncWithoutDetaching(array|\Hypervel\Database\Eloquent\Collection|\Hypervel\Support\Collection $ids)
 * @method void toggle(mixed $ids, bool $touch = true)
 * @method \Hypervel\Database\Eloquent\Collection<int, TRelatedModel> newPivotStatement()
 * @method \Hypervel\Support\LazyCollection<int, object{pivot: TPivotModel}&TRelatedModel> lazy(int $chunkSize = 1000)
 * @method \Hypervel\Support\LazyCollection<int, object{pivot: TPivotModel}&TRelatedModel> lazyById(int $chunkSize = 1000, ?string $column = null, ?string $alias = null)
 * @method \Hypervel\Support\LazyCollection<int, object{pivot: TPivotModel}&TRelatedModel> lazyByIdDesc(int $chunkSize = 1000, ?string $column = null, ?string $alias = null)
 * @method \Hypervel\Database\Eloquent\Collection<int, object{pivot: TPivotModel}&TRelatedModel> getResults()
 */
class BelongsToMany extends BaseBelongsToMany implements RelationContract
{
    use WithoutAddConstraints;

    /**
     * @param mixed $id
     * @param array|string $columns
     * @return ($id is (array<mixed>|\Hyperf\Collection\Contracts\Arrayable<array-key, mixed>) ? \Hypervel\Database\Eloquent\Collection<int, object{pivot: TPivotModel}&TRelatedModel> : null|(object{pivot: TPivotModel}&TRelatedModel))
     */
    public function find($id, $columns = ['*'])
    {
        return parent::find($id, $columns);
    }

    /**
     * @param mixed $id
     * @param array|string $columns
     * @return ($id is (array<mixed>|\Hyperf\Collection\Contracts\Arrayable<array-key, mixed>) ? \Hypervel\Database\Eloquent\Collection<int, object{pivot: TPivotModel}&TRelatedModel> : object{pivot: TPivotModel}&TRelatedModel)
     */
    public function findOrNew($id, $columns = ['*'])
    {
        return parent::findOrNew($id, $columns);
    }

    /**
     * @param mixed $id
     * @param array|string $columns
     * @return ($id is (array<mixed>|\Hyperf\Collection\Contracts\Arrayable<array-key, mixed>) ? \Hypervel\Database\Eloquent\Collection<int, object{pivot: TPivotModel}&TRelatedModel> : object{pivot: TPivotModel}&TRelatedModel)
     */
    public function findOrFail($id, $columns = ['*'])
    {
        return parent::findOrFail($id, $columns);
    }

    /**
     * @template TValue
     *
     * @param mixed $id
     * @param (Closure(): TValue)|list<string>|string $columns
     * @param null|(Closure(): TValue) $callback
     * @return ($id is (array<mixed>|\Hyperf\Collection\Contracts\Arrayable<array-key, mixed>) ? \Hypervel\Database\Eloquent\Collection<int, object{pivot: TPivotModel}&TRelatedModel> : (object{pivot: TPivotModel}&TRelatedModel))|TValue
     */
    public function findOr($id, $columns = ['*'], ?Closure $callback = null)
    {
        return parent::findOr($id, $columns, $callback);
    }

    /**
     * @template TValue
     *
     * @param (Closure(): TValue)|list<string> $columns
     * @param null|(Closure(): TValue) $callback
     * @return (object{pivot: TPivotModel}&TRelatedModel)|TValue
     */
    public function firstOr($columns = ['*'], ?Closure $callback = null)
    {
        return parent::firstOr($columns, $callback);
    }

    /**
     * @template TContainer of \Hypervel\Database\Eloquent\Collection<array-key, TRelatedModel&object{pivot: TPivotModel}>|\Hypervel\Support\Collection<array-key, TRelatedModel&object{pivot: TPivotModel}>|array<array-key, TRelatedModel>
     *
     * @param TContainer $models
     * @return TContainer
     */
    public function saveMany($models, array $pivotAttributes = [])
    {
        return parent::saveMany($models, $pivotAttributes);
    }
}
