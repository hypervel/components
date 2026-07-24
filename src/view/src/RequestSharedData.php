<?php

declare(strict_types=1);

namespace Hypervel\View;

use Closure;
use Hypervel\Context\CoroutineContext;

class RequestSharedData
{
    /**
     * The coroutine context key for request-scoped shared view data.
     */
    protected const CONTEXT_KEY = '__view.request_shared_data';

    /**
     * Get the shared data for the current request.
     */
    public static function all(): array
    {
        return CoroutineContext::get(self::CONTEXT_KEY, []);
    }

    /**
     * Run a callback with additional request-scoped shared data.
     */
    public static function scope(array $data, Closure $callback): mixed
    {
        $hadPrevious = CoroutineContext::has(self::CONTEXT_KEY);
        $previous = CoroutineContext::get(self::CONTEXT_KEY, []);

        CoroutineContext::set(self::CONTEXT_KEY, array_merge($previous, $data));

        try {
            return $callback();
        } finally {
            if ($hadPrevious) {
                CoroutineContext::set(self::CONTEXT_KEY, $previous);
            } else {
                CoroutineContext::forget(self::CONTEXT_KEY);
            }
        }
    }
}
