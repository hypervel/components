<?php

declare(strict_types=1);

namespace Hypervel\Support;

use ArgumentCountError;
use ArrayAccess;
use Closure;
use Hyperf\Macroable\Macroable;
use Hypervel\Support\Enumerable;
use Hypervel\Support\ItemNotFoundException;
use Hypervel\Support\MultipleItemsFoundException;
use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Contracts\Support\Jsonable;
use InvalidArgumentException;
use JsonSerializable;
use Random\Randomizer;
use Traversable;
use WeakMap;

class Arr
{
    use Macroable;

    /**
     * Determine whether the given value is array accessible.
     */
    public static function accessible(mixed $value): bool
    {
        return is_array($value) || $value instanceof ArrayAccess;
    }

    /**
     * Determine whether the given value is arrayable.
     */
    public static function arrayable(mixed $value): bool
    {
        return is_array($value)
            || $value instanceof Arrayable
            || $value instanceof Traversable
            || $value instanceof Jsonable
            || $value instanceof JsonSerializable;
    }

    /**
     * Add an element to an array using "dot" notation if it doesn't exist.
     */
    public static function add(array $array, string|int|float $key, mixed $value): array
    {
        if (is_null(static::get($array, $key))) {
            static::set($array, $key, $value);
        }

        return $array;
    }

    /**
     * Get an array item from an array using "dot" notation.
     *
     * @throws \InvalidArgumentException
     */
    public static function array(ArrayAccess|array $array, string|int|null $key, ?array $default = null): array
    {
        $value = Arr::get($array, $key, $default);

        if (! is_array($value)) {
            throw new InvalidArgumentException(
                sprintf('Array value for key [%s] must be an array, %s found.', $key, gettype($value))
            );
        }

        return $value;
    }

    /**
     * Get a boolean item from an array using "dot" notation.
     *
     * @throws \InvalidArgumentException
     */
    public static function boolean(ArrayAccess|array $array, string|int|null $key, ?bool $default = null): bool
    {
        $value = Arr::get($array, $key, $default);

        if (! is_bool($value)) {
            throw new InvalidArgumentException(
                sprintf('Array value for key [%s] must be a boolean, %s found.', $key, gettype($value))
            );
        }

        return $value;
    }

    /**
     * Collapse an array of arrays into a single array.
     */
    public static function collapse(iterable $array): array
    {
        $results = [];

        foreach ($array as $values) {
            if ($values instanceof Collection) {
                $results[] = $values->all();
            } elseif (is_array($values)) {
                $results[] = $values;
            }
        }

        return array_merge([], ...$results);
    }

    /**
     * Cross join the given arrays, returning all possible permutations.
     */
    public static function crossJoin(iterable ...$arrays): array
    {
        $results = [[]];

        foreach ($arrays as $index => $array) {
            $append = [];

            foreach ($results as $product) {
                foreach ($array as $item) {
                    $product[$index] = $item;

                    $append[] = $product;
                }
            }

            $results = $append;
        }

        return $results;
    }

    /**
     * Divide an array into two arrays. One with keys and the other with values.
     */
    public static function divide(array $array): array
    {
        return [array_keys($array), array_values($array)];
    }

    /**
     * Flatten a multi-dimensional associative array with dots.
     */
    public static function dot(iterable $array, string $prepend = ''): array
    {
        $results = [];

        $flatten = function ($data, $prefix) use (&$results, &$flatten): void {
            foreach ($data as $key => $value) {
                $newKey = $prefix.$key;

                if (is_array($value) && ! empty($value)) {
                    $flatten($value, $newKey.'.');
                } else {
                    $results[$newKey] = $value;
                }
            }
        };

        $flatten($array, $prepend);

        return $results;
    }

    /**
     * Convert a flatten "dot" notation array into an expanded array.
     */
    public static function undot(iterable $array): array
    {
        $results = [];

        foreach ($array as $key => $value) {
            static::set($results, $key, $value);
        }

        return $results;
    }

    /**
     * Get all of the given array except for a specified array of keys.
     */
    public static function except(array $array, array|string|int|float $keys): array
    {
        static::forget($array, $keys);

        return $array;
    }

