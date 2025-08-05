<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent;

use Hyperf\Collection\Enumerable;
use Hyperf\Database\Model\Collection as BaseCollection;
use Hypervel\Support\Collection as SupportCollection;

/**
 * @template TKey of array-key
 * @template TModel of \Hypervel\Database\Eloquent\Model
 *
 * @extends \Hyperf\Database\Model\Collection<TKey, TModel>
 *
 * @method $this load($relations)
 * @method $this loadMissing($relations)
 * @method $this loadMorph(string $relation, $relations)
 * @method $this loadAggregate($relations, string $column, string $function = null)
 * @method $this loadCount($relations)
 * @method $this loadMax($relations, string $column)
 * @method $this loadMin($relations, string $column)
 * @method $this loadSum($relations, string $column)
 * @method $this loadAvg($relations, string $column)
 * @method $this loadExists($relations)
 * @method $this loadMorphCount(string $relation, $relations)
 * @method $this makeVisible($attributes)
 * @method $this makeHidden($attributes)
 * @method $this append($attributes)
 * @method $this diff($items)
 * @method $this intersect($items)
 * @method $this unique((callable(TModel, TKey): mixed)|string|null $key = null, bool $strict = false)
 * @method $this only($keys)
 * @method $this except($keys)
 * @method $this merge($items)
 * @method \Hypervel\Database\Eloquent\Collection<TKey, TModel> fresh($with = [])
 * @method \Hypervel\Database\Eloquent\Builder<TModel> toQuery()
 * @method array<int, array-key> modelKeys()
 * @method array<int, mixed> getQueueableIds()
 * @method array<int, string> getQueueableRelations()
 */
class Collection extends BaseCollection
{
    /**
     * @template TFindDefault
     *
     * @param mixed $key
     * @param TFindDefault $default
     * @return ($key is (array<mixed>|\Hyperf\Contract\Arrayable<array-key, mixed>) ? static : TFindDefault|TModel)
     */
    public function find($key, $default = null)
    {
        return parent::find($key, $default);
    }

    /**
     * @param null|array<array-key, string>|string $value
     * @param null|string $key
     * @return \Hypervel\Support\Collection<array-key, mixed>
     */
    public function pluck($value, $key = null): Enumerable
    {
        return $this->toBase()->pluck($value, $key);
    }

    /**
     * @return \Hypervel\Support\Collection<int, TKey>
     */
    public function keys(): Enumerable
    {
        return $this->toBase()->keys();
    }

    /**
     * @template TZipValue
     *
     * @param \Hyperf\Contract\Arrayable<array-key, TZipValue>|iterable<array-key, TZipValue> ...$items
     * @return \Hypervel\Support\Collection<int, \Hypervel\Support\Collection<int, TModel|TZipValue>>
     */
    public function zip($items): Enumerable
    {
        return $this->toBase()->zip(...func_get_args());
    }

    /**
     * @return \Hypervel\Support\Collection<int, mixed>
     */
    public function collapse(): Enumerable
    {
        return $this->toBase()->collapse();
    }

    /**
     * @param int $depth
     * @return \Hypervel\Support\Collection<int, mixed>
     */
    public function flatten($depth = INF): Enumerable
    {
        return $this->toBase()->flatten($depth);
    }

    /**
     * @return \Hypervel\Support\Collection<TModel, TKey>
     */
    public function flip(): Enumerable
    {
        return $this->toBase()->flip();
    }

    /**
     * @template TPadValue
     *
     * @param int $size
     * @param TPadValue $value
     * @return \Hypervel\Support\Collection<int, TModel|TPadValue>
     */
    public function pad($size, $value): Enumerable
    {
        return $this->toBase()->pad($size, $value);
    }

    /**
     * @template TMapValue
     *
     * @param callable(TModel, TKey): TMapValue $callback
     * @return \Hypervel\Support\Collection<TKey, TMapValue>|static<TKey, TMapValue>
     */
    public function map(callable $callback): Enumerable
    {
        $result = parent::map($callback);

        return $result->contains(function ($item) {
            return ! $item instanceof Model;
        }) ? $result->toBase() : $result;
    }

    /**
     * @template TMapWithKeysKey of array-key
     * @template TMapWithKeysValue
     *
     * @param callable(TModel, TKey): array<TMapWithKeysKey, TMapWithKeysValue> $callback
     * @return \Hypervel\Support\Collection<TMapWithKeysKey, TMapWithKeysValue>|static<TMapWithKeysKey, TMapWithKeysValue>
     */
    public function mapWithKeys(callable $callback): Enumerable
    {
        $result = parent::mapWithKeys($callback);

        return $result->contains(fn ($item) => ! $item instanceof Model) ? $result->toBase() : $result;
    }

    /**
     * @return SupportCollection<TKey, TValue>
     */
    public function toBase()
    {
        return new SupportCollection($this);
    }
}
