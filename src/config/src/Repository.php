<?php

declare(strict_types=1);

namespace Hypervel\Config;

use ArrayAccess;
use Closure;
use Hyperf\Collection\Arr;
use Hyperf\Macroable\Macroable;
use Hypervel\Config\Contracts\Repository as ConfigContract;
use InvalidArgumentException;

class Repository implements ArrayAccess, ConfigContract
{
    use Macroable;

    /**
     * Callback for calling after `set` function.
     */
    protected static ?Closure $afterSettingCallback = null;

    /**
     * Create a new configuration repository.
     */
    public function __construct(protected array $items)
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
     * Set a given configuration value.
     */
    public function set(array|string $key, mixed $value = null): void
    {
        $keys = is_array($key) ? $key : [$key => $value];

        foreach ($keys as $key => $value) {
            Arr::set($this->items, $key, $value);
        }

        if (static::$afterSettingCallback) {
            call_user_func(static::$afterSettingCallback, $keys);
        }
    }

    /**
     * Prepend a value onto an array configuration value.
     */
    public function prepend(string $key, mixed $value): void
    {
        $array = $this->get($key, []);

        array_unshift($array, $value);

        $this->set($key, $array);
    }

    /**
     * Push a value onto an array configuration value.
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
     * Set callback after calling `set` function.
     */
    public function afterSettingCallback(?Closure $callback): void
    {
        static::$afterSettingCallback = $callback;
    }

    /**
     * Determine if the given configuration option exists.
     *
     * @param string $key
     */
    public function offsetExists($key): bool
    {
        return $this->has($key);
    }

    /**
     * Get a configuration option.
     *
     * @param string $key
     */
    public function offsetGet($key): mixed
    {
        return $this->get($key);
    }

    /**
     * Set a configuration option.
     *
     * @param string $key
     * @param mixed $value
     */
    public function offsetSet($key, $value): void
    {
        $this->set($key, $value);
    }

    /**
     * Unset a configuration option.
     *
     * @param string $key
     */
    public function offsetUnset($key): void
    {
        $this->set($key, null);
    }
}
