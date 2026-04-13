<?php

declare(strict_types=1);

namespace Hypervel\Watcher\Driver;

use Hypervel\Engine\Channel;

interface DriverInterface
{
    public function watch(Channel $channel): void;
}
