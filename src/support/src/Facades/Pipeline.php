<?php

declare(strict_types=1);

namespace Hypervel\Support\Facades;

/**
 * @method static \Hypervel\Pipeline\Pipeline send(mixed $passable)
 * @method static \Hypervel\Pipeline\Pipeline through(mixed $pipes)
 * @method static \Hypervel\Pipeline\Pipeline pipe(mixed $pipes)
 * @method static \Hypervel\Pipeline\Pipeline via(string $method)
 * @method static mixed then(\Closure $destination)
 * @method static mixed thenReturn()
 * @method static \Hypervel\Pipeline\Pipeline finally(\Closure $callback)
 * @method static \Hypervel\Pipeline\Pipeline withinTransaction(string|null|\UnitEnum|false $withinTransaction = null)
 * @method static \Hypervel\Pipeline\Pipeline setContainer(\Hypervel\Contracts\Container\Container $container)
 * @method static \Hypervel\Pipeline\Pipeline|mixed when(\Closure|mixed|null $value = null, callable|null $callback = null, callable|null $default = null)
 * @method static \Hypervel\Pipeline\Pipeline|mixed unless(\Closure|mixed|null $value = null, callable|null $callback = null, callable|null $default = null)
 * @method static void macro(string $name, object|callable $macro)
 * @method static void mixin(object $mixin, bool $replace = true)
 * @method static bool hasMacro(string $name)
 * @method static void flushMacros()
 *
 * @see \Hypervel\Pipeline\Pipeline
 */
class Pipeline extends Facade
{
    /**
     * Indicates if the resolved instance should be cached.
     */
    protected static bool $cached = false;

    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return 'pipeline';
    }
}
