<?php

declare(strict_types=1);

namespace Hypervel\Queue\Connectors;

use Hypervel\Contracts\Queue\Queue;
use Hypervel\Queue\FailoverQueue;
use Hypervel\Queue\QueueManager;
use Psr\EventDispatcher\EventDispatcherInterface;

class FailoverConnector implements ConnectorInterface
{
    /**
     * Create a new connector instance.
     */
    public function __construct(
        protected QueueManager $manager,
        protected EventDispatcherInterface $events
    ) {
    }

    /**
     * Establish a queue connection.
     */
    public function connect(array $config): Queue
    {
        return new FailoverQueue(
            $this->manager,
            $this->events,
            $config['connections'],
        );
    }
}
