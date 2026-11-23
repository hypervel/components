<?php

declare(strict_types=1);

namespace Hypervel\Tests\Watcher\Fixtures;

use Hypervel\Contracts\Log\StdoutLoggerInterface;
use JMac\Testing\Double;

class ContainerStub
{
    /**
     * Create a logger that accepts routine watcher messages.
     */
    public static function getLogger(): StdoutLoggerInterface
    {
        $logger = Double::for(StdoutLoggerInterface::class)->strict();
        $logger->allows('debug');
        $logger->allows('warning');
        $logger->allows('log');

        return $logger;
    }
}
