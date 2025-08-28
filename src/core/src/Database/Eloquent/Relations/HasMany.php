<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent\Relations;

use Closure;
use Hyperf\Database\Model\Relations\HasMany as BaseHasMany;
use Hypervel\Database\Eloquent\Relations\Concerns\WithoutAddConstraints;
use Hypervel\Database\Eloquent\Relations\Contracts\Relation as RelationContract;

/**
 * @template TRelatedModel of \Hypervel\Database\Eloquent\Model
 * @template TParentModel of \Hypervel\Database\Eloquent\Model
 *
 * @implements RelationContract<TRelatedModel, TParentModel, \Hypervel\Database\Eloquent\Collection<int, TRelatedModel>>
 *
 * @method \Hypervel\Database\Eloquent\Collection<int, TRelatedModel> get(array|string $columns = ['*'])
 * @method null|TRelatedModel first(array|string $columns = ['*'])
 * @method TRelatedModel firstOrFail(array|string $columns = ['*'])
 * @method mixed|TRelatedModel firstOr(\Closure|array|string $columns = ['*'], ?\Closure $callback = null)
 * @method null|TRelatedModel find(mixed $id, array|string $columns = ['*'])
 * @method TRelatedModel findOrFail(mixed $id, array|string $columns = ['*'])
 * @method TRelatedModel findOrNew(mixed $id, array|string $columns = ['*'])
 * @method \Hypervel\Database\Eloquent\Collection<int, TRelatedModel> findMany(mixed $ids, array|string $columns = ['*'])
 * @method TRelatedModel make(array $attributes = [])
 * @method TRelatedModel create(array $attributes = [])
 * @method TRelatedModel forceCreate(array $attributes)
 * @method TRelatedModel firstOrNew(array $attributes = [], array $values = [])
 * @method TRelatedModel firstOrCreate(array $attributes = [], array $values = [])
 * @method TRelatedModel updateOrCreate(array $attributes, array $values = [])
 * @method false|TRelatedModel save(\Hypervel\Database\Eloquent\Model $model)
 * @method array saveMany(array|\Hypervel\Support\Collection $models)
 * @method \Hypervel\Database\Eloquent\Collection<int, TRelatedModel> createMany(array $records)
 * @method \Hypervel\Database\Eloquent\Builder<TRelatedModel> getQuery()
 * @method \Hypervel\Support\LazyCollection<int, TRelatedModel> lazy(int $chunkSize = 1000)
 * @method \Hypervel\Support\LazyCollection<int, TRelatedModel> lazyById(int $chunkSize = 1000, ?string $column = null, ?string $alias = null)
 * @method \Hypervel\Support\LazyCollection<int, TRelatedModel> lazyByIdDesc(int $chunkSize = 1000, ?string $column = null, ?string $alias = null)
 * @method \Hypervel\Database\Eloquent\Collection<int, TRelatedModel> getResults()
 */
class HasMany extends BaseHasMany implements RelationContract
{
    use WithoutAddConstraints;

    /**
     * @template TValue
     *
     * @param array|(Closure(): TValue) $columns
     * @param null|(Closure(): TValue) $callback
     * @return TRelatedModel|TValue
     */
    public function firstOr($columns = ['*'], ?Closure $callback = null)
    {
        if ($columns instanceof Closure) {
            $callback = $columns;
            $columns = ['*'];
        }

        if (! is_null($model = $this->first($columns))) {
            return $model;
        }

        return $callback();
    }
}
