<?php

declare(strict_types=1);

namespace Hypervel\Sentry\State;

use Hypervel\Coroutine\Coroutine;
use Sentry\SentrySdk;
use Sentry\State\HubInterface;

class RuntimeContextBoundary
{
    /**
     * Create a runtime context boundary.
     */
    public function __construct(
        private readonly HubInterface $hub,
        private readonly CoroutineRuntimeContextStorage $runtimeContextStorage,
    ) {
    }

    /**
     * Start a runtime context for the current coroutine.
     *
     * Work outside a coroutine uses the global context because no defer can own
     * its end. Nested boundaries reuse their active context so it is not
     * released early. The defer is registered immediately so callback failures
     * still release the context.
     */
    public function start(): void
    {
        if (! Coroutine::inCoroutine() || $this->runtimeContextStorage->get() !== null) {
            return;
        }

        SentrySdk::startContext($this->hub);
        Coroutine::defer(static function (): void {
            SentrySdk::endContext();
        });
    }
}
