<?php

declare(strict_types=1);

namespace Hypervel\Queue\Connectors;

use Hypervel\Contracts\Queue\Queue;
use Hypervel\Queue\SyncQueue;
use Hypervel\Support\Arr;

class SyncConnector implements ConnectorInterface
{
    /**
     * Establish a queue connection.
     */
    public function connect(array $config): Queue
    {
        return new SyncQueue(Arr::get($config, 'after_commit', false));
    }
}
