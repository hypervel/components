<?php

declare(strict_types=1);

namespace Hypervel\Support\Facades;

use Hypervel\Log\Context\Repository;

/**
 * @method static \Hypervel\Log\Context\Repository getInstance()
 * @method static bool hasInstance()
 * @method static bool has(string $key)
 * @method static bool missing(string $key)
 * @method static array<string, mixed> all()
 * @method static mixed get(string $key, mixed $default = null)
 * @method static mixed pull(string $key, mixed $default = null)
 * @method static array<string, mixed> only(array<int, string> $keys)
 * @method static array<string, mixed> except(array<int, string> $keys)
 * @method static \Hypervel\Log\Context\Repository add(array<string, mixed>|string $key, mixed $value = null)
 * @method static \Hypervel\Log\Context\Repository addIf(string $key, mixed $value)
 * @method static mixed remember(string $key, mixed $value)
 * @method static \Hypervel\Log\Context\Repository forget(array<int, string>|string $key)
 * @method static \Hypervel\Log\Context\Repository push(string $key, mixed ...$values)
 * @method static mixed pop(string $key)
 * @method static bool stackContains(string $key, mixed $value, bool $strict = false)
 * @method static \Hypervel\Log\Context\Repository increment(string $key, int $amount = 1)
 * @method static \Hypervel\Log\Context\Repository decrement(string $key, int $amount = 1)
 * @method static bool hasHidden(string $key)
 * @method static bool missingHidden(string $key)
 * @method static array<string, mixed> allHidden()
 * @method static mixed getHidden(string $key, mixed $default = null)
 * @method static mixed pullHidden(string $key, mixed $default = null)
 * @method static array<string, mixed> onlyHidden(array<int, string> $keys)
 * @method static array<string, mixed> exceptHidden(array<int, string> $keys)
 * @method static \Hypervel\Log\Context\Repository addHidden(array<string, mixed>|string $key, mixed $value = null)
 * @method static \Hypervel\Log\Context\Repository addHiddenIf(string $key, mixed $value)
 * @method static mixed rememberHidden(string $key, mixed $value)
 * @method static \Hypervel\Log\Context\Repository forgetHidden(array<int, string>|string $key)
 * @method static \Hypervel\Log\Context\Repository pushHidden(string $key, mixed ...$values)
 * @method static mixed popHidden(string $key)
 * @method static bool hiddenStackContains(string $key, mixed $value, bool $strict = false)
 * @method static mixed scope(callable $callback, array<string, mixed> $data = [], array<string, mixed> $hidden = [])
 * @method static bool isEmpty()
 * @method static \Hypervel\Log\Context\Repository flush()
 * @method static \Hypervel\Log\Context\Repository replicate()
 * @method static \Hypervel\Log\Context\Repository dehydrating(callable $callback)
 * @method static \Hypervel\Log\Context\Repository hydrated(callable $callback)
 * @method static \Hypervel\Log\Context\Repository handleUnserializeExceptionsUsing(callable|null $callback)
 * @method static void flushState()
 * @method static \Hypervel\Log\Context\Repository|mixed when(null|\Closure|mixed $value = null, null|callable $callback = null, null|callable $default = null)
 * @method static \Hypervel\Log\Context\Repository|mixed unless(null|\Closure|mixed $value = null, null|callable $callback = null, null|callable $default = null)
 * @method static void macro(string $name, callable|object $macro)
 * @method static void mixin(object $mixin, bool $replace = true)
 * @method static bool hasMacro(string $name)
 * @method static void flushMacros()
 * @method static \Hypervel\Database\Eloquent\Model restoreModel(\Hypervel\Database\ModelIdentifier $value)
 *
 * @see \Hypervel\Log\Context\Repository
 */
class Context extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return Repository::class;
    }

    /**
     * Resolve the facade root instance from the coroutine context.
     *
     * Bypasses the container — the Repository is stored per-coroutine
     * via CoroutineContext, not as a container binding.
     */
    protected static function resolveFacadeInstance(string $name): mixed
    {
        if (isset(static::$resolvedInstance[$name])) {
            return static::$resolvedInstance[$name];
        }

        return Repository::getInstance();
    }
}
