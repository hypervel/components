<?php

declare(strict_types=1);

namespace Hypervel\Reverb\Events;

use Hypervel\Foundation\Events\Dispatchable;
use Hypervel\Reverb\Contracts\Connection;

class ConnectionEstablished
{
    use Dispatchable;

    /**
     * Create a new event instance.
     */
    public function __construct(public Connection $connection)
    {
    }
}
