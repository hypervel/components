<?php

declare(strict_types=1);

namespace Hypervel\Tests\Watcher\Driver;

use Hypervel\Contracts\Log\StdoutLoggerInterface;
use Hypervel\Engine\Channel;
use Hypervel\Foundation\Testing\Concerns\RunTestsInCoroutine;
use Hypervel\Tests\TestCase;
use Hypervel\Tests\Watcher\Fixtures\ContainerStub;
use Hypervel\Tests\Watcher\Fixtures\ScanFileDriverStub;
use Hypervel\Watcher\Driver\ScanFileDriver;
use Hypervel\Watcher\Option;

/**
 * @internal
 * @coversNothing
 */
class ScanFileDriverTest extends TestCase
{
    use RunTestsInCoroutine;

    public function testWatch()
    {
        $container = ContainerStub::getContainer(ScanFileDriver::class);
        $option = new Option($container->make('config')->get('watcher'), [], []);

        $channel = new Channel(10);
        $driver = new ScanFileDriverStub($option, $container->make(StdoutLoggerInterface::class));

        $driver->watch($channel);

        try {
            $this->assertStringEndsWith('.env', $channel->pop($option->getScanIntervalSeconds() + 0.1));
        } finally {
            $driver->stop();
            $channel->close();
        }
    }
}
