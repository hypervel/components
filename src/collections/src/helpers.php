<?php

declare(strict_types=1);

use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Support\Arr;
use Hypervel\Support\Collection;

if (! function_exists('collect')) {
    /**
     * Create a collection from the given value.
     *
     * @template TKey of array-key = array-key
     * @template TValue = mixed
     * @template TObject of object = object
     *
     * @param null|Arrayable<array<TKey, TValue>>|iterable<TKey, TValue>|scalar|TObject $value
     * @return Collection<TKey, TValue>
     */
    function collect(mixed $value = []): Collection
    {
        return new Collection($value);
    }
}

if (! function_exists('data_fill')) {
    /**
     * Fill in data where it's missing.
     */
    function data_fill(mixed &$target, array|int|string $key, mixed $value): mixed
    {
        return data_set($target, $key, $value, false);
    }
}

if (! function_exists('data_has')) {
    /**
     * Determine if a key / property exists on an array or object using "dot" notation.
     */
    function data_has(mixed $target, array|int|string|null $key): bool
    {
        if (is_null($key) || $key === []) {
            return false;
        }

        $key = is_array($key) ? $key : explode('.', (string) $key);

        foreach ($key as $segment) {
            if (Arr::accessible($target) && Arr::exists($target, $segment)) {
                $target = $target[$segment];
            } elseif (is_object($target) && property_exists($target, $segment)) {
                $target = $target->{$segment};
            } else {
                return false;
            }
        }

        return true;
    }
}

if (! function_exists('data_get')) {
    /**
     * Get an item from an array or object using "dot" notation.
     */
    function data_get(mixed $target, array|int|string|null $key, mixed $default = null): mixed
    {
        if (is_null($key)) {
            return $target;
        }

        $key = is_array($key) ? $key : explode('.', (string) $key);

        foreach ($key as $i => $segment) {
            unset($key[$i]);

            if (is_null($segment)) {
                return $target;
            }

            if ($segment === '*') {
                // Unlike upstream, wildcards also select stdClass properties, since they may target any key of an array or object.
                if ($target instanceof Collection) {
                    $target = $target->all();
                } elseif (! is_iterable($target) && ! $target instanceof stdClass) {
                    return value($default);
                }

                $result = [];

                foreach ($target as $item) {
                    $result[] = data_get($item, $key);
                }

                return in_array('*', $key) ? Arr::collapse($result) : $result;
            }

            $segment = match ($segment) {
                '\*' => '*',
                '\{first}' => '{first}',
                '{first}' => array_key_first(Arr::from($target)),
                '\{last}' => '{last}',
                '{last}' => array_key_last(Arr::from($target)),
                default => $segment,
            };

            if (Arr::accessible($target) && Arr::exists($target, $segment)) {
                $target = $target[$segment];
            } elseif (is_object($target) && isset($target->{$segment})) {
                $target = $target->{$segment};
            } else {
                return value($default);
            }
        }

        return $target;
    }
}

if (! function_exists('data_set')) {
    /**
     * Set an item on an array or object using dot notation.
     */
    function data_set(mixed &$target, array|int|string $key, mixed $value, bool $overwrite = true): mixed
    {
        $segments = is_array($key) ? $key : explode('.', (string) $key);

        if (($segment = array_shift($segments)) === '*') {
            // Unlike upstream, wildcards also set stdClass properties instead of replacing the object with an array.
            if (! Arr::accessible($target) && ! $target instanceof stdClass) {
                $target = [];
            }

            if ($target instanceof ArrayAccess && $target instanceof Traversable) {
                // Iterators may yield array copies; write changes through the original offsets.
                foreach ($target as $innerKey => $inner) {
                    if ($segments) {
                        data_set($inner, $segments, $value, $overwrite);

                        if (! is_object($inner)) {
                            $target[$innerKey] = $inner;
                        }
                    } elseif ($overwrite) {
                        $target[$innerKey] = $value;
                    }
                }
            } elseif ($segments) {
                foreach ($target as &$inner) {
                    data_set($inner, $segments, $value, $overwrite);
                }
            } elseif ($overwrite) {
                foreach ($target as &$inner) {
                    $inner = $value;
                }
            }
        } elseif (Arr::accessible($target)) {
            if ($segments) {
                if ($target instanceof ArrayAccess) {
                    $inner = Arr::exists($target, $segment) ? $target[$segment] : [];
                    data_set($inner, $segments, $value, $overwrite);

                    // Objects mutate in place; assigning a model relation back would create an attribute.
                    if (! is_object($inner)) {
                        $target[$segment] = $inner;
                    }
                } else {
                    if (! Arr::exists($target, $segment)) {
                        $target[$segment] = [];
                    }

                    data_set($target[$segment], $segments, $value, $overwrite);
                }
            } elseif ($overwrite || ! Arr::exists($target, $segment)) {
                $target[$segment] = $value;
            }
        } elseif (is_object($target)) {
            if ($segments) {
                if (! isset($target->{$segment})) {
                    $target->{$segment} = [];
                }

                data_set($target->{$segment}, $segments, $value, $overwrite);
            } elseif ($overwrite || ! isset($target->{$segment})) {
                $target->{$segment} = $value;
            }
        } else {
            $target = [];

            if ($segments) {
                data_set($target[$segment], $segments, $value, $overwrite);
            } elseif ($overwrite) {
                $target[$segment] = $value;
            }
        }

        return $target;
    }
}

