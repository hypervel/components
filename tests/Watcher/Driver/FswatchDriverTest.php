<?php

declare(strict_types=1);

namespace Hypervel\Tests\Watcher\Driver;

use Hypervel\Engine\Channel;
use Hypervel\Tests\TestCase;
use Hypervel\Tests\Watcher\Fixtures\FswatchDriverStub;
use Hypervel\Watcher\Driver\FswatchDriver;
use Hypervel\Watcher\Option;
use Hypervel\Watcher\WatchPath;
use Hypervel\Watcher\WatchPathType;
use InvalidArgumentException;

class FswatchDriverTest extends TestCase
{
    public function testWatch()
    {
        $option = new Option(
            driver: FswatchDriver::class,
            watchPaths: [
                new WatchPath('/tmp', WatchPathType::Directory),
                new WatchPath('.env', WatchPathType::File),
            ],
            scanInterval: 1,
        );

        $channel = new Channel(10);

        try {
            $driver = new FswatchDriverStub($option);
            $driver->watch($channel);

            $this->assertSame('.env', $channel->pop($option->getScanIntervalSeconds() + 0.1));
        } catch (InvalidArgumentException $e) {
            if (str_contains($e->getMessage(), 'fswatch not exists')) {
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

    public function testStopTerminatesAndClosesProcess()
    {
        $option = new Option(
            driver: FswatchDriver::class,
            watchPaths: [
                new WatchPath('/tmp', WatchPathType::Directory),
            ],
            scanInterval: 1,
        );

        // Stub that bypasses the `which fswatch` check and exposes a setter for the process handle.
        $driver = new class($option) extends FswatchDriver {
            protected function exec(string $command): array
            {
                return ['code' => 0, 'output' => '/usr/bin/fswatch'];
            }

            public function setProcess(mixed $process): void
            {
                $this->process = $process;
            }
        };

        // Start a real child process.
        $process = proc_open('sleep 60', [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        $this->assertTrue(is_resource($process));

        $pid = proc_get_status($process)['pid'];
        $this->assertTrue(posix_kill($pid, 0), 'Process should be running before stop()');

        $driver->setProcess($process);
        $driver->stop();

        // After stop(), the process should be killed and the handle closed.
        $this->assertFalse(is_resource($process), 'Process handle should be closed after stop()');
        $this->assertFalse(posix_kill($pid, 0), 'Process should not be running after stop()');
    }
}
