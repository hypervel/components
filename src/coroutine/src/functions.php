<?php

declare(strict_types=1);

namespace Hypervel\Coroutine;

use Closure;
use Hypervel\Container\Container;
use RuntimeException;
use Swoole\Runtime;

/**
 * @param callable[] $callables
 * @param int $concurrent if $concurrent is equal to 0, that means unlimited
 * @param array<string>|bool $copyContext When set, parent coroutine context is copied to each child.
 *                                        false = fresh context (default), true or empty array = copy all keys, non-empty array = copy listed keys only.
 *                                        Object values are shared by reference unless they implement Hypervel\Context\ReplicableContext.
 */
function parallel(array $callables, int $concurrent = 0, bool|array $copyContext = false): array
{
    $parallel = new Parallel($concurrent, $copyContext);
    foreach ($callables as $key => $callable) {
        $parallel->add($callable, $key);
    }
    return $parallel->wait();
}

/**
 * @template TReturn
 *
 * @param Closure():TReturn $closure
 * @return TReturn
 */
function wait(Closure $closure, ?float $timeout = null)
{
    return Container::getInstance()
        ->make(Waiter::class)
        ->wait($closure, $timeout);
}

/**
 * @param array<string>|bool $copyContext When set, parent coroutine context is copied to the child.
 *                                        false = fresh context (default), true or empty array = copy all keys, non-empty array = copy listed keys only.
 *                                        Object values are shared by reference unless they implement Hypervel\Context\ReplicableContext.
 */
function co(callable $callable, bool|array $copyContext = false): bool|int
{
    $id = $copyContext === false
        ? Coroutine::create($callable)
        : Coroutine::fork($callable, is_array($copyContext) ? $copyContext : []);

    return $id > 0 ? $id : false;
}

// defer() wrapper was removed intentionally. Use Coroutine::defer() directly for
// coroutine-exit cleanup. The global defer() helper in foundation provides Laravel-style
// lifecycle-aware deferred callbacks — having two functions named "defer" with different
// semantics caused import ambiguity bugs. Do not re-add this wrapper.

/**
 * @param array<string>|bool $copyContext When set, parent coroutine context is copied to the child.
 *                                        false = fresh context (default), true or empty array = copy all keys, non-empty array = copy listed keys only.
 *                                        Object values are shared by reference unless they implement Hypervel\Context\ReplicableContext.
 */
function go(callable $callable, bool|array $copyContext = false): bool|int
{
    $id = $copyContext === false
        ? Coroutine::create($callable)
        : Coroutine::fork($callable, is_array($copyContext) ? $copyContext : []);

    return $id > 0 ? $id : false;
}

/**
 * Run callable in non-coroutine environment, all hook functions by Swoole only available in the callable.
 *
 * @param array|callable $callbacks
 */
function run($callbacks, int $flags = SWOOLE_HOOK_ALL): bool
{
    if (Coroutine::inCoroutine()) {
        throw new RuntimeException('Function \'run\' only execute in non-coroutine environment.');
    }

    Runtime::enableCoroutine($flags);

    /* @phpstan-ignore-next-line */
    $result = \Swoole\Coroutine\run(...(array) $callbacks);

    Runtime::enableCoroutine(0);
    return $result;
}
