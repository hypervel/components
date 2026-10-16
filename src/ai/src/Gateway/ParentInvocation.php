<?php

declare(strict_types=1);

namespace Hypervel\Ai\Gateway;

use Closure;
use Hypervel\Context\CoroutineContext;

class ParentInvocation
{
    public const string PARENT_INVOCATION_CONTEXT_KEY = '__ai.parent_invocation';

    /**
     * Get the invocation and tool invocation the current run was delegated from.
     *
     * @return array{?string, ?string}
     */
    public static function current(): array
    {
        return CoroutineContext::get(static::PARENT_INVOCATION_CONTEXT_KEY, [null, null]);
    }

    /**
     * Run the given callback with the given invocation and tool invocation as the delegating parent.
     */
    public static function within(?string $invocationId, string $toolInvocationId, Closure $callback): mixed
    {
        $previous = CoroutineContext::get(static::PARENT_INVOCATION_CONTEXT_KEY);

        CoroutineContext::set(static::PARENT_INVOCATION_CONTEXT_KEY, [$invocationId, $toolInvocationId]);

        try {
            return $callback();
        } finally {
            if ($previous === null) {
                CoroutineContext::forget(static::PARENT_INVOCATION_CONTEXT_KEY);
            } else {
                CoroutineContext::set(static::PARENT_INVOCATION_CONTEXT_KEY, $previous);
            }
        }
    }
}
