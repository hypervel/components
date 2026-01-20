<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent\Relations;

use Hyperf\Database\Model\Relations\BelongsTo as BaseBelongsTo;
use Hypervel\Database\Eloquent\Relations\Concerns\WithoutAddConstraints;
use Hypervel\Database\Eloquent\Relations\Contracts\Relation as RelationContract;

/**
 * @template TRelatedModel of \Hypervel\Database\Eloquent\Model
 * @template TChildModel of \Hypervel\Database\Eloquent\Model
 *
 * @implements RelationContract<TRelatedModel, TChildModel, null|TRelatedModel>
 *
 * @method \Hypervel\Database\Eloquent\Collection<int, TRelatedModel> get(array|string $columns = ['*'])
 * @method TRelatedModel make(array $attributes = [])
 * @method TRelatedModel create(array $attributes = [])
 * @method TRelatedModel firstOrNew(array $attributes = [], array $values = [])
 * @method TRelatedModel firstOrCreate(array $attributes = [], array $values = [])
 * @method TRelatedModel updateOrCreate(array $attributes, array $values = [])
 * @method null|TRelatedModel first(array|string $columns = ['*'])
 * @method TRelatedModel firstOrFail(array|string $columns = ['*'])
 * @method TRelatedModel findOrFail(mixed $id, array|string $columns = ['*'])
 * @method null|TRelatedModel find(mixed $id, array|string $columns = ['*'])
 * @method \Hypervel\Database\Eloquent\Collection<int, TRelatedModel> findMany(mixed $ids, array|string $columns = ['*'])
 * @method TRelatedModel findOrNew(mixed $id, array|string $columns = ['*'])
 * @method mixed|TRelatedModel firstOr(\Closure|array|string $columns = ['*'], ?\Closure $callback = null)
 * @method TRelatedModel forceCreate(array $attributes)
 * @method \Hypervel\Database\Eloquent\Builder<TRelatedModel> getQuery()
 * @method \Hypervel\Support\LazyCollection<int, TRelatedModel> lazy(int $chunkSize = 1000)
 * @method \Hypervel\Support\LazyCollection<int, TRelatedModel> lazyById(int $chunkSize = 1000, ?string $column = null, ?string $alias = null)
 * @method \Hypervel\Support\LazyCollection<int, TRelatedModel> lazyByIdDesc(int $chunkSize = 1000, ?string $column = null, ?string $alias = null)
 * @method null|TRelatedModel getResults()
 * @method TChildModel associate(\Hypervel\Database\Eloquent\Model|int|string $model)
 * @method TChildModel dissociate()
 * @method TChildModel getChild()
 */
class BelongsTo extends BaseBelongsTo implements RelationContract
{
    use WithoutAddConstraints;
}
