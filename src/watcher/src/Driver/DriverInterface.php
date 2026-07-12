<?php

declare(strict_types=1);

namespace Hypervel\Watcher\Driver;

use Hypervel\Engine\Channel;

interface DriverInterface
{
    /**
     * Run the watch loop, pushing changed file paths into the channel.
     *
     * This method blocks for one driver lifecycle. It returns only after
     * terminal completion or stop() releases the driver's suspended work.
     */
    public function watch(Channel $channel): void;

    /**
     * Stop watching and release the driver's resources.
     *
     * Idempotent — teardown paths may invoke it multiple times.
     */
    public function stop(): void;
}
