<?php

declare(strict_types=1);

namespace Hypervel\Tests\Watcher\Driver;

use Hypervel\Engine\Channel;
use Hypervel\Tests\TestCase;
use Hypervel\Tests\Watcher\Fixtures\FindNewerDriverStub;
use Hypervel\Watcher\Driver\FindNewerDriver;
use Hypervel\Watcher\Option;
use Hypervel\Watcher\WatchPath;
use Hypervel\Watcher\WatchPathType;
use InvalidArgumentException;
use RuntimeException;

/**
 * @internal
 * @coversNothing
 */
class FindNewerDriverTest extends TestCase
{
    public function testWatch()
    {
        $option = new Option(
            driver: FindNewerDriver::class,
            watchPaths: [
                new WatchPath('/tmp', WatchPathType::Directory),
                new WatchPath('.env', WatchPathType::File),
            ],
            scanInterval: 1,
        );

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

    public function testRecoveryAfterScanException()
    {
        $option = new Option(
            driver: FindNewerDriver::class,
            watchPaths: [
                new WatchPath('/tmp', WatchPathType::Directory),
            ],
            scanInterval: 1,
        );

        // Stub that throws on first scan(), succeeds on second, then returns empty.
        // exec() is stubbed to bypass shell commands (which find, echo).
        $driver = new class($option) extends FindNewerDriver {
            private int $scanCallCount = 0;

            protected function exec(string $command): array
            {
                return ['code' => 0, 'output' => '/usr/bin/find'];
            }

            protected function scan(): array
            {
                return match (++$this->scanCallCount) {
                    1 => throw new RuntimeException('Simulated scan failure'),
                    2 => ['/tmp/recovered.php'],
                    default => [],
                };
            }
        };

        $channel = new Channel(10);
        $driver->watch($channel);

        try {
            // First tick throws (recovered via finally), second tick succeeds.
            $file = $channel->pop(0.2);
            $this->assertSame('/tmp/recovered.php', $file);
        } finally {
            $driver->stop();
            $channel->close();
        }
    }

    public function testAllChangedFilesAreReported()
    {
        $option = new Option(
            driver: FindNewerDriver::class,
            watchPaths: [
                new WatchPath('/tmp', WatchPathType::Directory),
            ],
            scanInterval: 1,
        );

        // Stub that returns multiple files on first tick, then empty.
        // This prevents the timer from continuously filling the channel.
        $driver = new class($option) extends FindNewerDriver {
            private int $scanCallCount = 0;

            protected function exec(string $command): array
            {
                return ['code' => 0, 'output' => '/usr/bin/find'];
            }

            protected function scan(): array
            {
                if (++$this->scanCallCount === 1) {
                    return ['/tmp/a.php', '/tmp/b.php', '/tmp/c.php'];
                }

                return [];
            }
        };

        $channel = new Channel(10);
        $driver->watch($channel);

        try {
            // Collect all pushed files from the first tick.
            $pushed = [];
            $timeout = 0.2;
            while (($file = $channel->pop($timeout)) !== false) {
                $pushed[] = $file;
                $timeout = 0.05;
            }

            $this->assertContains('/tmp/a.php', $pushed);
            $this->assertContains('/tmp/b.php', $pushed);
            $this->assertContains('/tmp/c.php', $pushed);
            $this->assertCount(3, $pushed);
        } finally {
            $driver->stop();
            $channel->close();
        }
    }
}
