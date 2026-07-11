<?php

declare(strict_types=1);

namespace Hypervel\Watcher\Driver;

use Hypervel\Engine\Channel;

interface DriverInterface
{
    /**
     * Run the watch loop, pushing changed file paths into the channel.
     *
     * Deterministic shutdown is stop(): it releases the driver's resources and
     * unblocks suspended I/O. A closing channel is observed opportunistically
     * and is not guaranteed to interrupt blocked I/O.
     */
    public function watch(Channel $channel): void;

    /**
     * Stop watching and release the driver's resources.
     *
     * Idempotent — teardown paths may invoke it multiple times.
     */
    public function stop(): void;
}
