<?php

declare(strict_types=1);

namespace Hypervel\Sentry\State;

use ArrayObject;
use Hypervel\Context\CoroutineContext;
use Sentry\State\RuntimeContext;
use Sentry\State\RuntimeContextStorageInterface;

class CoroutineRuntimeContextStorage implements RuntimeContextStorageInterface
{
    private const string CONTEXT_KEY = '__sentry.runtime_context';

    /**
     * Return the current coroutine's runtime context.
     *
     * Child coroutines may return their parent's context while retaining their
     * own ownership of it.
     */
    public function get(): ?RuntimeContext
    {
        /** @var ?SharedRuntimeContext $sharedRuntimeContext */
        $sharedRuntimeContext = CoroutineContext::get(self::CONTEXT_KEY);

        return $sharedRuntimeContext?->getRuntimeContext();
    }

    /**
     * Store a runtime context for the current coroutine.
     */
    public function set(RuntimeContext $runtimeContext): void
    {
        CoroutineContext::set(
            self::CONTEXT_KEY,
            new SharedRuntimeContext($runtimeContext),
        );
    }

    /**
     * Release the current coroutine's runtime context.
     *
     * Only the final owner returns the context so the SDK flushes it once.
     */
    public function remove(): ?RuntimeContext
    {
        /** @var ?SharedRuntimeContext $sharedRuntimeContext */
        $sharedRuntimeContext = CoroutineContext::get(self::CONTEXT_KEY);

        if ($sharedRuntimeContext === null) {
            return null;
        }

        CoroutineContext::forget(self::CONTEXT_KEY);

        return $sharedRuntimeContext->release();
    }

    /**
     * Share a parent's runtime context with a child coroutine.
     *
     * @param ArrayObject<string, mixed> $context
     * @param null|ArrayObject<string, mixed> $parentContext
     */
    public function inheritFrom(ArrayObject $context, ?ArrayObject $parentContext): bool
    {
        if (isset($context[self::CONTEXT_KEY])
            || $parentContext === null
            || ! isset($parentContext[self::CONTEXT_KEY])) {
            return false;
        }

        /** @var SharedRuntimeContext $sharedRuntimeContext */
        $sharedRuntimeContext = $parentContext[self::CONTEXT_KEY];
        $sharedRuntimeContext->retain();
        $context[self::CONTEXT_KEY] = $sharedRuntimeContext;

        return true;
    }
}
