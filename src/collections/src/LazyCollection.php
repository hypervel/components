<?php

declare(strict_types=1);

namespace Hypervel\Support;

use ArrayIterator;
use Closure;
use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use Generator;
use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Contracts\Support\CanBeEscapedWhenCastToString;
use Hypervel\Support\Traits\EnumeratesValues;
use Hypervel\Support\Traits\Macroable;
use InvalidArgumentException;
use Iterator;
use IteratorAggregate;
use IteratorIterator;
use Override;
use stdClass;
use Traversable;
use UnitEnum;

/**
 * @template TKey of array-key
 *
 * @template-covariant TValue
 *
 * @implements Enumerable<TKey, TValue>
 */
class LazyCollection implements CanBeEscapedWhenCastToString, Enumerable
{
    /**
     * @use EnumeratesValues<TKey, TValue>
     */
    use EnumeratesValues;

    use Macroable;

    /**
     * The source from which to generate items.
     *
     * @var array<TKey, TValue>|(Closure(): Generator<TKey, TValue, mixed, void>)|static
     */
    public Closure|self|array $source;

    /**
     * Create a new lazy collection instance.
     *
     * @param null|array<TKey, TValue>|Arrayable<TKey, TValue>|(Closure(): Generator<TKey, TValue, mixed, void>)|iterable<TKey, TValue>|self<TKey, TValue> $source
     */
    public function __construct(mixed $source = null)
    {
        if ($source instanceof Closure || $source instanceof self) {
            $this->source = $source;
        } elseif (is_null($source)) {
            $this->source = static::empty();
        } elseif ($source instanceof Generator) {
            throw new InvalidArgumentException(
                'Generators should not be passed directly to LazyCollection. Instead, pass a generator function.'
            );
        } else {
            $this->source = $this->getArrayableItems($source);
        }
    }

    /**
     * Create a new collection instance if the value isn't one already.
     *
     * @template TMakeKey of array-key
     * @template TMakeValue
     *
     * @param null|array<TMakeKey, TMakeValue>|Arrayable<TMakeKey, TMakeValue>|(Closure(): Generator<TMakeKey, TMakeValue, mixed, void>)|iterable<TMakeKey, TMakeValue>|self<TMakeKey, TMakeValue> $items
     * @return static<TMakeKey, TMakeValue>
     */
    public static function make(mixed $items = []): static
    {
        return new static($items);
    }

    /**
     * Create a collection with the given range.
     *
     * @return static<int, int>
     */
    public static function range(int $from, int $to, int $step = 1): static
    {
        if ($step == 0) {
            throw new InvalidArgumentException('Step value cannot be zero.');
        }

        return new static(function () use ($from, $to, $step) {
            if ($from <= $to) {
                for (; $from <= $to; $from += abs($step)) {
                    yield $from;
                }
            } else {
                for (; $from >= $to; $from -= abs($step)) {
                    yield $from;
                }
            }
        });
    }

    /**
     * Get all items in the enumerable.
     *
     * @return array<TKey, TValue>
     */
    public function all(): array
    {
        if (is_array($this->source)) {
            return $this->source;
        }

        return iterator_to_array($this->getIterator());
    }

    /**
     * Eager load all items into a new lazy collection backed by an array.
     *
     * @return static<TKey, TValue>
     */
    public function eager(): static
    {
        return new static($this->all());
    }

    /**
     * Cache values as they're enumerated.
     *
     * @return static<TKey, TValue>
     */
    public function remember(): static
    {
        $iterator = $this->getIterator();

        $iteratorIndex = 0;

        $cache = [];

        return new static(function () use ($iterator, &$iteratorIndex, &$cache) {
            for ($index = 0; true; ++$index) {
                if (array_key_exists($index, $cache)) {
                    yield $cache[$index][0] => $cache[$index][1];

                    continue;
                }

                if ($iteratorIndex < $index) {
                    $iterator->next();

                    ++$iteratorIndex;
                }

                if (! $iterator->valid()) {
                    break;
                }

                $cache[$index] = [$iterator->key(), $iterator->current()];

                yield $cache[$index][0] => $cache[$index][1];
            }
        });
    }

    /**
     * Get the median of a given key.
     *
     * @param null|array<array-key, string>|string $key
     */
    public function median(string|array|null $key = null): float|int|null
    {
        return $this->collect()->median($key);
    }

    /**
     * Get the mode of a given key.
     *
     * @param null|array<string>|string $key
     * @return null|array<int, float|int>
     */
    public function mode(string|array|null $key = null): ?array
    {
        return $this->collect()->mode($key);
    }

    /**
     * Collapse the collection of items into a single array.
     *
     * @return static<int, mixed>
     */
    public function collapse()
    {
        return new static(function () {
            foreach ($this as $values) {
                if (is_array($values) || $values instanceof Enumerable) {
                    foreach ($values as $value) {
                        yield $value;
                    }
                }
            }
        });
    }

    /**
     * Collapse the collection of items into a single array while preserving its keys.
     *
     * @return static<array-key, mixed>
     */
    public function collapseWithKeys(): static
    {
        return new static(function () {
            foreach ($this as $values) {
                if (is_array($values) || $values instanceof Enumerable) {
                    foreach ($values as $key => $value) {
                        yield $key => $value;
                    }
                }
            }
        });
    }

