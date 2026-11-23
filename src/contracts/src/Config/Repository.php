<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Config;

use Closure;
use Hypervel\Support\Attributes\BootOrTestsOnly;
use Hypervel\Support\Collection;
use InvalidArgumentException;

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
     * @throws InvalidArgumentException
     */
    public function string(string $key, mixed $default = null): string;

    /**
     * Get the specified nullable string configuration value.
     *
     * Without a default the key must be present, even if its value is null.
     *
     * @param null|(Closure():(null|string))|string $default
     * @throws InvalidArgumentException
     */
    public function nullableString(string $key, mixed $default = null): ?string;

    /**
     * Get the specified integer configuration value.
     *
     * @param null|(Closure():(null|int))|int $default
     * @throws InvalidArgumentException
     */
    public function integer(string $key, mixed $default = null): int;

    /**
     * Get the specified nullable integer configuration value.
     *
     * Without a default the key must be present, even if its value is null.
     *
     * @param null|(Closure():(null|int))|int $default
     * @throws InvalidArgumentException
     */
    public function nullableInteger(string $key, mixed $default = null): ?int;

    /**
     * Get the specified float configuration value.
     *
     * @param null|(Closure():(null|float))|float $default
     * @throws InvalidArgumentException
     */
    public function float(string $key, mixed $default = null): float;

    /**
     * Get the specified nullable float configuration value.
     *
     * Without a default the key must be present, even if its value is null.
     *
     * @param null|(Closure():(null|float))|float $default
     * @throws InvalidArgumentException
     */
    public function nullableFloat(string $key, mixed $default = null): ?float;

    /**
     * Get the specified boolean configuration value.
     *
     * @param null|bool|(Closure():(null|bool)) $default
     * @throws InvalidArgumentException
     */
    public function boolean(string $key, mixed $default = null): bool;

    /**
     * Get the specified nullable boolean configuration value.
     *
     * Without a default the key must be present, even if its value is null.
     *
     * @param null|bool|(Closure():(null|bool)) $default
     * @throws InvalidArgumentException
     */
    public function nullableBoolean(string $key, mixed $default = null): ?bool;

    /**
     * Get the specified array configuration value.
     *
     * @param null|array<array-key, mixed>|(Closure():(null|array<array-key, mixed>)) $default
     * @return array<array-key, mixed>
     * @throws InvalidArgumentException
     */
    public function array(string $key, mixed $default = null): array;

    /**
     * Get the specified nullable array configuration value.
     *
     * Without a default the key must be present, even if its value is null.
     *
     * @param null|array<array-key, mixed>|(Closure():(null|array<array-key, mixed>)) $default
     * @return null|array<array-key, mixed>
     * @throws InvalidArgumentException
     */
    public function nullableArray(string $key, mixed $default = null): ?array;

    /**
     * Get the specified configuration value as a Collection.
     *
     * @param null|array<array-key, mixed>|(Closure():(null|array<array-key, mixed>)) $default
     * @return Collection<array-key, mixed>
     * @throws InvalidArgumentException
     */
    public function collection(string $key, mixed $default = null): Collection;

    /**
     * Get all of the configuration items for the application.
     *
     * @return array<array-key, mixed>
     */
    public function all(): array;

    /**
     * Set a given configuration value.
     *
     * The config repository is a process-global singleton; per-request mutation
     * races across coroutines and affects every concurrent request.
     */
    #[BootOrTestsOnly(whenShared: true)]
    public function set(array|string $key, mixed $value = null): void;

    /**
     * Prepend a value onto an array configuration value.
     *
     * The config repository is a process-global singleton; per-request mutation
     * races across coroutines and affects every concurrent request.
     */
    #[BootOrTestsOnly(whenShared: true)]
    public function prepend(string $key, mixed $value): void;

    /**
     * Push a value onto an array configuration value.
     *
     * The config repository is a process-global singleton; per-request mutation
     * races across coroutines and affects every concurrent request.
     */
    #[BootOrTestsOnly(whenShared: true)]
    public function push(string $key, mixed $value): void;
}
