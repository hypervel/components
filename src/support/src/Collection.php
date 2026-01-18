<?php

declare(strict_types=1);

namespace Hypervel\Support;

use Closure;
use Hyperf\Collection\Collection as BaseCollection;
use Hyperf\Collection\Enumerable;
use Hypervel\Support\Traits\TransformsToResourceCollection;
use Stringable;
use UnitEnum;

/**
 * @template TKey of array-key
 * @template TValue
 *
 * @extends \Hyperf\Collection\Collection<TKey, TValue>
 */
class Collection extends BaseCollection
{
    use TransformsToResourceCollection;

    /**
     * Group an associative array by a field or using a callback.
     *
     * Supports UnitEnum and Stringable keys, converting them to array keys.
     */
    public function groupBy(mixed $groupBy, bool $preserveKeys = false): Enumerable
    {
        if (is_array($groupBy)) {
            $nextGroups = $groupBy;
            $groupBy = array_shift($nextGroups);
        }

        $groupBy = $this->valueRetriever($groupBy);
        $results = [];

        foreach ($this->items as $key => $value) {
            $groupKeys = $groupBy($value, $key);

            if (! is_array($groupKeys)) {
                $groupKeys = [$groupKeys];
            }

            foreach ($groupKeys as $groupKey) {
                $groupKey = match (true) {
                    is_bool($groupKey) => (int) $groupKey,
                    $groupKey instanceof UnitEnum => enum_value($groupKey),
                    $groupKey instanceof Stringable => (string) $groupKey,
                    is_null($groupKey) => (string) $groupKey,
                    default => $groupKey,
                };

                if (! array_key_exists($groupKey, $results)) {
                    $results[$groupKey] = new static();
                }

                $results[$groupKey]->offsetSet($preserveKeys ? $key : null, $value);
            }
        }

        $result = new static($results);

        if (! empty($nextGroups)) {
            return $result->map->groupBy($nextGroups, $preserveKeys);
        }

        return $result;
    }

    /**
     * Key an associative array by a field or using a callback.
     *
     * Supports UnitEnum keys, converting them to array keys via enum_value().
     */
    public function keyBy(mixed $keyBy): static
    {
        $keyBy = $this->valueRetriever($keyBy);
        $results = [];

        foreach ($this->items as $key => $item) {
            $resolvedKey = $keyBy($item, $key);

            if ($resolvedKey instanceof UnitEnum) {
                $resolvedKey = enum_value($resolvedKey);
            }

            if (is_object($resolvedKey)) {
                $resolvedKey = (string) $resolvedKey;
            }

            $results[$resolvedKey] = $item;
        }

        return new static($results);
    }

    /**
     * Get a lazy collection for the items in this collection.
     *
     * @return \Hypervel\Support\LazyCollection<TKey, TValue>
     */
    public function lazy(): LazyCollection
    {
        return new LazyCollection($this->items);
    }

    /**
     * Results array of items from Collection or Arrayable.
     *
     * @return array<TKey, TValue>
     */
    protected function getArrayableItems(mixed $items): array
    {
        if ($items instanceof UnitEnum) {
            return [$items];
        }

        return parent::getArrayableItems($items);
    }

    /**
     * Get an operator checker callback.
     *
     * @param callable|string $key
     * @param null|string $operator
     */
    protected function operatorForWhere(mixed $key, mixed $operator = null, mixed $value = null): callable|Closure
    {
        if ($this->useAsCallable($key)) {
            return $key;
        }

        if (func_num_args() === 1) {
            $value = true;
            $operator = '=';
        }

        if (func_num_args() === 2) {
            $value = $operator;
            $operator = '=';
        }

        return function ($item) use ($key, $operator, $value) {
            $retrieved = enum_value(data_get($item, $key));
            $value = enum_value($value);

            $strings = array_filter([$retrieved, $value], function ($value) {
                return match (true) {
                    is_string($value) => true,
                    $value instanceof Stringable => true,
                    default => false,
                };
            });

            if (count($strings) < 2 && count(array_filter([$retrieved, $value], 'is_object')) == 1) {
                return in_array($operator, ['!=', '<>', '!==']);
            }

            return match ($operator) {
                '=', '==' => $retrieved == $value,
                '!=', '<>' => $retrieved != $value,
                '<' => $retrieved < $value,
                '>' => $retrieved > $value,
                '<=' => $retrieved <= $value,
                '>=' => $retrieved >= $value,
                '===' => $retrieved === $value,
                '!==' => $retrieved !== $value,
                '<=>' => $retrieved <=> $value,
                default => $retrieved == $value,
            };
        };
    }
}
