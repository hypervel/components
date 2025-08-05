<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent;

use Hyperf\DbConnection\Model\Model as BaseModel;
use Hyperf\Stringable\Str;
use Hypervel\Broadcasting\Contracts\HasBroadcastChannel;
use Hypervel\Database\Eloquent\Concerns\HasCallbacks;
use Hypervel\Database\Eloquent\Concerns\HasObservers;
use Hypervel\Database\Eloquent\Concerns\HasRelations;
use Hypervel\Database\Eloquent\Concerns\HasRelationships;
use Hypervel\Database\Eloquent\Relations\Pivot;
use Hypervel\Router\Contracts\UrlRoutable;

/**
 * @method static \Hypervel\Database\Eloquent\Collection<int, static> all(array|string $columns = ['*'])
 * @method static \Hypervel\Database\Eloquent\Builder<static> with($relations)
 * @method static \Hypervel\Database\Eloquent\Builder<static> on($connection = null)
 * @method static \Hypervel\Database\Eloquent\Builder<static> onWriteConnection()
 * @method static \Hypervel\Database\Eloquent\Builder<static> query()
 * @method \Hypervel\Database\Eloquent\Builder<static> newQuery()
 * @method static \Hypervel\Database\Eloquent\Builder<static> newModelQuery()
 * @method static \Hypervel\Database\Eloquent\Builder<static> newQueryWithoutRelationships()
 * @method static \Hypervel\Database\Eloquent\Builder<static> newQueryWithoutScopes()
 * @method static \Hypervel\Database\Eloquent\Builder<static> newQueryWithoutScope($scope)
 * @method static \Hypervel\Database\Eloquent\Builder<static> newQueryForRestoration($ids)
 * @method \Hypervel\Database\Eloquent\Builder<static> newEloquentBuilder($query)
 *
 * @mixin \Hypervel\Database\Eloquent\Builder
 */
abstract class Model extends BaseModel implements UrlRoutable, HasBroadcastChannel
{
    use HasCallbacks;
    use HasRelations;
    use HasRelationships;
    use HasObservers;

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

    /**
     * @param array<array-key, static> $models
     * @return \Hypervel\Database\Eloquent\Collection<array-key, static>
     */
    public function newCollection(array $models = [])
    {
        return new Collection($models);
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

        return $caller['function'] ?? $three['function'];
    }
}
