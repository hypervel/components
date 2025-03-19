<?php

declare(strict_types=1);

namespace Hypervel\Queue\Connectors;

use Hypervel\Queue\Contracts\Queue;

interface ConnectorInterface
{
    /**
     * Establish a queue connection.
     */
    public function connect(array $config): Queue;
}