    /**
     * Determine if an item exists in the enumerable.
     *
     * @param (callable(TValue, TKey): bool)|string|TValue $key
     */
    public function contains(mixed $key, mixed $operator = null, mixed $value = null): bool
    {
        if (func_num_args() === 1 && $this->useAsCallable($key)) {
            $placeholder = new stdClass();

            /** @var callable $key */
            return $this->first($key, $placeholder) !== $placeholder;
        }

        if (func_num_args() === 1) {
            $needle = $key;

            foreach ($this as $value) {
                if ($value == $needle) {
                    return true;
                }
            }

            return false;
        }

        return $this->contains($this->operatorForWhere(...func_get_args()));
    }

    /**
     * Determine if an item exists, using strict comparison.
     *
     * @param array-key|(callable(TValue): bool)|TValue $key
     * @param null|TValue $value
     */
    public function containsStrict(mixed $key, mixed $value = null): bool
    {
        if (func_num_args() === 2) {
            return $this->contains(fn ($item) => data_get($item, $key) === $value);
        }

        if ($this->useAsCallable($key)) {
            return ! is_null($this->first($key));
        }

        foreach ($this as $item) {
            if ($item === $key) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine if an item is not contained in the enumerable.
     */
    public function doesntContain(mixed $key, mixed $operator = null, mixed $value = null): bool
    {
        return ! $this->contains(...func_get_args());
    }

    /**
     * Determine if an item is not contained in the enumerable, using strict comparison.
     */
    public function doesntContainStrict(mixed $key, mixed $operator = null, mixed $value = null): bool
    {
        return ! $this->containsStrict(...func_get_args());
    }

    #[Override]
    public function crossJoin(Arrayable|iterable ...$arrays): static
    {
        // @phpstan-ignore return.type (passthru loses generic type info)
        return $this->passthru(__FUNCTION__, func_get_args());
    }

    /**
     * Count the number of items in the collection by a field or using a callback.
     *
     * @param null|(callable(TValue, TKey): (array-key|UnitEnum))|string $countBy
     * @return static<array-key, int>
     */
    public function countBy(callable|string|null $countBy = null)
    {
        $countBy = is_null($countBy)
            ? $this->identity()
            : $this->valueRetriever($countBy);

        return new static(function () use ($countBy) {
            $counts = [];

            foreach ($this as $key => $value) {
                $group = enum_value($countBy($value, $key));

                if (empty($counts[$group])) {
                    $counts[$group] = 0;
                }

                ++$counts[$group];
            }

            yield from $counts;
        });
    }

    #[Override]
    public function diff(mixed $items): static
    {
        return $this->passthru(__FUNCTION__, func_get_args());
    }

    #[Override]
    public function diffUsing(mixed $items, callable $callback): static
    {
        return $this->passthru(__FUNCTION__, func_get_args());
    }

    #[Override]
    public function diffAssoc(Arrayable|iterable $items): static
    {
        return $this->passthru(__FUNCTION__, func_get_args());
    }

    #[Override]
    public function diffAssocUsing(Arrayable|iterable $items, callable $callback): static
    {
        return $this->passthru(__FUNCTION__, func_get_args());
    }

    #[Override]
    public function diffKeys(Arrayable|iterable $items): static
    {
        return $this->passthru(__FUNCTION__, func_get_args());
    }

    #[Override]
    public function diffKeysUsing(Arrayable|iterable $items, callable $callback): static
    {
        return $this->passthru(__FUNCTION__, func_get_args());
    }

    #[Override]
    public function duplicates(callable|string|null $callback = null, bool $strict = false): static
    {
        return $this->passthru(__FUNCTION__, func_get_args());
    }

    #[Override]
    public function duplicatesStrict(callable|string|null $callback = null): static
    {
        return $this->passthru(__FUNCTION__, func_get_args());
    }

    #[Override]
    public function except(mixed $keys): static
    {
        return $this->passthru(__FUNCTION__, func_get_args());
    }

    /**
     * Run a filter over each of the items.
     *
     * @param null|(callable(TValue, TKey): bool) $callback
     */
    public function filter(?callable $callback = null): static
    {
        if (is_null($callback)) {
            $callback = fn ($value) => (bool) $value;
        }

        return new static(function () use ($callback) {
            foreach ($this as $key => $value) {
                if ($callback($value, $key)) {
                    yield $key => $value;
                }
            }
        });
    }

    /**
     * Get the first item from the enumerable passing the given truth test.
     *
     * @template TFirstDefault
     *
     * @param null|(callable(TValue, TKey): bool) $callback
     * @param (Closure(): TFirstDefault)|TFirstDefault $default
     * @return TFirstDefault|TValue
     */
    public function first(?callable $callback = null, mixed $default = null): mixed
    {
        $iterator = $this->getIterator();

        if (is_null($callback)) {
            if (! $iterator->valid()) {
                return value($default);
            }

            return $iterator->current();
        }

        foreach ($iterator as $key => $value) {
            if ($callback($value, $key)) {
                return $value;
            }
        }

        return value($default);
    }

    /**
     * Get a flattened list of the items in the collection.
     *
     * @return static<int, mixed>
     */
    public function flatten(int|float $depth = INF)
    {
        $instance = new static(function () use ($depth) {
            foreach ($this as $item) {
                if (! is_array($item) && ! $item instanceof Enumerable) {
                    yield $item;
                } elseif ($depth === 1) {
                    yield from $item;
                } else {
                    yield from (new static($item))->flatten($depth - 1);
                }
            }
        });

        return $instance->values();
    }

    /**
     * Flip the items in the collection.
     *
     * @return static<TValue, TKey>
     * @phpstan-ignore generics.notSubtype (TValue becomes key - only valid when TValue is array-key, but can't express this constraint)
     */
    public function flip()
    {
        return new static(function () {
            foreach ($this as $key => $value) {
                yield $value => $key;
            }
        });
    }

    /**
     * Get an item by key.
     *
     * @template TGetDefault
     *
     * @param null|TKey $key
     * @param (Closure(): TGetDefault)|TGetDefault $default
     * @return TGetDefault|TValue
     */
    public function get(mixed $key, mixed $default = null): mixed
    {
        if (is_null($key)) {
            return null;
        }

        foreach ($this as $outerKey => $outerValue) {
            if ($outerKey == $key) {
                return $outerValue;
            }
        }

        return value($default);
    }

    /**
     * @template TGroupKey of array-key|\UnitEnum|\Stringable
     *
     * @param array|(callable(TValue, TKey): TGroupKey)|string $groupBy
     * @return static<
     *  ($groupBy is (array|string)
     *      ? array-key
     *      : (TGroupKey is \UnitEnum ? array-key : (TGroupKey is \Stringable ? string : TGroupKey))),
     *  static<($preserveKeys is true ? TKey : int), ($groupBy is array ? mixed : TValue)>
     * >
     * @phpstan-ignore method.childReturnType, generics.notSubtype (complex conditional types PHPStan can't match)
     */
    #[Override]
    public function groupBy(callable|array|string $groupBy, bool $preserveKeys = false): static
    {
        // @phpstan-ignore return.type (passthru loses generic type info)
        return $this->passthru(__FUNCTION__, func_get_args());
    }

    /**
     * Key an associative array by a field or using a callback.
     *
     * @template TNewKey of array-key|\UnitEnum
     *
     * @param array|(callable(TValue, TKey): TNewKey)|string $keyBy
     * @return static<($keyBy is (array|string) ? array-key : (TNewKey is UnitEnum ? array-key : TNewKey)), TValue>
     * @phpstan-ignore method.childReturnType (complex conditional return type PHPStan can't verify)
     */
    public function keyBy(callable|array|string $keyBy): static
    {
        return new static(function () use ($keyBy) {
            $keyBy = $this->valueRetriever($keyBy);

            foreach ($this as $key => $item) {
                $resolvedKey = $keyBy($item, $key);

                if (is_object($resolvedKey)) {
                    $resolvedKey = (string) $resolvedKey;
                }

                yield $resolvedKey => $item;
            }
        });
    }

    /**
     * Determine if an item exists in the collection by key.
     */
    public function has(mixed $key): bool
    {
        $keys = array_flip(is_array($key) ? $key : func_get_args());
        $count = count($keys);

        foreach ($this as $key => $value) {
            if (array_key_exists($key, $keys) && --$count == 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine if any of the keys exist in the collection.
     */
    public function hasAny(mixed $key): bool
    {
        $keys = array_flip(is_array($key) ? $key : func_get_args());

        foreach ($this as $key => $value) {
            if (array_key_exists($key, $keys)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Concatenate values of a given key as a string.
     *
     * @param null|(callable(TValue, TKey): mixed)|string $value
     */
    public function implode(callable|string|null $value, ?string $glue = null): string
    {
        return $this->collect()->implode(...func_get_args());
    }

    #[Override]
    public function intersect(mixed $items): static
    {
        return $this->passthru(__FUNCTION__, func_get_args());
    }

    #[Override]
    public function intersectUsing(mixed $items, callable $callback): static
    {
        return $this->passthru(__FUNCTION__, func_get_args());
    }

    #[Override]
    public function intersectAssoc(mixed $items): static
    {
        return $this->passthru(__FUNCTION__, func_get_args());
    }

    #[Override]
    public function intersectAssocUsing(mixed $items, callable $callback): static
    {
        return $this->passthru(__FUNCTION__, func_get_args());
    }

    #[Override]
    public function intersectByKeys(mixed $items): static
    {
        return $this->passthru(__FUNCTION__, func_get_args());
    }

    /**
     * Determine if the items are empty or not.
     */
    public function isEmpty(): bool
    {
        return ! $this->getIterator()->valid();
    }

    /**
     * Determine if the collection contains a single item.
     */
    public function containsOneItem(?callable $callback = null): bool
    {
        return $this->hasSole($callback);
    }

    /**
     * Determine if the collection contains multiple items.
     */
    public function containsManyItems(): bool
    {
        return $this->hasMany();
    }

    /**
     * Join all items from the collection using a string. The final items can use a separate glue string.
     */
    public function join(string $glue, string $finalGlue = ''): string
    {
        return $this->collect()->join(...func_get_args());
    }

    /**
     * Get the keys of the collection items.
     *
     * @return static<int, TKey>
     */
    public function keys()
    {
        return new static(function () {
            foreach ($this as $key => $value) {
                yield $key;
            }
        });
    }

    /**
     * Get the last item from the collection.
     *
     * @template TLastDefault
     *
     * @param null|(callable(TValue, TKey): bool) $callback
     * @param (Closure(): TLastDefault)|TLastDefault $default
     * @return TLastDefault|TValue
     */
    public function last(?callable $callback = null, mixed $default = null): mixed
    {
        $needle = $placeholder = new stdClass();

        foreach ($this as $key => $value) {
            if (is_null($callback) || $callback($value, $key)) {
                $needle = $value;
            }
        }

        return $needle === $placeholder ? value($default) : $needle;
    }

    /**
     * Get the values of a given key.
     *
     * @param null|Closure|array<array-key, string>|int|string $value
     * @return static<array-key, mixed>
     */
    public function pluck(Closure|string|int|array|null $value, Closure|string|int|array|null $key = null)
    {
        return new static(function () use ($value, $key) {
            [$value, $key] = $this->explodePluckParameters($value, $key);

            foreach ($this as $item) {
                $itemValue = $value instanceof Closure
                    ? $value($item)
                    : data_get($item, $value);

                if (is_null($key)) {
                    yield $itemValue;
                } else {
                    $itemKey = $key instanceof Closure
                        ? $key($item)
                        : data_get($item, $key);

                    if (is_object($itemKey) && method_exists($itemKey, '__toString')) {
                        $itemKey = (string) $itemKey;
                    }

                    yield $itemKey => $itemValue;
                }
            }
        });
    }

    /**
     * Run a map over each of the items.
     *
     * @template TMapValue
     *
     * @param callable(TValue, TKey): TMapValue $callback
     * @return static<TKey, TMapValue>
     */
    public function map(callable $callback)
    {
        return new static(function () use ($callback) {
            foreach ($this as $key => $value) {
                yield $key => $callback($value, $key);
            }
        });
    }

    #[Override]
    public function mapToDictionary(callable $callback): static
    {
        // @phpstan-ignore return.type (passthru loses generic type info)
        return $this->passthru(__FUNCTION__, func_get_args());
    }

    /**
     * Run an associative map over each of the items.
     *
     * The callback should return an associative array with a single key/value pair.
     *
     * @template TMapWithKeysKey of array-key
     * @template TMapWithKeysValue
     *
     * @param callable(TValue, TKey): array<TMapWithKeysKey, TMapWithKeysValue> $callback
     * @return static<TMapWithKeysKey, TMapWithKeysValue>
     */
    public function mapWithKeys(callable $callback)
    {
        return new static(function () use ($callback) {
            foreach ($this as $key => $value) {
                yield from $callback($value, $key);
            }
        });
    }

    #[Override]
    public function merge(mixed $items): static
    {
        // @phpstan-ignore return.type (passthru loses generic type info)
        return $this->passthru(__FUNCTION__, func_get_args());
    }

    #[Override]
    public function mergeRecursive(mixed $items): static
    {
        // @phpstan-ignore return.type (passthru loses generic type info)
        return $this->passthru(__FUNCTION__, func_get_args());
    }

    /**
     * Multiply the items in the collection by the multiplier.
     */
    public function multiply(int $multiplier): static
    {
        return $this->passthru(__FUNCTION__, func_get_args());
    }

    /**
     * Create a collection by using this collection for keys and another for its values.
     *
     * @template TCombineValue
     *
     * @param Arrayable<array-key, TCombineValue>|(callable(): Generator<array-key, TCombineValue>)|iterable<array-key, TCombineValue> $values
     * @return static<TValue, TCombineValue>
     * @phpstan-ignore generics.notSubtype (TValue becomes key - only valid when TValue is array-key, but can't express this constraint)
     */
    public function combine(Arrayable|iterable|callable $values): static
    {
        return new static(function () use ($values) {
            if ($values instanceof Arrayable) {
                $values = $values->toArray();
            }

            $values = $this->makeIterator($values);

            $errorMessage = 'Both parameters should have an equal number of elements';

            foreach ($this as $key) {
                if (! $values->valid()) {
                    trigger_error($errorMessage, E_USER_WARNING);

                    break;
                }

                yield $key => $values->current();

                $values->next();
            }

            if ($values->valid()) {
                trigger_error($errorMessage, E_USER_WARNING);
            }
        });
    }

    #[Override]
    public function union(mixed $items): static
    {
        return $this->passthru(__FUNCTION__, func_get_args());
    }

    /**
     * Create a new collection consisting of every n-th element.
     *
     * @throws InvalidArgumentException
     */
    public function nth(int $step, int $offset = 0): static
    {
        if ($step < 1) {
            throw new InvalidArgumentException('Step value must be at least 1.');
        }

        return new static(function () use ($step, $offset) {
            $position = 0;

            foreach ($this->slice($offset) as $item) {
                if ($position % $step === 0) {
                    yield $item;
                }

                ++$position;
            }
        });
    }

    /**
     * Get the items with the specified keys.
     *
     * @param null|array<array-key, TKey>|Enumerable<array-key, TKey>|string $keys
     */
    public function only(mixed $keys): static
    {
        if ($keys instanceof Enumerable) {
            $keys = $keys->all();
        } elseif (! is_null($keys)) {
            $keys = is_array($keys) ? $keys : func_get_args();
        }

        return new static(function () use ($keys) {
            if (is_null($keys)) {
                yield from $this;

                return;
            }

            $keys = array_flip($keys);

            foreach ($this as $key => $value) {
                if (array_key_exists($key, $keys)) {
                    yield $key => $value;

                    unset($keys[$key]);

                    if (empty($keys)) {
                        break;
                    }
                }
            }
        });
    }

    /**
     * Select specific values from the items within the collection.
     *
     * @param null|array<array-key, TKey>|Enumerable<array-key, TKey>|string $keys
     */
    public function select(mixed $keys): static
    {
        if ($keys instanceof Enumerable) {
            $keys = $keys->all();
        } elseif (! is_null($keys)) {
            $keys = is_array($keys) ? $keys : func_get_args();
        }

        return new static(function () use ($keys) {
            if (is_null($keys)) {
                yield from $this;

                return;
            }

            foreach ($this as $item) {
                $result = [];

                foreach ($keys as $key) {
                    if (Arr::accessible($item) && Arr::exists($item, $key)) {
                        $result[$key] = $item[$key];
                    } elseif (is_object($item) && isset($item->{$key})) {
                        $result[$key] = $item->{$key};
                    }
                }

                yield $result;
            }
        });
    }

    /**
     * Push all of the given items onto the collection.
     *
     * @template TConcatKey of array-key
     * @template TConcatValue
     *
     * @param iterable<TConcatKey, TConcatValue> $source
     * @return static<TConcatKey|TKey, TConcatValue|TValue>
     */
    public function concat(iterable $source): static
    {
        return (new static(function () use ($source) {
            yield from $this;
            yield from $source;
        }))->values();
    }

    /**
     * Get one or a specified number of items randomly from the collection.
     *
     * @return static<int, TValue>|TValue
     *
     * @throws InvalidArgumentException
     */
    public function random(callable|int|string|null $number = null): mixed
    {
        $result = $this->collect()->random(...func_get_args());

        return is_null($number) ? $result : new static($result);
    }

    /**
     * Replace the collection items with the given items.
     *
     * @param Arrayable<TKey, TValue>|iterable<TKey, TValue> $items
     */
    public function replace(mixed $items): static
    {
        return new static(function () use ($items) {
            $items = $this->getArrayableItems($items);

            foreach ($this as $key => $value) {
                if (array_key_exists($key, $items)) {
                    yield $key => $items[$key];

                    unset($items[$key]);
                } else {
                    yield $key => $value;
                }
            }

            foreach ($items as $key => $value) {
                yield $key => $value;
            }
        });
    }

    #[Override]
    public function replaceRecursive(mixed $items): static
    {
        return $this->passthru(__FUNCTION__, func_get_args());
    }

    #[Override]
    public function reverse(): static
    {
        return $this->passthru(__FUNCTION__, func_get_args());
    }

    /**
     * Search the collection for a given value and return the corresponding key if successful.
     *
     * @param (callable(TValue,TKey): bool)|TValue $value
     * @return false|TKey
     */
    public function search(mixed $value, bool $strict = false): mixed
    {
        /** @var (callable(TValue,TKey): bool) $predicate */
        $predicate = $this->useAsCallable($value)
            ? $value
            : function ($item) use ($value, $strict) {
                return $strict ? $item === $value : $item == $value;
            };

        foreach ($this as $key => $item) {
            if ($predicate($item, $key)) {
                return $key;
            }
        }

        return false;
    }

    /**
     * Get the item before the given item.
     *
     * @param (callable(TValue,TKey): bool)|TValue $value
     * @return null|TValue
     */
    public function before(mixed $value, bool $strict = false): mixed
    {
        $previous = null;

        /** @var (callable(TValue,TKey): bool) $predicate */
        $predicate = $this->useAsCallable($value)
            ? $value
            : function ($item) use ($value, $strict) {
                return $strict ? $item === $value : $item == $value;
            };

        foreach ($this as $key => $item) {
            if ($predicate($item, $key)) {
                return $previous;
            }

            $previous = $item;
        }

        return null;
    }

    /**
     * Get the item after the given item.
     *
     * @param (callable(TValue,TKey): bool)|TValue $value
     * @return null|TValue
     */
    public function after(mixed $value, bool $strict = false): mixed
    {
        $found = false;

        /** @var (callable(TValue,TKey): bool) $predicate */
        $predicate = $this->useAsCallable($value)
            ? $value
            : function ($item) use ($value, $strict) {
                return $strict ? $item === $value : $item == $value;
            };

        foreach ($this as $key => $item) {
            if ($found) {
                return $item;
            }

            if ($predicate($item, $key)) {
                $found = true;
            }
        }

        return null;
    }

    #[Override]
    public function shuffle(): static
    {
        return $this->passthru(__FUNCTION__, []);
    }

    /**
     * Create chunks representing a "sliding window" view of the items in the collection.
     *
     * @return static<int, static>
     *
     * @throws InvalidArgumentException
     */
    public function sliding(int $size = 2, int $step = 1): static
    {
        if ($size < 1) {
            throw new InvalidArgumentException('Size value must be at least 1.');
        }
        if ($step < 1) {
            throw new InvalidArgumentException('Step value must be at least 1.');
        }

        return new static(function () use ($size, $step) {
            $iterator = $this->getIterator();

            $chunk = [];

            while ($iterator->valid()) {
                $chunk[$iterator->key()] = $iterator->current();

                if (count($chunk) == $size) {
                    yield (new static($chunk))->tap(function () use (&$chunk, $step) {
                        $chunk = array_slice($chunk, $step, null, true);
                    });

                    // If the $step between chunks is bigger than each chunk's $size
                    // we will skip the extra items (which should never be in any
                    // chunk) before we continue to the next chunk in the loop.
                    if ($step > $size) {
                        $skip = $step - $size;

                        for ($i = 0; $i < $skip && $iterator->valid(); ++$i) {
                            $iterator->next();
                        }
                    }
                }

                $iterator->next();
            }
        });
    }

    /**
     * Skip the first {$count} items.
     */
    public function skip(int $count): static
    {
        return new static(function () use ($count) {
            $iterator = $this->getIterator();

            while ($iterator->valid() && $count--) {
                $iterator->next();
            }

            while ($iterator->valid()) {
                yield $iterator->key() => $iterator->current();

                $iterator->next();
            }
        });
    }

    /**
     * Skip items in the collection until the given condition is met.
     *
     * @param callable(TValue,TKey): bool|TValue $value
     */
    public function skipUntil(mixed $value): static
    {
        $callback = $this->useAsCallable($value) ? $value : $this->equality($value);

        return $this->skipWhile($this->negate($callback));
    }

    /**
     * Skip items in the collection while the given condition is met.
     *
     * @param callable(TValue,TKey): bool|TValue $value
     */
    public function skipWhile(mixed $value): static
    {
        $callback = $this->useAsCallable($value) ? $value : $this->equality($value);

        return new static(function () use ($callback) {
            $iterator = $this->getIterator();

            while ($iterator->valid() && $callback($iterator->current(), $iterator->key())) {
                $iterator->next();
            }

            while ($iterator->valid()) {
                yield $iterator->key() => $iterator->current();

                $iterator->next();
            }
        });
    }

    #[Override]
    public function slice(int $offset, ?int $length = null): static
    {
        if ($offset < 0 || $length < 0) {
            return $this->passthru(__FUNCTION__, func_get_args());
        }

        $instance = $this->skip($offset);

        return is_null($length) ? $instance : $instance->take($length);
    }

    /**
     * @throws InvalidArgumentException
     */
    #[Override]
    public function split(int $numberOfGroups): static
    {
        if ($numberOfGroups < 1) {
            throw new InvalidArgumentException('Number of groups must be at least 1.');
        }

        // @phpstan-ignore return.type (passthru loses generic type info)
        return $this->passthru(__FUNCTION__, func_get_args());
    }

    /**
     * Get the first item in the collection, but only if exactly one item exists. Otherwise, throw an exception.
     *
     * @param null|(callable(TValue, TKey): bool)|string $key
     * @return TValue
     *
     * @throws ItemNotFoundException
     * @throws MultipleItemsFoundException
     */
    public function sole(callable|string|null $key = null, mixed $operator = null, mixed $value = null): mixed
    {
        $filter = func_num_args() > 1
            ? $this->operatorForWhere(...func_get_args())
            : $key;

        return $this
            ->unless($filter == null)
            ->filter($filter)
            ->take(2)
            ->collect()
            ->sole();
    }

    /**
     * Determine if the collection contains a single item or a single item matching the given criteria.
     *
     * @param null|(callable(TValue, TKey): bool)|string $key
     */
    public function hasSole(callable|string|null $key = null, mixed $operator = null, mixed $value = null): bool
    {
        $filter = func_num_args() > 1
            ? $this->operatorForWhere(...func_get_args())
            : $key;

        return $this
            ->unless($filter == null)
            ->filter($filter)
            ->take(2)
            ->count() === 1;
    }

    /**
     * Get the first item in the collection but throw an exception if no matching items exist.
     *
     * @param null|(callable(TValue, TKey): bool)|string $key
     * @return TValue
     *
     * @throws ItemNotFoundException
     */
    public function firstOrFail(callable|string|null $key = null, mixed $operator = null, mixed $value = null): mixed
    {
        $filter = func_num_args() > 1
            ? $this->operatorForWhere(...func_get_args())
            : $key;

        return $this
            ->unless($filter == null)
            ->filter($filter)
            ->take(1)
            ->collect()
            ->firstOrFail();
    }

    /**
     * Chunk the collection into chunks of the given size.
     *
     * @return ($preserveKeys is true ? static<int, static> : static<int, static<int, TValue>>)
     */
    public function chunk(int $size, bool $preserveKeys = true): static
    {
        if ($size <= 0) {
            return static::empty();
        }

        $add = match ($preserveKeys) {
            true => fn (array &$chunk, Iterator $iterator) => $chunk[$iterator->key()] = $iterator->current(),
            false => fn (array &$chunk, Iterator $iterator) => $chunk[] = $iterator->current(),
        };

        return new static(function () use ($size, $add) {
            $iterator = $this->getIterator();

            while ($iterator->valid()) {
                $chunk = [];

                while (true) {
                    $add($chunk, $iterator);

                    if (count($chunk) < $size) {
                        $iterator->next();

                        if (! $iterator->valid()) {
                            break;
                        }
                    } else {
                        break;
                    }
                }

                yield new static($chunk);

                $iterator->next();
            }
        });
    }

    /**
     * Split a collection into a certain number of groups, and fill the first groups completely.
     *
     * @return static<int, static>
     *
     * @throws InvalidArgumentException
     */
    public function splitIn(int $numberOfGroups): static
    {
        if ($numberOfGroups < 1) {
            throw new InvalidArgumentException('Number of groups must be at least 1.');
        }

        return $this->chunk((int) ceil($this->count() / $numberOfGroups));
    }

    /**
     * Chunk the collection into chunks with a callback.
     *
     * @param callable(TValue, TKey, static<int, TValue>): bool $callback
     * @return static<int, static<int, TValue>>
     */
    public function chunkWhile(callable $callback): static
    {
        return new static(function () use ($callback) {
            $iterator = $this->getIterator();

            $chunk = new Collection();

            if ($iterator->valid()) {
                $chunk[$iterator->key()] = $iterator->current();

                $iterator->next();
            }

            while ($iterator->valid()) {
                // @phpstan-ignore argument.type (callback typed for static but receives Collection chunk)
                if (! $callback($iterator->current(), $iterator->key(), $chunk)) {
                    yield new static($chunk);

                    $chunk = new Collection();
                }

                $chunk[$iterator->key()] = $iterator->current();

                $iterator->next();
            }

            // @phpstan-ignore method.impossibleType (PHPStan infers Collection<*NEVER*, *NEVER*>)
            if ($chunk->isNotEmpty()) {
                yield new static($chunk);
            }
        });
    }

    #[Override]
    public function sort(callable|int|null $callback = null): static
    {
        return $this->passthru(__FUNCTION__, func_get_args());
    }

    #[Override]
    public function sortDesc(int $options = SORT_REGULAR): static
    {
        return $this->passthru(__FUNCTION__, func_get_args());
    }

    #[Override]
    public function sortBy(callable|array|string $callback, int $options = SORT_REGULAR, bool $descending = false): static
    {
        return $this->passthru(__FUNCTION__, func_get_args());
    }

    #[Override]
    public function sortByDesc(callable|array|string $callback, int $options = SORT_REGULAR): static
    {
        return $this->passthru(__FUNCTION__, func_get_args());
    }

    #[Override]
    public function sortKeys(int $options = SORT_REGULAR, bool $descending = false): static
    {
        return $this->passthru(__FUNCTION__, func_get_args());
    }

    #[Override]
    public function sortKeysDesc(int $options = SORT_REGULAR): static
    {
        return $this->passthru(__FUNCTION__, func_get_args());
    }

    #[Override]
    public function sortKeysUsing(callable $callback): static
    {
        return $this->passthru(__FUNCTION__, func_get_args());
    }

    /**
     * Take the first or last {$limit} items.
     *
     * @return static<TKey, TValue>
     */
    public function take(int $limit): static
    {
        if ($limit < 0) {
            return new static(function () use ($limit) {
                $limit = abs($limit);
                $ringBuffer = [];
                $position = 0;

                foreach ($this as $key => $value) {
                    $ringBuffer[$position] = [$key, $value];
                    $position = ($position + 1) % $limit;
                }

                for ($i = 0, $end = min($limit, count($ringBuffer)); $i < $end; ++$i) {
                    $pointer = ($position + $i) % $limit;
                    yield $ringBuffer[$pointer][0] => $ringBuffer[$pointer][1];
                }
            });
        }

        return new static(function () use ($limit) {
            $iterator = $this->getIterator();

            while ($limit--) {
                if (! $iterator->valid()) {
                    break;
                }

                yield $iterator->key() => $iterator->current();

                if ($limit) {
                    $iterator->next();
                }
            }
        });
    }

    /**
     * Take items in the collection until the given condition is met.
     *
     * @param callable(TValue,TKey): bool|TValue $value
     * @return static<TKey, TValue>
     */
    public function takeUntil(mixed $value): static
    {
        /** @var callable(TValue, TKey): bool $callback */
        $callback = $this->useAsCallable($value) ? $value : $this->equality($value);

        return new static(function () use ($callback) {
            foreach ($this as $key => $item) {
                if ($callback($item, $key)) {
                    break;
                }

                yield $key => $item;
            }
        });
    }

    /**
     * Take items in the collection until a given point in time, with an optional callback on timeout.
     *
     * @param null|callable(null|TValue, null|TKey): mixed $callback
     * @return static<TKey, TValue>
     */
    public function takeUntilTimeout(DateTimeInterface $timeout, ?callable $callback = null): static
    {
        $timeout = $timeout->getTimestamp();

        return new static(function () use ($timeout, $callback) {
            if ($this->now() >= $timeout) {
                if ($callback) {
                    $callback(null, null);
                }

                return;
            }

            foreach ($this as $key => $value) {
                yield $key => $value;

                if ($this->now() >= $timeout) {
                    if ($callback) {
                        $callback($value, $key);
                    }

                    break;
                }
            }
        });
    }

    /**
     * Take items in the collection while the given condition is met.
     *
     * @param callable(TValue,TKey): bool|TValue $value
     * @return static<TKey, TValue>
     */
    public function takeWhile(mixed $value): static
    {
        /** @var callable(TValue, TKey): bool $callback */
        $callback = $this->useAsCallable($value) ? $value : $this->equality($value);

        return $this->takeUntil(fn ($item, $key) => ! $callback($item, $key));
    }

    /**
     * Pass each item in the collection to the given callback, lazily.
     *
     * @param callable(TValue, TKey): mixed $callback
     * @return static<TKey, TValue>
     */
    public function tapEach(callable $callback): static
    {
        return new static(function () use ($callback) {
            foreach ($this as $key => $value) {
                $callback($value, $key);

                yield $key => $value;
            }
        });
    }

    /**
     * Throttle the values, releasing them at most once per the given seconds.
     *
     * @return static<TKey, TValue>
     */
    public function throttle(float $seconds): static
    {
        return new static(function () use ($seconds) {
            $microseconds = $seconds * 1_000_000;

            foreach ($this as $key => $value) {
                $fetchedAt = $this->preciseNow();

                yield $key => $value;

                $sleep = $microseconds - ($this->preciseNow() - $fetchedAt);

                $this->usleep((int) $sleep);
            }
        });
    }

    /**
     * Flatten a multi-dimensional associative array with dots.
     */
    public function dot(): static
    {
        return $this->passthru(__FUNCTION__, []);
    }

    #[Override]
    public function undot(): static
    {
        return $this->passthru(__FUNCTION__, []);
    }

    /**
     * Return only unique items from the collection array.
     *
     * @param null|(callable(TValue, TKey): mixed)|string $key
     * @return static<TKey, TValue>
     */
    public function unique(callable|string|null $key = null, bool $strict = false): static
    {
        $callback = $this->valueRetriever($key);

        return new static(function () use ($callback, $strict) {
            $exists = [];

            foreach ($this as $key => $item) {
                if (! in_array($id = $callback($item, $key), $exists, $strict)) {
                    yield $key => $item;

                    $exists[] = $id;
                }
            }
        });
    }

    /**
     * Reset the keys on the underlying array.
     *
     * @return static<int, TValue>
     */
    public function values(): static
    {
        return new static(function () {
            foreach ($this as $item) {
                yield $item;
            }
        });
    }

    /**
     * Run the given callback every time the interval has passed.
     *
     * @return static<TKey, TValue>
     */
    public function withHeartbeat(DateInterval|int $interval, callable $callback): static
    {
        $seconds = is_int($interval) ? $interval : $this->intervalSeconds($interval);

        return new static(function () use ($seconds, $callback) {
            $start = $this->now();

            foreach ($this as $key => $value) {
                $now = $this->now();

                if (($now - $start) >= $seconds) {
                    $callback();

                    $start = $now;
                }

                yield $key => $value;
            }
        });
    }

    /**
     * Get the total seconds from the given interval.
     */
    protected function intervalSeconds(DateInterval $interval): int
    {
        $start = new DateTimeImmutable();

        return $start->add($interval)->getTimestamp() - $start->getTimestamp();
    }

    /**
     * Zip the collection together with one or more arrays.
     *
     * e.g. new LazyCollection([1, 2, 3])->zip([4, 5, 6]);
     *      => [[1, 4], [2, 5], [3, 6]]
     *
     * @template TZipValue
     *
     * @param Arrayable<array-key, TZipValue>|iterable<array-key, TZipValue> ...$items
     * @return static<int, static<int, TValue|TZipValue>>
     */
    public function zip(Arrayable|iterable ...$items)
    {
        $iterables = func_get_args();

        return new static(function () use ($iterables) {
            $iterators = (new Collection($iterables))
                ->map(fn ($iterable) => $this->makeIterator($iterable))
                ->prepend($this->getIterator());

            while ($iterators->contains->valid()) {
                yield new static($iterators->map->current());

                $iterators->each->next();
            }
        });
    }

    #[Override]
    public function pad(int $size, mixed $value)
    {
        if ($size < 0) {
            return $this->passthru(__FUNCTION__, func_get_args());
        }

        return new static(function () use ($size, $value) {
            $yielded = 0;

            foreach ($this as $index => $item) {
                yield $index => $item;

                ++$yielded;
            }

            while ($yielded++ < $size) {
                yield $value;
            }
        });
    }

    /**
     * Get the values iterator.
     *
     * @return Iterator<TKey, TValue>
     */
    public function getIterator(): Iterator
    {
        return $this->makeIterator($this->source);
    }

    /**
     * Count the number of items in the collection.
     */
    public function count(): int
    {
        if (is_array($this->source)) {
            return count($this->source);
        }

        return iterator_count($this->getIterator());
    }

    /**
     * Make an iterator from the given source.
     *
     * @template TIteratorKey of array-key
     * @template TIteratorValue
     *
     * @param array<TIteratorKey, TIteratorValue>|(callable(): Generator<TIteratorKey, TIteratorValue>)|IteratorAggregate<TIteratorKey, TIteratorValue> $source
     * @return Iterator<TIteratorKey, TIteratorValue>
     */
    protected function makeIterator(IteratorAggregate|array|callable $source): Iterator
    {
        if ($source instanceof IteratorAggregate) {
            $iterator = $source->getIterator();

            return $iterator instanceof Iterator ? $iterator : new IteratorIterator($iterator);
        }

        if (is_array($source)) {
            return new ArrayIterator($source);
        }

        // Only callable remains at this point
        $maybeTraversable = $source();

        // @phpstan-ignore instanceof.alwaysTrue (PHPDoc says Generator but runtime callable could return anything)
        if ($maybeTraversable instanceof Iterator) {
            return $maybeTraversable;
        }

        // @phpstan-ignore deadCode.unreachable (defensive - handles non-Iterator Traversables)
        if ($maybeTraversable instanceof Traversable) {
            return new IteratorIterator($maybeTraversable);
        }

        return new ArrayIterator(Arr::wrap($maybeTraversable));
    }

    /**
     * Explode the "value" and "key" arguments passed to "pluck".
     *
     * @return array
     */
    protected function explodePluckParameters(Closure|string|int|array|null $value, Closure|string|int|array|null $key): array
    {
        $value = is_string($value) ? explode('.', $value) : $value;

        $key = is_null($key) || is_array($key) || is_int($key) || $key instanceof Closure ? $key : explode('.', $key);

        return [$value, $key];
    }

    /**
     * Pass this lazy collection through a method on the collection class.
     *
     * @param array<mixed> $params
     */
    protected function passthru(string $method, array $params): static
    {
        return new static(function () use ($method, $params) {
            yield from $this->collect()->{$method}(...$params);
        });
    }

    /**
     * Get the current time.
     */
    protected function now(): int
    {
        return class_exists(Carbon::class)
            ? Carbon::now()->timestamp
            : time();
    }

    /**
     * Get the precise current time.
     */
    protected function preciseNow(): float
    {
        return class_exists(Carbon::class)
            ? Carbon::now()->getPreciseTimestamp()
            : microtime(true) * 1_000_000;
    }

    /**
     * Sleep for the given amount of microseconds.
     */
    protected function usleep(int $microseconds): void
    {
        if ($microseconds <= 0) {
            return;
        }

        class_exists(Sleep::class)
            ? Sleep::usleep($microseconds)
            : usleep($microseconds);
    }
}