    /**
     * Get all of the given array except for a specified array of values.
     */
    public static function exceptValues(array $array, mixed $values, bool $strict = false): array
    {
        $values = (array) $values;

        return array_filter($array, function ($value) use ($values, $strict) {
            return ! in_array($value, $values, $strict);
        });
    }

    /**
     * Determine if the given key exists in the provided array.
     */
    public static function exists(ArrayAccess|array $array, string|int|float|null $key): bool
    {
        if ($array instanceof Enumerable) {
            return $array->has($key);
        }

        if ($array instanceof ArrayAccess) {
            return $array->offsetExists($key);
        }

        if (is_float($key) || is_null($key)) {
            $key = (string) $key;
        }

        return array_key_exists($key, $array);
    }

    /**
     * Return the first element in an iterable passing a given truth test.
     *
     * @template TKey
     * @template TValue
     * @template TFirstDefault
     *
     * @param  iterable<TKey, TValue>  $array
     * @param  (callable(TValue, TKey): bool)|null  $callback
     * @param  TFirstDefault|(\Closure(): TFirstDefault)  $default
     * @return TValue|TFirstDefault
     */
    public static function first(iterable $array, ?callable $callback = null, mixed $default = null): mixed
    {
        if (is_null($callback)) {
            if (empty($array)) {
                return value($default);
            }

            if (is_array($array)) {
                return array_first($array);
            }

            foreach ($array as $item) {
                return $item;
            }

            return value($default);
        }

        $array = static::from($array);

        $key = array_find_key($array, $callback);

        return $key !== null ? $array[$key] : value($default);
    }

    /**
     * Return the last element in an array passing a given truth test.
     *
     * @template TKey
     * @template TValue
     * @template TLastDefault
     *
     * @param  iterable<TKey, TValue>  $array
     * @param  (callable(TValue, TKey): bool)|null  $callback
     * @param  TLastDefault|(\Closure(): TLastDefault)  $default
     * @return TValue|TLastDefault
     */
    public static function last(iterable $array, ?callable $callback = null, mixed $default = null): mixed
    {
        if (is_null($callback)) {
            return empty($array) ? value($default) : array_last($array);
        }

        return static::first(array_reverse($array, true), $callback, $default);
    }

    /**
     * Take the first or last {$limit} items from an array.
     */
    public static function take(array $array, int $limit): array
    {
        if ($limit < 0) {
            return array_slice($array, $limit, abs($limit));
        }

        return array_slice($array, 0, $limit);
    }

    /**
     * Flatten a multi-dimensional array into a single level.
     */
    public static function flatten(iterable $array, float $depth = INF): array
    {
        $result = [];

        foreach ($array as $item) {
            $item = $item instanceof Collection ? $item->all() : $item;

            if (! is_array($item)) {
                $result[] = $item;
            } else {
                $values = $depth === 1.0
                    ? array_values($item)
                    : static::flatten($item, $depth - 1);

                foreach ($values as $value) {
                    $result[] = $value;
                }
            }
        }

        return $result;
    }

    /**
     * Get a float item from an array using "dot" notation.
     *
     * @throws \InvalidArgumentException
     */
    public static function float(ArrayAccess|array $array, string|int|null $key, ?float $default = null): float
    {
        $value = Arr::get($array, $key, $default);

        if (! is_float($value)) {
            throw new InvalidArgumentException(
                sprintf('Array value for key [%s] must be a float, %s found.', $key, gettype($value))
            );
        }

        return $value;
    }

    /**
     * Remove one or many array items from a given array using "dot" notation.
     */
    public static function forget(array &$array, array|string|int|float $keys): void
    {
        $original = &$array;

        $keys = (array) $keys;

        if (count($keys) === 0) {
            return;
        }

        foreach ($keys as $key) {
            // if the exact key exists in the top-level, remove it
            if (static::exists($array, $key)) {
                unset($array[$key]);

                continue;
            }

            $parts = explode('.', $key);

            // clean up before each pass
            $array = &$original;

            while (count($parts) > 1) {
                $part = array_shift($parts);

                if (isset($array[$part]) && static::accessible($array[$part])) {
                    $array = &$array[$part];
                } else {
                    continue 2;
                }
            }

            unset($array[array_shift($parts)]);
        }
    }

