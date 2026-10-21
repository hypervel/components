<?php

declare(strict_types=1);

namespace Hypervel\Tests\Telescope\Fixtures;

use Hypervel\Telescope\Watchers\QueryWatcher;

class ProxyFrameQueryWatcher extends QueryWatcher
{
    /**
     * Find the stack trace caller from inside the given proxy callback.
     */
    public function callerFromProxy(callable $proxy): ?array
    {
        return $proxy(fn (): ?array => $this->getCallerFromStackTrace());
    }
}
