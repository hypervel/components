<?php

declare(strict_types=1);

namespace Hypervel\Tests\Watcher\Driver;

use Hypervel\Engine\Channel;
use Hypervel\Tests\TestCase;
use Hypervel\Tests\Watcher\Fixtures\FindDriverStub;
use Hypervel\Watcher\Driver\FindDriver;
use Hypervel\Watcher\Option;
use Hypervel\Watcher\WatchPath;
use Hypervel\Watcher\WatchPathType;
use InvalidArgumentException;

/**
 * @internal
 * @coversNothing
 */
class FindDriverTest extends TestCase
{
    public function testWatch()
    {
        $option = new Option(
            driver: FindDriver::class,
            watchPaths: [
                new WatchPath('/tmp', WatchPathType::Directory),
                new WatchPath('.env', WatchPathType::File),
            ],
            scanInterval: 1,
        );

        $channel = new Channel(10);

        try {
            $driver = new FindDriverStub($option);
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
