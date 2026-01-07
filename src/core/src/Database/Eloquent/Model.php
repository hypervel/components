<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent;

use Hyperf\DbConnection\Model\Model as BaseModel;
use Hyperf\Stringable\Str;
use Hypervel\Broadcasting\Contracts\HasBroadcastChannel;
use Hypervel\Context\Context;
use Hypervel\Database\Eloquent\Concerns\HasAttributes;
use Hypervel\Database\Eloquent\Concerns\HasBootableTraits;
use Hypervel\Database\Eloquent\Concerns\HasCallbacks;
use Hypervel\Database\Eloquent\Concerns\HasCollection;
use Hypervel\Database\Eloquent\Concerns\HasGlobalScopes;
use Hypervel\Database\Eloquent\Concerns\HasLocalScopes;
use Hypervel\Database\Eloquent\Concerns\HasObservers;
use Hypervel\Database\Eloquent\Concerns\HasRelations;
use Hypervel\Database\Eloquent\Concerns\HasRelationships;
use Hypervel\Database\Eloquent\Concerns\TransformsToResource;
use Hypervel\Database\Eloquent\Relations\Pivot;
use Hypervel\Router\Contracts\UrlRoutable;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * @method static \Hypervel\Database\Eloquent\Collection<int, static> all(array|string $columns = ['*'])
 * @method static \Hypervel\Database\Eloquent\Builder<static> on($connection = null)
 * @method static \Hypervel\Database\Eloquent\Builder<static> onWriteConnection()
 * @method static \Hypervel\Database\Eloquent\Builder<static> query()
 * @method \Hypervel\Database\Eloquent\Builder<static> newQuery()
 * @method static \Hypervel\Database\Eloquent\Builder<static> newModelQuery()
 * @method static \Hypervel\Database\Eloquent\Builder<static> newQueryWithoutRelationships()
 * @method static \Hypervel\Database\Eloquent\Builder<static> newQueryWithoutScopes()
 * @method static \Hypervel\Database\Eloquent\Builder<static> newQueryWithoutScope($scope)
 * @method static \Hypervel\Database\Eloquent\Builder<static> newQueryForRestoration($ids)
 * @method static static make(array $attributes = [])
 * @method static static create(array $attributes = [])
 * @method static static forceCreate(array $attributes = [])
 * @method static static firstOrNew(array $attributes = [], array $values = [])
 * @method static static firstOrCreate(array $attributes = [], array $values = [])
 * @method static static updateOrCreate(array $attributes, array $values = [])
 * @method static null|static first(mixed $columns = ['*'])
 * @method static static firstOrFail(mixed $columns = ['*'])
 * @method static static sole(mixed $columns = ['*'])
 * @method static ($id is (array<mixed>|\Hyperf\Contract\Arrayable<array-key, mixed>) ? \Hypervel\Database\Eloquent\Collection<int, static> : null|static) find(mixed $id, array $columns = ['*'])
 * @method static ($id is (array<mixed>|\Hyperf\Contract\Arrayable<array-key, mixed>) ? \Hypervel\Database\Eloquent\Collection<int, static> : static) findOrNew(mixed $id, array $columns = ['*'])
 * @method static ($id is (array<mixed>|\Hyperf\Contract\Arrayable<array-key, mixed>) ? \Hypervel\Database\Eloquent\Collection<int, static> : static) findOrFail(mixed $id, array $columns = ['*'])
 * @method static \Hypervel\Database\Eloquent\Collection<int, static> findMany(array|\Hypervel\Support\Contracts\Arrayable $ids, array $columns = ['*'])
 * @method static \Hypervel\Database\Eloquent\Builder<static> where(mixed $column, mixed $operator = null, mixed $value = null, string $boolean = 'and')
 * @method static \Hypervel\Database\Eloquent\Builder<static> orWhere(mixed $column, mixed $operator = null, mixed $value = null)
 * @method static \Hypervel\Database\Eloquent\Builder<static> with(mixed $relations, mixed ...$args)
 * @method static \Hypervel\Database\Eloquent\Builder<static> without(mixed $relations)
 * @method static \Hypervel\Database\Eloquent\Builder<static> withWhereHas(string $relation, (\Closure(\Hypervel\Database\Eloquent\Builder<*>|\Hypervel\Database\Eloquent\Relations\Contracts\Relation<*, *, *>): mixed)|null $callback = null, string $operator = '>=', int $count = 1)
 * @method static \Hypervel\Database\Eloquent\Builder<static> whereMorphDoesntHaveRelation(mixed $relation, array|string $types, mixed $column, mixed $operator = null, mixed $value = null)
 * @method static \Hypervel\Database\Eloquent\Builder<static> orWhereMorphDoesntHaveRelation(mixed $relation, array|string $types, mixed $column, mixed $operator = null, mixed $value = null)
 * @method static \Hypervel\Database\Eloquent\Collection<int, static> get(array|string $columns = ['*'])
 * @method static \Hypervel\Database\Eloquent\Collection<int, static> hydrate(array $items)
 * @method static \Hypervel\Database\Eloquent\Collection<int, static> fromQuery(string $query, array $bindings = [])
 * @method static array<int, static> getModels(array|string $columns = ['*'])
 * @method static array<int, static> eagerLoadRelations(array $models)
 * @method static \Hypervel\Database\Eloquent\Relations\Contracts\Relation<\Hypervel\Database\Eloquent\Model, static, *> getRelation(string $name)
 * @method static static getModel()
 * @method static bool chunk(int $count, callable(\Hypervel\Support\Collection<int, static>, int): (bool|void) $callback)
 * @method static bool chunkById(int $count, callable(\Hypervel\Support\Collection<int, static>, int): (bool|void) $callback, null|string $column = null, null|string $alias = null)
 * @method static bool chunkByIdDesc(int $count, callable(\Hypervel\Support\Collection<int, static>, int): (bool|void) $callback, null|string $column = null, null|string $alias = null)
 * @method static bool each(callable(static, int): (bool|void) $callback, int $count = 1000)
 * @method static bool eachById(callable(static, int): (bool|void) $callback, int $count = 1000, null|string $column = null, null|string $alias = null)
 * @method static \Hypervel\Database\Eloquent\Builder<static> whereIn(string $column, mixed $values, string $boolean = 'and', bool $not = false)
 *
 * @mixin \Hypervel\Database\Eloquent\Builder
 */
