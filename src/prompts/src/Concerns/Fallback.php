<?php

declare(strict_types=1);

namespace Hypervel\Prompts\Concerns;

use Closure;
use Hypervel\Context\CoroutineContext;
use Hypervel\Coroutine\Coroutine;
use RuntimeException;

trait Fallback
{
    /**
     * Context key for the fallback condition.
     */
    protected const SHOULD_FALLBACK_CONTEXT_KEY = '__prompts.should_fallback';

    /**
     * Context key for the fallback implementations.
     */
    protected const FALLBACKS_CONTEXT_KEY = '__prompts.fallbacks';

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
            CoroutineContext::set(self::SHOULD_FALLBACK_CONTEXT_KEY, $condition);
        } else {
            static::$shouldFallback = $condition;
        }
    }

    /**
     * Whether the prompt should fallback to a custom implementation.
     */
    public static function shouldFallback(): bool
    {
        if (Coroutine::inCoroutine()) {
            $shouldFallback = CoroutineContext::get(self::SHOULD_FALLBACK_CONTEXT_KEY) ?? static::$shouldFallback;
            $fallbacks = CoroutineContext::get(self::FALLBACKS_CONTEXT_KEY) ?? static::$fallbacks;

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
            $fallbacks = CoroutineContext::get(self::FALLBACKS_CONTEXT_KEY) ?? static::$fallbacks;
            $fallbacks[static::class] = $fallback;
            CoroutineContext::set(self::FALLBACKS_CONTEXT_KEY, $fallbacks);
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
            $fallbacks = CoroutineContext::get(self::FALLBACKS_CONTEXT_KEY) ?? static::$fallbacks;
        } else {
            $fallbacks = static::$fallbacks;
        }

        $fallback = $fallbacks[static::class] ?? null;

        if ($fallback === null) {
            throw new RuntimeException('No fallback implementation registered for [' . static::class . ']');
        }

        return $fallback($this);
    }

    /**
     * Reset fallback state to defaults.
     */
    public static function resetFallback(): void
    {
        static::$shouldFallback = false;
        static::$fallbacks = [];
        CoroutineContext::forget(self::SHOULD_FALLBACK_CONTEXT_KEY);
        CoroutineContext::forget(self::FALLBACKS_CONTEXT_KEY);
    }
}
