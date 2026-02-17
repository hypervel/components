<?php

declare(strict_types=1);

namespace Hypervel\Queue\Connectors;

use Hypervel\Contracts\Queue\Queue;

interface ConnectorInterface
{
    /**
     * Establish a queue connection.
     */
    public function connect(array $config): Queue;
}
