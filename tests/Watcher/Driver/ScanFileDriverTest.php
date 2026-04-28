<?php

declare(strict_types=1);

namespace Hypervel\Tests\Watcher\Driver;

use Hypervel\Engine\Channel;
use Hypervel\Tests\TestCase;
use Hypervel\Tests\Watcher\Fixtures\ContainerStub;
use Hypervel\Tests\Watcher\Fixtures\ScanFileDriverStub;
use Hypervel\Watcher\Driver\ScanFileDriver;
use Hypervel\Watcher\Option;
use Hypervel\Watcher\WatchPath;
use Hypervel\Watcher\WatchPathType;

class ScanFileDriverTest extends TestCase
{
    public function testWatch()
    {
        $option = new Option(
            driver: ScanFileDriver::class,
            watchPaths: [
                new WatchPath('/tmp', WatchPathType::Directory),
                new WatchPath('.env', WatchPathType::File),
            ],
            scanInterval: 1,
        );

        $channel = new Channel(10);
        $driver = new ScanFileDriverStub($option, ContainerStub::getLogger());

        $driver->watch($channel);

        try {
            $this->assertStringEndsWith('.env', $channel->pop($option->getScanIntervalSeconds() + 0.1));
        } finally {
            $driver->stop();
            $channel->close();
        }
    }

    public function testAddAndModifyInSameCycleReportsBothCorrectly()
    {
        $option = new Option(
            driver: ScanFileDriver::class,
            watchPaths: [
                new WatchPath('/tmp', WatchPathType::Directory),
            ],
            scanInterval: 1,
        );

        $logger = ContainerStub::getLogger();
        $logger->shouldReceive('warning')->andReturn(null);

        // Anonymous stub that returns different MD5 maps on successive calls.
        // Tick 1: {A, C} — establishes baseline.
        // Tick 2: {A, B, C_changed} — B is added, C is modified, A is unchanged.
        $driver = new class($option, $logger) extends ScanFileDriver {
            private int $callCount = 0;

            protected function getWatchMD5(): array
            {
                return match (++$this->callCount) {
                    1 => ['/tmp/A.php' => 'hash_a', '/tmp/C.php' => 'hash_c'],
                    default => ['/tmp/A.php' => 'hash_a', '/tmp/B.php' => 'hash_b', '/tmp/C.php' => 'hash_c_changed'],
                };
            }
        };

        $channel = new Channel(10);
        $driver->watch($channel);

        try {
            // Wait for two ticks to fire (baseline + detection).
            $pushed = [];
            $timeout = 0.2;
            while (($file = $channel->pop($timeout)) !== false) {
                $pushed[] = $file;
                $timeout = 0.05;
            }

            // B should be reported as added, C as modified. A should NOT appear.
            $this->assertContains('/tmp/B.php', $pushed);
            $this->assertContains('/tmp/C.php', $pushed);
            $this->assertNotContains('/tmp/A.php', $pushed);
            $this->assertCount(2, $pushed);
        } finally {
            $driver->stop();
            $channel->close();
        }
    }
}
