<?php

declare(strict_types=1);

namespace Hypervel\Queue\Connectors;

use Closure;
use Hypervel\Contracts\Queue\Queue;
use Hypervel\Queue\DeferredQueue;

class DeferredConnector implements ConnectorInterface
{
    /**
     * Create a new deferred connector instance.
     */
    public function __construct(
        protected ?Closure $exceptionCallback = null
    ) {
    }

    /**
     * Establish a queue connection.
     */
    public function connect(array $config): Queue
    {
        return (new DeferredQueue($config['after_commit']))
            ->setExceptionCallback($this->exceptionCallback);
    }
}