abstract class Model extends BaseModel implements UrlRoutable, HasBroadcastChannel
{
    use HasAttributes;
    use HasBootableTraits;
    use HasCallbacks;
    use HasCollection;
    use HasGlobalScopes;
    use HasLocalScopes;
    use HasObservers;
    use HasRelations;
    use HasRelationships;
    use TransformsToResource;

    protected ?string $connection = null;

    public function resolveRouteBinding($value)
    {
        return $this->where($this->getRouteKeyName(), $value)->firstOrFail();
    }

    /**
     * @param \Hypervel\Database\Query\Builder $query
     * @return \Hypervel\Database\Eloquent\Builder<static>
     */
    public function newModelBuilder($query)
    {
        // @phpstan-ignore-next-line
        return new Builder($query);
    }

    public function broadcastChannelRoute(): string
    {
        return str_replace('\\', '.', get_class($this)) . '.{' . Str::camel(class_basename($this)) . '}';
    }

    public function broadcastChannel(): string
    {
        return str_replace('\\', '.', get_class($this)) . '.' . $this->getKey();
    }

    /**
     * @param \Hypervel\Database\Eloquent\Model $parent
     * @param string $table
     * @param bool $exists
     * @param null|string $using
     * @return \Hypervel\Database\Eloquent\Relations\Pivot
     */
    public function newPivot($parent, array $attributes, $table, $exists, $using = null)
    {
        return $using ? $using::fromRawAttributes($parent, $attributes, $table, $exists) : Pivot::fromAttributes($parent, $attributes, $table, $exists);
    }

    /**
     * @return string
     */
    protected function guessBelongsToRelation()
    {
        [$one, $two, $three, $caller] = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 4);

        return $caller['function'] ?? $three['function']; // @phpstan-ignore nullCoalesce.offset (defensive backtrace handling)
    }

    /**
     * Get the event dispatcher instance.
     */
    public function getEventDispatcher(): ?EventDispatcherInterface
    {
        if (Context::get($this->getWithoutEventContextKey())) {
            return null;
        }

        return parent::getEventDispatcher();
    }

    /**
     * Execute a callback without firing any model events for any model type.
     */
    public static function withoutEvents(callable $callback): mixed
    {
        $key = static::getWithoutEventContextKey();
        $depth = Context::get($key) ?? 0;
        Context::set($key, $depth + 1);

        try {
            return $callback();
        } finally {
            $depth = Context::get($key) ?? 1;
            if ($depth <= 1) {
                Context::destroy($key);
            } else {
                Context::set($key, $depth - 1);
            }
        }
    }

    /**
     * Save the model and all of its relationships without raising any events to the parent model.
     */
    public function pushQuietly(): bool
    {
        return static::withoutEvents(fn () => $this->push());
    }

    /**
     * Save the model to the database without raising any events.
     */
    public function saveQuietly(array $options = []): bool
    {
        return static::withoutEvents(fn () => $this->save($options));
    }

    /**
     * Update the model in the database without raising any events.
     *
     * @param array<string, mixed> $attributes
     * @param array<string, mixed> $options
     */
    public function updateQuietly(array $attributes = [], array $options = []): bool
    {
        if (! $this->exists) {
            return false;
        }

        return $this->fill($attributes)->saveQuietly($options);
    }

    /**
     * Increment a column's value by a given amount without raising any events.
     * @param mixed $amount
     */
    public function incrementQuietly(string $column, $amount = 1, array $extra = []): int
    {
        return static::withoutEvents(
            fn () => $this->incrementOrDecrement($column, $amount, $extra, 'increment')
        );
    }

    /**
     * Decrement a column's value by a given amount without raising any events.
     */
    public function decrementQuietly(string $column, float|int $amount = 1, array $extra = []): int
    {
        return static::withoutEvents(
            fn () => $this->incrementOrDecrement($column, $amount, $extra, 'decrement')
        );
    }

    /**
     * Delete the model from the database without raising any events.
     */
    public function deleteQuietly(): bool
    {
        return static::withoutEvents(fn () => $this->delete());
    }

    /**
     * Clone the model into a new, non-existing instance without raising any events.
     */
    public function replicateQuietly(?array $except = null): static
    {
        return static::withoutEvents(fn () => $this->replicate($except));
    }

    /**
     * Handle dynamic static method calls into the model.
     *
     * Checks for methods marked with the #[Scope] attribute before
     * falling back to the default behavior.
     *
     * @param string $method
     * @param array<int, mixed> $parameters
     * @return mixed
     */
    public static function __callStatic($method, $parameters)
    {
        if (static::isScopeMethodWithAttribute($method)) {
            return static::query()->{$method}(...$parameters);
        }

        return (new static())->{$method}(...$parameters);
    }

    protected static function getWithoutEventContextKey(): string
    {
        return '__database.model.without_events.' . static::class;
    }
}
