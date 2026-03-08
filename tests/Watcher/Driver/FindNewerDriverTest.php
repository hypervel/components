<?php

declare(strict_types=1);

namespace Hypervel\Tests\Watcher\Driver;

use Hypervel\Engine\Channel;
use Hypervel\Foundation\Testing\Concerns\RunTestsInCoroutine;
use Hypervel\Tests\TestCase;
use Hypervel\Tests\Watcher\Fixtures\ContainerStub;
use Hypervel\Tests\Watcher\Fixtures\FindNewerDriverStub;
use Hypervel\Watcher\Driver\FindNewerDriver;
use Hypervel\Watcher\Option;
use InvalidArgumentException;

/**
 * @internal
 * @coversNothing
 */
class FindNewerDriverTest extends TestCase
{
    use RunTestsInCoroutine;

    public function testWatch()
    {
        $container = ContainerStub::getContainer(FindNewerDriver::class);
        $option = new Option($container->make('config')->get('watcher'), [], []);
        $channel = new Channel(10);

        try {
            $driver = new FindNewerDriverStub($option);
            $driver->watch($channel);
            $this->assertSame('.env', $channel->pop($option->getScanIntervalSeconds() + 0.1));
        } catch (InvalidArgumentException $e) {
            if (str_contains($e->getMessage(), 'find not exists')) {
                $this->markTestSkipped();
            }
            throw $e;
        } finally {
            if (isset($driver)) {
                $driver->stop();
            }
            $channel->close();
        }
    }
}
