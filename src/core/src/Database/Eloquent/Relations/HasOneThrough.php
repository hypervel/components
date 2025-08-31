<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent\Relations;

use Closure;
use Hyperf\Database\Model\Relations\HasOneThrough as BaseHasOneThrough;
use Hypervel\Database\Eloquent\Relations\Concerns\WithoutAddConstraints;
use Hypervel\Database\Eloquent\Relations\Contracts\Relation as RelationContract;

/**
 * @template TRelatedModel of \Hypervel\Database\Eloquent\Model
 * @template TIntermediateModel of \Hypervel\Database\Eloquent\Model
 * @template TParentModel of \Hypervel\Database\Eloquent\Model
 *
 * @implements RelationContract<TRelatedModel, TParentModel, null|TRelatedModel>
 *
 * @method \Hypervel\Database\Eloquent\Collection<int, TRelatedModel> get(array|string $columns = ['*'])
 * @method null|TRelatedModel first(array|string $columns = ['*'])
 * @method TRelatedModel firstOrFail(array|string $columns = ['*'])
 * @method TRelatedModel findOrFail(mixed $id, array|string $columns = ['*'])
 * @method ($id is (array<mixed>|\Hyperf\Collection\Contracts\Arrayable<array-key, mixed>) ? \Hypervel\Database\Eloquent\Collection<int, TRelatedModel> : null|TRelatedModel) find(mixed $id, array $columns = ['*'])
 * @method \Hypervel\Database\Eloquent\Builder<TRelatedModel> getQuery()
 * @method \Hypervel\Support\LazyCollection<int, TRelatedModel> lazy(int $chunkSize = 1000)
 * @method \Hypervel\Support\LazyCollection<int, TRelatedModel> lazyById(int $chunkSize = 1000, ?string $column = null, ?string $alias = null)
 * @method \Hypervel\Support\LazyCollection<int, TRelatedModel> lazyByIdDesc(int $chunkSize = 1000, ?string $column = null, ?string $alias = null)
 * @method null|TRelatedModel getResults()
 */
class HasOneThrough extends BaseHasOneThrough implements RelationContract
{
    use WithoutAddConstraints;

    /**
     * @template TValue
     *
     * @param (Closure(): TValue)|list<string> $columns
     * @param null|(Closure(): TValue) $callback
     * @return TRelatedModel|TValue
     */
    public function firstOr($columns = ['*'], ?Closure $callback = null)
    {
        return parent::firstOr($columns, $callback);
    }
}
