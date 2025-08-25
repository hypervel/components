<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent;

use Closure;
use Hyperf\Database\Model\Builder as BaseBuilder;
use Hyperf\Database\Model\Collection;
use Hyperf\Database\Model\ModelNotFoundException as BaseModelNotFoundException;
use Hypervel\Database\Eloquent\Concerns\QueriesRelationships;
use Hypervel\Support\Collection as BaseCollection;
use Hypervel\Support\LazyCollection;

/**
 * @template TModel of \Hypervel\Database\Eloquent\Model
 *
 * @extends \Hyperf\Database\Model\Builder<TModel>
 *
 * @method TModel make(array $attributes = [])
 * @method TModel create(array $attributes = [])
 * @method TModel forceCreate(array $attributes = [])
 * @method TModel firstOrNew(array $attributes = [], array $values = [])
 * @method TModel firstOrCreate(array $attributes = [], array $values = [])
 * @method TModel updateOrCreate(array $attributes, array $values = [])
 * @method null|TModel first(mixed $columns = ['*'])
 * @method TModel firstOrFail(mixed $columns = ['*'])
 * @method TModel sole(mixed $columns = ['*'])
 * @method ($id is (array<mixed>|\Hyperf\Contract\Arrayable<array-key, mixed>) ? \Hypervel\Database\Eloquent\Collection<int, TModel> : null|TModel) find(mixed $id, array $columns = ['*'])
 * @method ($id is (array<mixed>|\Hyperf\Contract\Arrayable<array-key, mixed>) ? \Hypervel\Database\Eloquent\Collection<int, TModel> : TModel) findOrNew(mixed $id, array $columns = ['*'])
 * @method \Hypervel\Database\Eloquent\Collection<int, TModel> findMany(array|\Hypervel\Support\Contracts\Arrayable $ids, array $columns = ['*'])
 * @method $this where(mixed $column, mixed $operator = null, mixed $value = null, string $boolean = 'and')
 * @method $this orWhere(mixed $column, mixed $operator = null, mixed $value = null)
 * @method $this with(mixed $relations, mixed ...$args)
 * @method $this without(mixed $relations)
 * @method $this withWhereHas(string $relation, (\Closure(\Hypervel\Database\Eloquent\Builder<*>|\Hypervel\Database\Eloquent\Relations\Contracts\Relation<*, *, *>): mixed)|null $callback = null, string $operator = '>=', int $count = 1)
 * @method $this whereMorphDoesntHaveRelation(mixed $relation, array|string $types, mixed $column, mixed $operator = null, mixed $value = null)
 * @method $this orWhereMorphDoesntHaveRelation(mixed $relation, array|string $types, mixed $column, mixed $operator = null, mixed $value = null)
 * @method \Hypervel\Database\Eloquent\Collection<int, TModel> get(array|string $columns = ['*'])
 * @method \Hypervel\Database\Eloquent\Collection<int, TModel> hydrate(array $items)
 * @method \Hypervel\Database\Eloquent\Collection<int, TModel> fromQuery(string $query, array $bindings = [])
 * @method array<int, TModel> getModels(array|string $columns = ['*'])
 * @method array<int, TModel> eagerLoadRelations(array $models)
 * @method \Hypervel\Database\Eloquent\Relations\Contracts\Relation<\Hypervel\Database\Eloquent\Model, TModel, *> getRelation(string $name)
 * @method TModel getModel()
 * @method bool chunk(int $count, callable(\Hypervel\Support\Collection<int, TModel>, int): (bool|void) $callback)
 * @method bool chunkById(int $count, callable(\Hypervel\Support\Collection<int, TModel>, int): (bool|void) $callback, null|string $column = null, null|string $alias = null)
 * @method bool chunkByIdDesc(int $count, callable(\Hypervel\Support\Collection<int, TModel>, int): (bool|void) $callback, null|string $column = null, null|string $alias = null)
 * @method bool each(callable(TModel, int): (bool|void) $callback, int $count = 1000)
 * @method bool eachById(callable(TModel, int): (bool|void) $callback, int $count = 1000, null|string $column = null, null|string $alias = null)
 * @method $this whereIn(string $column, mixed $values, string $boolean = 'and', bool $not = false)
 */
class Builder extends BaseBuilder
{
    use QueriesRelationships;

    /**
     * @return \Hypervel\Support\LazyCollection<int, TModel>
     */
    public function cursor()
    {
        return new LazyCollection(function () {
            yield from parent::cursor();
        });
    }

