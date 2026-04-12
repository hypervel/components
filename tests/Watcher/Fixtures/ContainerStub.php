<?php

declare(strict_types=1);

namespace Hypervel\Tests\Watcher\Fixtures;

use Hypervel\Contracts\Log\StdoutLoggerInterface;
use Mockery as m;
use Mockery\MockInterface;

class ContainerStub
{
    public static function getLogger(): StdoutLoggerInterface|MockInterface
    {
        $logger = m::mock(StdoutLoggerInterface::class);
        $logger->shouldReceive('debug')->andReturn(null);
        $logger->shouldReceive('log')->andReturn(null);

        return $logger;
    }
}
