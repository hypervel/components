<?php

declare(strict_types=1);

namespace Hypervel\Queue\Connectors;

use Hypervel\Contracts\Queue\Queue;
use Hypervel\Queue\NullQueue;

class NullConnector implements ConnectorInterface
{
    /**
     * Establish a queue connection.
     */
    public function connect(array $config): Queue
    {
        return new NullQueue();
    }
}
