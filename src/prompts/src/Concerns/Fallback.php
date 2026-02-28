<?php

declare(strict_types=1);

namespace Hypervel\Prompts\Concerns;

use Closure;
use Hypervel\Context\Context;
use Hypervel\Coroutine\Coroutine;
use RuntimeException;

trait Fallback
{
    /**
     * Whether to fallback to a custom implementation.
     */
    protected static bool $shouldFallback = false;

    /**
     * The fallback implementations.
     *
     * @var array<class-string, Closure(static): mixed>
     */
    protected static array $fallbacks = [];

    /**
     * Enable the fallback implementation.
     */
    public static function fallbackWhen(bool $condition): void
    {
        if (Coroutine::inCoroutine()) {
            $current = Context::get('__prompt.should_fallback', false);
            Context::set('__prompt.should_fallback', $condition || $current);
        } else {
            static::$shouldFallback = $condition || static::$shouldFallback;
        }
    }

    /**
     * Whether the prompt should fallback to a custom implementation.
     */
    public static function shouldFallback(): bool
    {
        if (Coroutine::inCoroutine()) {
            $shouldFallback = Context::get('__prompt.should_fallback') ?? static::$shouldFallback;
            $fallbacks = Context::get('__prompt.fallbacks') ?? static::$fallbacks;

            return $shouldFallback && isset($fallbacks[static::class]);
        }

        return static::$shouldFallback && isset(static::$fallbacks[static::class]);
    }

    /**
     * Set the fallback implementation.
     *
     * @param Closure(static): mixed $fallback
     */
    public static function fallbackUsing(Closure $fallback): void
    {
        if (Coroutine::inCoroutine()) {
            $fallbacks = Context::get('__prompt.fallbacks') ?? static::$fallbacks;
            $fallbacks[static::class] = $fallback;
            Context::set('__prompt.fallbacks', $fallbacks);
        } else {
            static::$fallbacks[static::class] = $fallback;
        }
    }

    /**
     * Call the registered fallback implementation.
     */
    public function fallback(): mixed
    {
        if (Coroutine::inCoroutine()) {
            $fallbacks = Context::get('__prompt.fallbacks') ?? static::$fallbacks;
        } else {
            $fallbacks = static::$fallbacks;
        }

        $fallback = $fallbacks[static::class] ?? null;

        if ($fallback === null) {
            throw new RuntimeException('No fallback implementation registered for [' . static::class . ']');
        }

        return $fallback($this);
    }
}