if (! function_exists('data_forget')) {
    /**
     * Remove / unset an item from an array or object using "dot" notation.
     */
    function data_forget(mixed &$target, array|int|string|null $key): mixed
    {
        $segments = is_array($key) ? $key : explode('.', (string) $key);

        // Unlike upstream, wildcards also remove values from stdClass properties.
        if (($segment = array_shift($segments)) === '*' && (Arr::accessible($target) || $target instanceof stdClass)) {
            if ($segments) {
                // ArrayAccess reads and iterators may copy arrays; write changes back while objects mutate in place.
                if ($target instanceof ArrayAccess && $target instanceof Traversable) {
                    foreach ($target as $innerKey => $inner) {
                        data_forget($inner, $segments);

                        if (is_array($inner)) {
                            $target[$innerKey] = $inner;
                        }
                    }
                } else {
                    foreach ($target as &$inner) {
                        data_forget($inner, $segments);
                    }
                }
            }
        } elseif (Arr::accessible($target)) {
            if ($segments && Arr::exists($target, $segment)) {
                if ($target instanceof ArrayAccess) {
                    $inner = $target[$segment];
                    data_forget($inner, $segments);

                    if (is_array($inner)) {
                        $target[$segment] = $inner;
                    }
                } else {
                    data_forget($target[$segment], $segments);
                }
            } else {
                Arr::forget($target, $segment);
            }
        } elseif (is_object($target)) {
            if ($segments && isset($target->{$segment})) {
                data_forget($target->{$segment}, $segments);
            } elseif (! $segments) {
                // Unlike upstream, this does not check isset(), so null properties are removed too.
                unset($target->{$segment});
            }
        }

        return $target;
    }
}

if (! function_exists('head')) {
    /**
     * Get the first element of an array. Useful for method chaining.
     *
     * @template TValue
     *
     * @param array<array-key, TValue> $array
     * @return ($array is array{} ? false : TValue)
     */
    function head(array $array): mixed
    {
        return empty($array) ? false : array_first($array);
    }
}

if (! function_exists('last')) {
    /**
     * Get the last element from an array.
     *
     * @template TValue
     *
     * @param array<array-key, TValue> $array
     * @return ($array is array{} ? false : TValue)
     */
    function last(array $array): mixed
    {
        return empty($array) ? false : array_last($array);
    }
}

if (! function_exists('value')) {
    /**
     * Return the default value of the given value.
     *
     * @template TValue
     * @template TArgs
     *
     * @param (Closure(TArgs): TValue)|TValue $value
     * @param TArgs ...$args
     * @return TValue
     */
    function value(mixed $value, mixed ...$args): mixed
    {
        return $value instanceof Closure ? $value(...$args) : $value;
    }
}

if (! function_exists('when')) {
    /**
     * Return a value if the given condition is true.
     *
     * @template TValue
     * @template TArgs
     * @template TDefault
     * @template TDefaultArgs
     *
     * @param (Closure(TArgs): TValue)|TValue $value
     * @param (Closure(TDefaultArgs): TDefault)|TDefault $default
     * @return ($condition is Closure ? TDefault|TValue : ($condition is empty ? TDefault : TValue))
     */
    function when(mixed $condition, mixed $value, mixed $default = null): mixed
    {
        $condition = $condition instanceof Closure ? $condition() : $condition;

        if ($condition) {
            return value($value, $condition);
        }

        return value($default, $condition);
    }
}
