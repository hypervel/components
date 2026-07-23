<?php

declare(strict_types=1);

namespace Hypervel\Tests\Watcher\Driver;

use Hypervel\Coroutine\WaitGroup;
use Hypervel\Engine\Channel;
use Hypervel\Engine\Coroutine;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Tests\TestCase;
use Hypervel\Tests\Watcher\Fixtures\ContainerStub;
use Hypervel\Tests\Watcher\Fixtures\ScanFileDriverStub;
use Hypervel\Watcher\Driver\ScanFileDriver;
use Hypervel\Watcher\Option;
use Hypervel\Watcher\WatchPath;
use Hypervel\Watcher\WatchPathType;
use Mockery as m;
use Symfony\Component\Finder\Exception\DirectoryNotFoundException;

class ScanFileDriverTest extends TestCase
{
    public function testWatch(): void
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
        $finished = new WaitGroup(1);

        Coroutine::create(function () use ($channel, $driver, $finished): void {
            try {
                $driver->watch($channel);
            } finally {
                $finished->done();
            }
        });

        try {
            $this->assertStringEndsWith('.env', $channel->pop(($option->getScanIntervalSeconds() * 2) + 0.1));
        } finally {
            $driver->stop();
            $this->assertTrue($finished->wait(0.1));
            $channel->close();
        }
    }

    public function testAddAndModifyInSameCycleReportsBothCorrectly(): void
    {
        $option = new Option(
            driver: ScanFileDriver::class,
            watchPaths: [
                new WatchPath('/tmp', WatchPathType::Directory),
            ],
            scanInterval: 1,
        );

        $logger = ContainerStub::getLogger();

        // Anonymous stub that returns different file hash maps on successive calls.
        // Tick 1: {A, C} — establishes baseline.
        // Tick 2: {A, B, C_changed} — B is added, C is modified, A is unchanged.
        $driver = new class($option, $logger) extends ScanFileDriver {
            private int $callCount = 0;

            protected function getWatchFileHashes(): array
            {
                return match (++$this->callCount) {
                    1 => ['/tmp/A.php' => 'hash_a', '/tmp/C.php' => 'hash_c'],
                    default => ['/tmp/A.php' => 'hash_a', '/tmp/B.php' => 'hash_b', '/tmp/C.php' => 'hash_c_changed'],
                };
            }
        };

        $channel = new Channel(10);
        $finished = new WaitGroup(1);
        Coroutine::create(function () use ($channel, $driver, $finished): void {
            try {
                $driver->watch($channel);
            } finally {
                $finished->done();
            }
        });

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
            $this->assertTrue($finished->wait(0.1));
            $channel->close();
        }
    }

    public function testEmptyBaselineReportsNewFiles(): void
    {
        $option = new Option(
            driver: ScanFileDriver::class,
            watchPaths: [
                new WatchPath('/tmp', WatchPathType::Directory),
            ],
            scanInterval: 1,
        );

        $logger = ContainerStub::getLogger();

        $driver = new class($option, $logger) extends ScanFileDriver {
            public function process(Channel $channel, array $fileHashes): void
            {
                $this->processFileHashes($channel, $fileHashes);
            }
        };

        $channel = new Channel(10);

        try {
            $driver->process($channel, []);
            $driver->process($channel, ['/tmp/B.php' => 'hash_b']);

            $this->assertSame('/tmp/B.php', $channel->pop(0.1));
            $this->assertFalse($channel->pop(0.01));
        } finally {
            $driver->stop();
            $channel->close();
        }
    }

    public function testUnreadableFileHashesReturnNull(): void
    {
        $option = new Option(
            driver: ScanFileDriver::class,
            watchPaths: [
                new WatchPath('/tmp/unreadable.php', WatchPathType::File),
            ],
            scanInterval: 1,
        );

        $logger = ContainerStub::getLogger();
        $filesystem = m::mock(Filesystem::class);
        $filesystem->shouldReceive('hash')
            ->once()
            ->with('/tmp/unreadable.php')
            ->andReturn(false);

        $driver = new class($option, $logger, $filesystem) extends ScanFileDriver {
            public function hashPath(string $path): ?string
            {
                return $this->hashFile($path);
            }
        };

        $this->assertNull($driver->hashPath('/tmp/unreadable.php'));

        $driver->stop();
    }

    public function testAddedModifiedAndDeletedFilesAreReportedIndependently(): void
    {
        $driver = new class(new Option(driver: ScanFileDriver::class), ContainerStub::getLogger()) extends ScanFileDriver {
            public function process(Channel $channel, array $fileHashes): void
            {
                $this->processFileHashes($channel, $fileHashes);
            }
        };
        $channel = new Channel(3);

        try {
            $driver->process($channel, [
                '/tmp/unchanged.php' => 'same',
                '/tmp/modified.php' => 'old',
                '/tmp/deleted.php' => 'deleted',
            ]);
            $driver->process($channel, [
                '/tmp/unchanged.php' => 'same',
                '/tmp/modified.php' => 'new',
                '/tmp/added.php' => 'added',
            ]);

            $this->assertSame('/tmp/added.php', $channel->pop(0.1));
            $this->assertSame('/tmp/deleted.php', $channel->pop(0.1));
            $this->assertSame('/tmp/modified.php', $channel->pop(0.1));
        } finally {
            $driver->stop();
            $channel->close();
        }
    }

    public function testMissingDirectoryDoesNotPreventLaterRootsFromBeingScanned(): void
    {
        $filesystem = m::mock(Filesystem::class);
        $filesystem->shouldReceive('allFiles')
            ->once()
            ->with('/tmp/missing')
            ->andThrow(new DirectoryNotFoundException('/tmp/missing'));
        $filesystem->shouldReceive('allFiles')
            ->once()
            ->with('/tmp/present')
            ->andReturn([]);

        $option = new Option(
            driver: ScanFileDriver::class,
            watchPaths: [
                new WatchPath('missing', WatchPathType::Directory),
                new WatchPath('present', WatchPathType::Directory),
            ],
        );
        $driver = new class($option, ContainerStub::getLogger(), $filesystem) extends ScanFileDriver {
            protected function resolveTargets(array $watchPaths): array
            {
                return ['/tmp/missing', '/tmp/present'];
            }

            public function fileHashesForTest(): array
            {
                return $this->getWatchFileHashes();
            }
        };

        $this->assertSame([], $driver->fileHashesForTest());
    }
}
