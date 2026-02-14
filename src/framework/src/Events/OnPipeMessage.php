<?php

declare(strict_types=1);

namespace Hypervel\Framework\Events;

use Swoole\Server;

class OnPipeMessage
{
    /**
     * Create a new inter-worker pipe message event instance.
     */
    public function __construct(
        public readonly Server $server,
        public readonly int $fromWorkerId,
        public readonly mixed $data,
    ) {
    }
}
