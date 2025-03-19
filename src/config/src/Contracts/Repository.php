<?php

declare(strict_types=1);

namespace Hypervel\Config\Contracts;

use Closure;
use Hyperf\Contract\ConfigInterface;

interface Repository extends ConfigInterface
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
