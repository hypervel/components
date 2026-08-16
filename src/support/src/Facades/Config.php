<?php

declare(strict_types=1);

namespace Hypervel\Support\Facades;

/**
 * @method static array all()
 * @method static array<array-key, mixed> array(string $key, null|array<array-key, mixed>|\Closure $default = null)
 * @method static bool boolean(string $key, null|bool|\Closure $default = null)
 * @method static \Hypervel\Support\Collection<array-key, mixed> collection(string $key, null|array<array-key, mixed>|\Closure $default = null)
 * @method static float float(string $key, null|\Closure|float $default = null)
 * @method static void flushMacros()
 * @method static void flushState()
 * @method static mixed get(array|string $key, mixed $default = null)
 * @method static array getMany(array $keys)
 * @method static bool has(string $key)
 * @method static bool hasMacro(string $name)
 * @method static int integer(string $key, null|\Closure|int $default = null)
 * @method static void macro(string $name, callable|object $macro)
 * @method static void mixin(object $mixin, bool $replace = true)
 * @method static void prepend(string $key, mixed $value)
 * @method static void push(string $key, mixed $value)
 * @method static void set(array|string $key, mixed $value = null)
 * @method static string string(string $key, null|\Closure|string $default = null)
 *
 * @see \Hypervel\Config\Repository
 */
class Config extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'config';
    }
}
