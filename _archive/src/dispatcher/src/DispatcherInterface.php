<?php

declare(strict_types=1);

namespace Hypervel\Dispatcher;

interface DispatcherInterface
{
    /**
     * Dispatch the given parameters.
     */
    public function dispatch(mixed ...$params): mixed;
}