    /**
     * @return \Hypervel\Support\LazyCollection<int, TModel>
     */
    public function lazy(int $chunkSize = 1000): LazyCollection
    {
        return new LazyCollection(function () use ($chunkSize) {
            yield from parent::lazy($chunkSize);
        });
    }

    /**
     * @return \Hypervel\Support\LazyCollection<int, TModel>
     */
    public function lazyById(int $chunkSize = 1000, ?string $column = null, ?string $alias = null): LazyCollection
    {
        return new LazyCollection(function () use ($chunkSize, $column, $alias) {
            yield from parent::lazyById($chunkSize, $column, $alias);
        });
    }

    /**
     * @return \Hypervel\Support\LazyCollection<int, TModel>
     */
    public function lazyByIdDesc(int $chunkSize = 1000, ?string $column = null, ?string $alias = null): LazyCollection
    {
        return new LazyCollection(function () use ($chunkSize, $column, $alias) {
            yield from parent::lazyByIdDesc($chunkSize, $column, $alias);
        });
    }

    /**
     * @template TWhenParameter
     * @template TWhenReturnType of static|void
     *
     * @param Closure(static): TWhenParameter|TWhenParameter $value
     * @param Closure(static, TWhenParameter): TWhenReturnType $callback
     * @param null|(Closure(static, TWhenParameter): TWhenReturnType) $default
     *
     * @return (TWhenReturnType is void ? static : TWhenReturnType)
     */
    public function when($value = null, ?callable $callback = null, ?callable $default = null)
    {
        return parent::when($value, $callback, $default);
    }

    /**
     * @param array<array-key, string>|string $column
     * @param null|string $key
     * @return \Hypervel\Support\Collection<array-key, mixed>
     */
    public function pluck($column, $key = null)
    {
        return new BaseCollection(parent::pluck($column, $key)->all());
    }

    /**
     * @template TValue
     *
     * @param array|(Closure(): TValue)|string $columns
     * @param null|(Closure(): TValue) $callback
     * @return (
     *     $id is (\Hyperf\Contract\Arrayable<array-key, mixed>|array<mixed>)
     *     ? \Hypervel\Database\Eloquent\Collection<int, TModel>
     *     : TModel|TValue
     * )
     */
    public function findOr(mixed $id, array|Closure|string $columns = ['*'], ?Closure $callback = null): mixed
    {
        return parent::findOr($id, $columns, $callback);
    }

    /**
     * @template TValue
     *
     * @param array|(Closure(): TValue) $columns
     * @param null|(Closure(): TValue) $callback
     * @return TModel|TValue
     */
    public function firstOr($columns = ['*'], ?Closure $callback = null)
    {
        return parent::firstOr($columns, $callback);
    }

    /**
     * @param mixed $id
     * @param array $columns
     * @return ($id is (array<mixed>|\Hyperf\Contract\Arrayable<array-key, mixed>) ? \Hypervel\Database\Eloquent\Collection<int, TModel> : TModel)
     *
     * @throws \Hypervel\Database\Eloquent\ModelNotFoundException<TModel>
     */
    public function findOrFail($id, $columns = ['*'])
    {
        try {
            return parent::findOrFail($id, $columns);
        } catch (BaseModelNotFoundException) {
            throw (new ModelNotFoundException())->setModel(
                get_class($this->model),
                $id
            );
        }
    }

    /**
     * @param array $columns
     * @return TModel
     *
     * @throws \Hypervel\Database\Eloquent\ModelNotFoundException<TModel>
     */
    public function firstOrFail($columns = ['*'])
    {
        try {
            return parent::firstOrFail($columns);
        } catch (BaseModelNotFoundException) {
            throw (new ModelNotFoundException())->setModel(
                get_class($this->model)
            );
        }
    }

    /**
     * @param array|string $columns
     * @return TModel
     *
     * @throws \Hypervel\Database\Eloquent\ModelNotFoundException<TModel>
     */
    public function sole($columns = ['*'])
    {
        try {
            return parent::sole($columns);
        } catch (BaseModelNotFoundException) {
            throw (new ModelNotFoundException())->setModel(
                get_class($this->model)
            );
        }
    }

    /**
     * @template TNewModel of \Hypervel\Database\Eloquent\Model
     *
     * @param TNewModel $model
     * @return static<TNewModel>
     */
    public function setModel($model)
    {
        return parent::setModel($model);
    }

    /**
     * @template TReturn
     *
     * @param callable(TModel): TReturn $callback
     * @param int $count
     * @return \Hypervel\Database\Eloquent\Collection<int, TReturn>
     */
    public function chunkMap(callable $callback, $count = 1000): Collection
    {
        return Collection::make(parent::chunkMap($callback, $count));
    }
}
