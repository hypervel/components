<?php

declare(strict_types=1);

namespace Hypervel\Queue\Connectors;

use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Queue\Queue;
use Hypervel\Queue\FailoverQueue;
use Hypervel\Queue\QueueManager;
use Hypervel\Support\Arr;

class FailoverConnector implements ConnectorInterface
{
    /**
     * Create a new connector instance.
     */
    public function __construct(
        protected QueueManager $manager,
        protected Dispatcher $events
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
            Arr::get($config, 'after_commit', true),
        );
    }
}
