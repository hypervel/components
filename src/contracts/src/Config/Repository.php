<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Config;

use Closure;

interface Repository
{
    /**
     * Determine if the given configuration value exists.
     */
    public function has(string $key): bool;

    /**
     * Get the specified configuration value.
     */
    public function get(array|string $key, mixed $default = null): mixed;

    /**
     * Get the specified string configuration value.
     *
     * @param null|(Closure():(null|string))|string $default
     */
    public function string(string $key, mixed $default = null): string;

    /**
     * Get the specified integer configuration value.
     *
     * @param null|(Closure():(null|int))|int $default
     */
    public function integer(string $key, mixed $default = null): int;

    /**
     * Get the specified float configuration value.
     *
     * @param null|(Closure():(null|float))|float $default
     */
    public function float(string $key, mixed $default = null): float;

    /**
     * Get the specified boolean configuration value.
     *
     * @param null|bool|(Closure():(null|bool)) $default
     */
    public function boolean(string $key, mixed $default = null): bool;

    /**
     * Get the specified array configuration value.
     *
     * @param null|array<array-key, mixed>|(Closure():(null|array<array-key, mixed>)) $default
     * @return array<array-key, mixed>
     */
    public function array(string $key, mixed $default = null): array;

    /**
     * Get all of the configuration items for the application.
     */
    public function all(): array;

    /**
     * Set a given configuration value.
     */
    public function set(array|string $key, mixed $value = null): void;

    /**
     * Set callback after calling `set` function.
     */
    public function afterSettingCallback(?Closure $callback): void;

    /**
     * Prepend a value onto an array configuration value.
     */
    public function prepend(string $key, mixed $value): void;

    /**
     * Push a value onto an array configuration value.
     */
    public function push(string $key, mixed $value): void;
}
