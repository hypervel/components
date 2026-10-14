<?php

declare(strict_types=1);

namespace Hypervel\Tests\Telescope\Fixtures;

use GuzzleHttp\Promise\Promise;
use Hypervel\Telescope\Watchers\QueryWatcher;

class PromiseHandlerQueryWatcher extends QueryWatcher
{
    /**
     * Find the stack trace caller from inside a Guzzle promise handler.
     */
    public function callerFromPromiseHandler(): ?array
    {
        $caller = null;
        $promise = new Promise;
        $handled = $promise->then(function () use (&$caller): void {
            $caller = $this->getCallerFromStackTrace();
        });

        $promise->resolve(null);
        $handled->wait();

        return $caller;
    }
}