    /**
     * Get the underlying array of items from the given argument.
     *
     * @template TKey of array-key = array-key
     * @template TValue = mixed
     *
     * @param  array<TKey, TValue>|Enumerable<TKey, TValue>|Arrayable<TKey, TValue>|WeakMap<object, TValue>|Traversable<TKey, TValue>|Jsonable|JsonSerializable|object  $items
     * @return ($items is WeakMap ? list<TValue> : array<TKey, TValue>)
     *
     * @throws InvalidArgumentException
     */
    public static function from(mixed $items): array
    {
        return match (true) {
            is_array($items) => $items,
            $items instanceof Enumerable => $items->all(),
            $items instanceof Arrayable => $items->toArray(),
            $items instanceof WeakMap => iterator_to_array($items, false),
            $items instanceof Traversable => iterator_to_array($items),
            $items instanceof Jsonable => json_decode($items->toJson(), true),
            $items instanceof JsonSerializable => (array) $items->jsonSerialize(),
            is_object($items) => (array) $items, // @phpstan-ignore function.alreadyNarrowedType
            default => throw new InvalidArgumentException('Items cannot be represented by a scalar value.'),
        };
    }

    /**
     * Get an item from an array using "dot" notation.
     */
    public static function get(ArrayAccess|array|null $array, string|int|null $key, mixed $default = null): mixed
    {
        if (! static::accessible($array)) {
            return value($default);
        }

        if (is_null($key)) {
            return $array;
        }

        if (static::exists($array, $key)) {
            return $array[$key];
        }

        if (! str_contains((string) $key, '.')) {
            return value($default);
        }

        foreach (explode('.', $key) as $segment) {
            if (static::accessible($array) && static::exists($array, $segment)) {
                $array = $array[$segment];
            } else {
                return value($default);
            }
        }

        return $array;
    }

