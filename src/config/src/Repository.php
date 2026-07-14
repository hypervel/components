<?php

declare(strict_types=1);

namespace Hypervel\Config;

use ArrayAccess;
use Closure;
use Hypervel\Contracts\Config\Repository as ConfigContract;
use Hypervel\Support\Arr;
use Hypervel\Support\Collection;
use Hypervel\Support\Traits\Macroable;
use InvalidArgumentException;

class Repository implements ArrayAccess, ConfigContract
{
    use Macroable;

    /**
     * The observer invoked after each configuration mutation.
     */
    protected ?Closure $mutationObserver = null;

    /**
     * Create a new configuration repository.
     */
    public function __construct(protected array $items = [])
    {
    }

    /**
     * Determine if the given configuration value exists.
     */
    public function has(string $key): bool
    {
        return Arr::has($this->items, $key);
    }

    /**
     * Get the specified configuration value.
     */
    public function get(array|string $key, mixed $default = null): mixed
    {
        if (is_array($key)) {
            return $this->getMany($key);
        }

        return Arr::get($this->items, $key, $default);
    }

    /**
     * Get many configuration values.
     */
    public function getMany(array $keys): array
    {
        $config = [];

        foreach ($keys as $key => $default) {
            if (is_numeric($key)) {
                [$key, $default] = [$default, null];
            }

            $config[$key] = Arr::get($this->items, $key, $default);
        }

        return $config;
    }

    /**
     * Get the specified string configuration value.
     *
     * @param null|(Closure():(null|string))|string $default
     * @throws InvalidArgumentException
     */
    public function string(string $key, mixed $default = null): string
    {
        $value = $this->get($key, $default);

        if (! is_string($value)) {
            throw new InvalidArgumentException(
                sprintf('Configuration value for key [%s] must be a string, %s given.', $key, gettype($value))
            );
        }

        return $value;
    }

    /**
     * Get the specified integer configuration value.
     *
     * @param null|(Closure():(null|int))|int $default
     * @throws InvalidArgumentException
     */
    public function integer(string $key, mixed $default = null): int
    {
        $value = $this->get($key, $default);

        if (! is_int($value)) {
            throw new InvalidArgumentException(
                sprintf('Configuration value for key [%s] must be an integer, %s given.', $key, gettype($value))
            );
        }

        return $value;
    }

    /**
     * Get the specified float configuration value.
     *
     * @param null|(Closure():(null|float))|float $default
     * @throws InvalidArgumentException
     */
    public function float(string $key, mixed $default = null): float
    {
        $value = $this->get($key, $default);

        if (! is_float($value)) {
            throw new InvalidArgumentException(
                sprintf('Configuration value for key [%s] must be a float, %s given.', $key, gettype($value))
            );
        }

        return $value;
    }

    /**
     * Get the specified boolean configuration value.
     *
     * @param null|bool|(Closure():(null|bool)) $default
     * @throws InvalidArgumentException
     */
    public function boolean(string $key, mixed $default = null): bool
    {
        $value = $this->get($key, $default);

        if (! is_bool($value)) {
            throw new InvalidArgumentException(
                sprintf('Configuration value for key [%s] must be a boolean, %s given.', $key, gettype($value))
            );
        }

        return $value;
    }

    /**
     * Get the specified array configuration value.
     *
     * @param null|array<array-key, mixed>|(Closure():(null|array<array-key, mixed>)) $default
     * @return array<array-key, mixed>
     * @throws InvalidArgumentException
     */
    public function array(string $key, mixed $default = null): array
    {
        $value = $this->get($key, $default);

        if (! is_array($value)) {
            throw new InvalidArgumentException(
                sprintf('Configuration value for key [%s] must be an array, %s given.', $key, gettype($value))
            );
        }

        return $value;
    }

    /**
     * Get the specified configuration value as a Collection.
     *
     * @param null|array<array-key, mixed>|(Closure():(null|array<array-key, mixed>)) $default
     * @return Collection<array-key, mixed>
     * @throws InvalidArgumentException
     */
    public function collection(string $key, mixed $default = null): Collection
    {
        return new Collection($this->array($key, $default));
    }

    /**
     * Set a given configuration value.
     *
     * Boot or tests only. The config repository is a process-global singleton;
     * per-request mutation races across coroutines and affects every concurrent
     * request.
     */
    public function set(array|string $key, mixed $value = null): void
    {
        $keys = is_array($key) ? $key : [$key => $value];

        foreach ($keys as $key => $value) {
            Arr::set($this->items, $key, $value);
        }

        if ($this->mutationObserver) {
            call_user_func($this->mutationObserver, $keys);
        }
    }

    /**
     * Prepend a value onto an array configuration value.
     *
     * Boot or tests only. The config repository is a process-global singleton;
     * per-request mutation races across coroutines and affects every concurrent
     * request.
     */
    public function prepend(string $key, mixed $value): void
    {
        $array = $this->get($key, []);

        array_unshift($array, $value);

        $this->set($key, $array);
    }

    /**
     * Push a value onto an array configuration value.
     *
     * Boot or tests only. The config repository is a process-global singleton;
     * per-request mutation races across coroutines and affects every concurrent
     * request.
     */
    public function push(string $key, mixed $value): void
    {
        $array = $this->get($key, []);

        $array[] = $value;

        $this->set($key, $array);
    }

    /**
     * Get all of the configuration items for the application.
     */
    public function all(): array
    {
        return $this->items;
    }

    /**
     * Replace all configuration items without reporting a mutation.
     *
     * @internal
     */
    public function replaceItems(array $items): void
    {
        $this->items = $items;
    }

    /**
     * Set the observer invoked after each configuration mutation.
     *
     * @internal
     */
    public function setMutationObserver(Closure $observer): void
    {
        $this->mutationObserver = $observer;
    }

    /**
     * Determine if the given configuration option exists.
     *
     * @param int|string $key
     */
    public function offsetExists($key): bool
    {
        return $this->has((string) $key);
    }

    /**
     * Get a configuration option.
     *
     * @param int|string $key
     */
    public function offsetGet($key): mixed
    {
        return $this->get((string) $key);
    }

    /**
     * Set a configuration option.
     *
     * Boot or tests only. The config repository is a process-global singleton;
     * per-request mutation races across coroutines and affects every concurrent
     * request.
     *
     * @param int|string $key
     * @param mixed $value
     */
    public function offsetSet($key, $value): void
    {
        $this->set((string) $key, $value);
    }

    /**
     * Unset a configuration option.
     *
     * Boot or tests only. The config repository is a process-global singleton;
     * per-request mutation races across coroutines and affects every concurrent
     * request.
     *
     * @param int|string $key
     */
    public function offsetUnset($key): void
    {
        $this->set((string) $key, null);
    }

    /**
     * Flush all static state.
     */
    public static function flushState(): void
    {
        static::flushMacros();
    }
}