    /**
     * Check if an item or items exist in an array using "dot" notation.
     */
    public static function has(ArrayAccess|array $array, string|array $keys): bool
    {
        $keys = (array) $keys;

        if (! $array || $keys === []) {
            return false;
        }

        foreach ($keys as $key) {
            $subKeyArray = $array;

            if (static::exists($array, $key)) {
                continue;
            }

            foreach (explode('.', $key) as $segment) {
                if (static::accessible($subKeyArray) && static::exists($subKeyArray, $segment)) {
                    $subKeyArray = $subKeyArray[$segment];
                } else {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Determine if all keys exist in an array using "dot" notation.
     */
    public static function hasAll(ArrayAccess|array $array, string|array $keys): bool
    {
        $keys = (array) $keys;

        if (! $array || $keys === []) {
            return false;
        }

        foreach ($keys as $key) {
            if (! static::has($array, $key)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Determine if any of the keys exist in an array using "dot" notation.
     */
    public static function hasAny(ArrayAccess|array $array, string|array|null $keys): bool
    {
        if (is_null($keys)) {
            return false;
        }

        $keys = (array) $keys;

        if (! $array) {
            return false;
        }

        if ($keys === []) {
            return false;
        }

        foreach ($keys as $key) {
            if (static::has($array, $key)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine if all items pass the given truth test.
     */
    public static function every(iterable $array, callable $callback): bool
    {
        return array_all($array, $callback);
    }

    /**
     * Determine if some items pass the given truth test.
     */
    public static function some(iterable $array, callable $callback): bool
    {
        return array_any($array, $callback);
    }

    /**
     * Get an integer item from an array using "dot" notation.
     *
     * @throws \InvalidArgumentException
     */
    public static function integer(ArrayAccess|array $array, string|int|null $key, ?int $default = null): int
    {
        $value = Arr::get($array, $key, $default);

        if (! is_int($value)) {
            throw new InvalidArgumentException(
                sprintf('Array value for key [%s] must be an integer, %s found.', $key, gettype($value))
            );
        }

        return $value;
    }

    /**
     * Determines if an array is associative.
     *
     * An array is "associative" if it doesn't have sequential numerical keys beginning with zero.
     */
    public static function isAssoc(array $array): bool
    {
        return ! array_is_list($array);
    }

    /**
     * Determines if an array is a list.
     *
     * An array is a "list" if all array keys are sequential integers starting from 0 with no gaps in between.
     */
    public static function isList(array $array): bool
    {
        return array_is_list($array);
    }

    /**
     * Join all items using a string. The final items can use a separate glue string.
     */
    public static function join(array $array, string $glue, string $finalGlue = ''): string
    {
        if ($finalGlue === '') {
            return implode($glue, $array);
        }

        if (count($array) === 0) {
            return '';
        }

        if (count($array) === 1) {
            return array_last($array);
        }

        $finalItem = array_pop($array);

        return implode($glue, $array).$finalGlue.$finalItem;
    }

    /**
     * Key an associative array by a field or using a callback.
     */
    public static function keyBy(iterable $array, callable|array|string $keyBy): array
    {
        return (new Collection($array))->keyBy($keyBy)->all();
    }

    /**
     * Prepend the key names of an associative array.
     */
    public static function prependKeysWith(array $array, string $prependWith): array
    {
        return static::mapWithKeys($array, fn ($item, $key) => [$prependWith.$key => $item]);
    }

    /**
     * Get a subset of the items from the given array.
     */
    public static function only(array $array, array|string $keys): array
    {
        return array_intersect_key($array, array_flip((array) $keys));
    }

    /**
     * Get a subset of the items from the given array by value.
     */
    public static function onlyValues(array $array, mixed $values, bool $strict = false): array
    {
        $values = (array) $values;

        return array_filter($array, function ($value) use ($values, $strict) {
            return in_array($value, $values, $strict);
        });
    }

    /**
     * Select an array of values from an array.
     */
    public static function select(array $array, array|string $keys): array
    {
        $keys = static::wrap($keys);

        return static::map($array, function ($item) use ($keys) {
            $result = [];

            foreach ($keys as $key) {
                if (Arr::accessible($item) && Arr::exists($item, $key)) {
                    $result[$key] = $item[$key];
                } elseif (is_object($item) && isset($item->{$key})) {
                    $result[$key] = $item->{$key};
                }
            }

            return $result;
        });
    }

    /**
     * Pluck an array of values from an array.
     */
    public static function pluck(iterable $array, string|array|int|Closure|null $value, string|array|Closure|null $key = null): array
    {
        $results = [];

        [$value, $key] = static::explodePluckParameters($value, $key);

        foreach ($array as $item) {
            $itemValue = $value instanceof Closure
                ? $value($item)
                : data_get($item, $value);

            // If the key is "null", we will just append the value to the array and keep
            // looping. Otherwise we will key the array using the value of the key we
            // received from the developer. Then we'll return the final array form.
            if (is_null($key)) {
                $results[] = $itemValue;
            } else {
                $itemKey = $key instanceof Closure
                    ? $key($item)
                    : data_get($item, $key);

                if (is_object($itemKey) && method_exists($itemKey, '__toString')) {
                    $itemKey = (string) $itemKey;
                }

                $results[$itemKey] = $itemValue;
            }
        }

        return $results;
    }

    /**
     * Explode the "value" and "key" arguments passed to "pluck".
     *
     * @param  string|array|Closure  $value
     * @param  string|array|Closure|null  $key
     * @return array
     */
    protected static function explodePluckParameters($value, $key)
    {
        $value = is_string($value) ? explode('.', $value) : $value;

        $key = is_null($key) || is_array($key) || $key instanceof Closure ? $key : explode('.', $key);

        return [$value, $key];
    }

    /**
     * Run a map over each of the items in the array.
     */
    public static function map(array $array, callable $callback): array
    {
        $keys = array_keys($array);

        try {
            $items = array_map($callback, $array, $keys);
        } catch (ArgumentCountError) {
            $items = array_map($callback, $array);
        }

        return array_combine($keys, $items);
    }

    /**
     * Run an associative map over each of the items.
     *
     * The callback should return an associative array with a single key/value pair.
     *
     * @template TKey
     * @template TValue
     * @template TMapWithKeysKey of array-key
     * @template TMapWithKeysValue
     *
     * @param  array<TKey, TValue>  $array
     * @param  callable(TValue, TKey): array<TMapWithKeysKey, TMapWithKeysValue>  $callback
     */
    public static function mapWithKeys(array $array, callable $callback): array
    {
        $result = [];

        foreach ($array as $key => $value) {
            $assoc = $callback($value, $key);

            foreach ($assoc as $mapKey => $mapValue) {
                $result[$mapKey] = $mapValue;
            }
        }

        return $result;
    }

    /**
     * Run a map over each nested chunk of items.
     *
     * @template TKey
     * @template TValue
     *
     * @param  array<TKey, array>  $array
     * @param  callable(mixed...): TValue  $callback
     * @return array<TKey, TValue>
     */
    public static function mapSpread(array $array, callable $callback): array
    {
        return static::map($array, function ($chunk, $key) use ($callback) {
            $chunk[] = $key;

            return $callback(...$chunk);
        });
    }

    /**
     * Push an item onto the beginning of an array.
     */
    public static function prepend(array $array, mixed $value, mixed $key = null): array
    {
        if (func_num_args() == 2) {
            array_unshift($array, $value);
        } else {
            $array = [$key => $value] + $array;
        }

        return $array;
    }

    /**
     * Get a value from the array, and remove it.
     */
    public static function pull(array &$array, string|int $key, mixed $default = null): mixed
    {
        $value = static::get($array, $key, $default);

        static::forget($array, $key);

        return $value;
    }

    /**
     * Convert the array into a query string.
     */
    public static function query(array $array): string
    {
        return http_build_query($array, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Get one or a specified number of random values from an array.
     *
     * @throws InvalidArgumentException
     */
    public static function random(array $array, ?int $number = null, bool $preserveKeys = false): mixed
    {
        $requested = is_null($number) ? 1 : $number;

        $count = count($array);

        if ($requested > $count) {
            throw new InvalidArgumentException(
                "You requested {$requested} items, but there are only {$count} items available."
            );
        }

        if (empty($array) || (! is_null($number) && $number <= 0)) {
            return is_null($number) ? null : [];
        }

        $keys = (new Randomizer)->pickArrayKeys($array, $requested);

        if (is_null($number)) {
            return $array[$keys[0]];
        }

        $results = [];

        if ($preserveKeys) {
            foreach ($keys as $key) {
                $results[$key] = $array[$key];
            }
        } else {
            foreach ($keys as $key) {
                $results[] = $array[$key];
            }
        }

        return $results;
    }

    /**
     * Set an array item to a given value using "dot" notation.
     *
     * If no key is given to the method, the entire array will be replaced.
     */
    public static function set(array &$array, string|int|null $key, mixed $value): array
    {
        if (is_null($key)) {
            return $array = $value;
        }

        $keys = explode('.', (string) $key);

        foreach ($keys as $i => $key) {
            if (count($keys) === 1) {
                break;
            }

            unset($keys[$i]);

            // If the key doesn't exist at this depth, we will just create an empty array
            // to hold the next value, allowing us to create the arrays to hold final
            // values at the correct depth. Then we'll keep digging into the array.
            if (! isset($array[$key]) || ! is_array($array[$key])) {
                $array[$key] = [];
            }

            $array = &$array[$key];
        }

        $array[array_shift($keys)] = $value;

        return $array;
    }

    /**
     * Push an item into an array using "dot" notation.
     *
     * @param  \ArrayAccess|array  $array
     * @param  string|int|null  $key
     * @param  mixed  $values
     * @return array
     */
    public static function push(ArrayAccess|array &$array, string|int|null $key, mixed ...$values): array
    {
        $target = static::array($array, $key, []);

        array_push($target, ...$values);

        return static::set($array, $key, $target);
    }

    /**
     * Shuffle the given array and return the result.
     */
    public static function shuffle(array $array): array
    {
        return (new Randomizer)->shuffleArray($array);
    }

    /**
     * Get the first item in the array, but only if exactly one item exists. Otherwise, throw an exception.
     *
     * @throws ItemNotFoundException
     * @throws MultipleItemsFoundException
     */
    public static function sole(array $array, ?callable $callback = null): mixed
    {
        if ($callback) {
            $array = static::where($array, $callback);
        }

        $count = count($array);

        if ($count === 0) {
            throw new ItemNotFoundException;
        }

        if ($count > 1) {
            throw new MultipleItemsFoundException($count);
        }

        return static::first($array);
    }

    /**
     * Sort the array using the given callback or "dot" notation.
     */
    public static function sort(iterable $array, callable|array|string|null $callback = null): array
    {
        return (new Collection($array))->sortBy($callback)->all();
    }

    /**
     * Sort the array in descending order using the given callback or "dot" notation.
     */
    public static function sortDesc(iterable $array, callable|array|string|null $callback = null): array
    {
        return (new Collection($array))->sortByDesc($callback)->all();
    }

    /**
     * Recursively sort an array by keys and values.
     */
    public static function sortRecursive(array $array, int $options = SORT_REGULAR, bool $descending = false): array
    {
        foreach ($array as &$value) {
            if (is_array($value)) {
                $value = static::sortRecursive($value, $options, $descending);
            }
        }

        if (! array_is_list($array)) {
            $descending
                ? krsort($array, $options)
                : ksort($array, $options);
        } else {
            $descending
                ? rsort($array, $options)
                : sort($array, $options);
        }

        return $array;
    }

    /**
     * Recursively sort an array by keys and values in descending order.
     */
    public static function sortRecursiveDesc(array $array, int $options = SORT_REGULAR): array
    {
        return static::sortRecursive($array, $options, true);
    }

    /**
     * Get a string item from an array using "dot" notation.
     *
     * @throws \InvalidArgumentException
     */
    public static function string(ArrayAccess|array $array, string|int|null $key, ?string $default = null): string
    {
        $value = Arr::get($array, $key, $default);

        if (! is_string($value)) {
            throw new InvalidArgumentException(
                sprintf('Array value for key [%s] must be a string, %s found.', $key, gettype($value))
            );
        }

        return $value;
    }

    /**
     * Conditionally compile classes from an array into a CSS class list.
     */
    public static function toCssClasses(array|string $array): string
    {
        $classList = static::wrap($array);

        $classes = [];

        foreach ($classList as $class => $constraint) {
            if (is_numeric($class)) {
                $classes[] = $constraint;
            } elseif ($constraint) {
                $classes[] = $class;
            }
        }

        return implode(' ', $classes);
    }

    /**
     * Conditionally compile styles from an array into a style list.
     */
    public static function toCssStyles(array|string $array): string
    {
        $styleList = static::wrap($array);

        $styles = [];

        foreach ($styleList as $class => $constraint) {
            if (is_numeric($class)) {
                $styles[] = Str::finish($constraint, ';');
            } elseif ($constraint) {
                $styles[] = Str::finish($class, ';');
            }
        }

        return implode(' ', $styles);
    }

    /**
     * Filter the array using the given callback.
     */
    public static function where(array $array, callable $callback): array
    {
        return array_filter($array, $callback, ARRAY_FILTER_USE_BOTH);
    }

    /**
     * Filter the array using the negation of the given callback.
     */
    public static function reject(array $array, callable $callback): array
    {
        return static::where($array, fn ($value, $key) => ! $callback($value, $key));
    }

    /**
     * Partition the array into two arrays using the given callback.
     *
     * @template TKey of array-key
     * @template TValue of mixed
     *
     * @param  iterable<TKey, TValue>  $array
     * @param  callable(TValue, TKey): bool  $callback
     * @return array<int<0, 1>, array<TKey, TValue>>
     */
    public static function partition(iterable $array, callable $callback): array
    {
        $passed = [];
        $failed = [];

        foreach ($array as $key => $item) {
            if ($callback($item, $key)) {
                $passed[$key] = $item;
            } else {
                $failed[$key] = $item;
            }
        }

        return [$passed, $failed];
    }

    /**
     * Filter items where the value is not null.
     */
    public static function whereNotNull(array $array): array
    {
        return static::where($array, fn ($value) => ! is_null($value));
    }

    /**
     * If the given value is not an array and not null, wrap it in one.
     */
    public static function wrap(mixed $value): array
    {
        if (is_null($value)) {
            return [];
        }

        return is_array($value) ? $value : [$value];
    }
}
