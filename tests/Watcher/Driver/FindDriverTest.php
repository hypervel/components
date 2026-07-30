<?php

declare(strict_types=1);

namespace Hypervel\Tests\Watcher\Driver;

use Hypervel\Contracts\Log\StdoutLoggerInterface;
use Hypervel\Coroutine\WaitGroup;
use Hypervel\Engine\Channel;
use Hypervel\Engine\Coroutine;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\TestCase;
use Hypervel\Tests\Watcher\Fixtures\ContainerStub;
use Hypervel\Tests\Watcher\Fixtures\FindDriverStub;
use Hypervel\Watcher\Driver\FindDriver;
use Hypervel\Watcher\Option;
use Hypervel\Watcher\WatchPath;
use Hypervel\Watcher\WatchPathType;
use InvalidArgumentException;
use Mockery as m;

class FindDriverTest extends TestCase
{
    protected string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = ParallelTesting::tempDir('watcher-find-driver');
        $files = new Filesystem;
        $files->deleteDirectory($this->tempDir);
        $files->ensureDirectoryExists($this->tempDir);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->tempDir);

        parent::tearDown();
    }

    public function testWatch(): void
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
            $driver = new FindDriverStub($option, ContainerStub::getLogger());
            $finished = new WaitGroup(1);
            Coroutine::create(function () use ($channel, $driver, $finished): void {
                try {
                    $driver->watch($channel);
                } finally {
                    $finished->done();
                }
            });

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
            if (isset($finished)) {
                $this->assertTrue($finished->wait(0.1));
            }
            $channel->close();
        }
    }

    public function testFindEscapesEveryTargetPath(): void
    {
        $option = new Option(driver: FindDriver::class, watchPaths: [], scanInterval: 1);
        $driver = new class($option, ContainerStub::getLogger()) extends FindDriver {
            public string $capturedCommand = '';

            protected function exec(string $command): array
            {
                if (str_starts_with($command, 'which ')) {
                    return ['code' => 0, 'output' => '/usr/bin/find'];
                }

                if ($command === 'find --version') {
                    return ['code' => 0, 'output' => 'GNU findutils'];
                }

                $this->capturedCommand = $command;

                return ['code' => 0, 'output' => ''];
            }

            public function findForTest(array $targets): void
            {
                $this->find([], $targets, '-0.10');
            }
        };
        $targets = ['/tmp/path with spaces', "/tmp/path'quoted", '/tmp/$(ignored);touch nope'];

        $driver->findForTest($targets);

        $this->assertSame(
            ($driver->isDarwin() ? 'gfind' : 'find') . ' '
                . implode(' ', array_map(escapeshellarg(...), $targets))
                . ' -mmin -0.10 -type f -print',
            $driver->capturedCommand,
        );
    }

    public function testNonGnuFindUsesWholeMinuteIntervals(): void
    {
        $option = new Option(driver: FindDriver::class, watchPaths: [], scanInterval: 1000);
        $driver = new class($option, ContainerStub::getLogger()) extends FindDriver {
            protected function exec(string $command): array
            {
                return $command === 'which find'
                    ? ['code' => 0, 'output' => '/usr/bin/find']
                    : ['code' => 1, 'output' => 'BusyBox'];
            }

            public function intervalForTest(): string
            {
                return $this->getScanIntervalMinutes();
            }
        };

        $this->assertSame('-1', $driver->intervalForTest());
    }

    public function testFailedScanWarnsOnceAndProcessesValidOutput(): void
    {
        $file = $this->tempDir . '/changed.php';
        touch($file);

        $logger = m::mock(StdoutLoggerInterface::class);
        $logger->shouldReceive('warning')
            ->once()
            ->with('One or more find commands exited with code 1 while scanning watched paths.');

        $option = new Option(
            driver: FindDriver::class,
            watchPaths: [new WatchPath('changed.php', WatchPathType::File)],
            scanInterval: 1000,
        );
        $driver = new class($option, $logger, $this->tempDir, $file) extends FindDriver {
            public function __construct(
                Option $option,
                StdoutLoggerInterface $logger,
                private string $target,
                private string $file,
            ) {
                parent::__construct($option, $logger);
            }

            protected function exec(string $command): array
            {
                if ($command === 'which find') {
                    return ['code' => 0, 'output' => '/usr/bin/find'];
                }

                if ($command === 'find --version') {
                    return ['code' => 0, 'output' => 'GNU findutils'];
                }

                return ['code' => 1, 'output' => $this->file];
            }

            protected function resolveTargets(array $watchPaths): array
            {
                return $watchPaths === [] ? [] : [$this->target];
            }

            public function scanForTest(): array
            {
                $this->startTime = 0;

                return $this->scan([], '-1');
            }
        };

        [, $changedFiles] = $driver->scanForTest();

        $this->assertSame([$file], $changedFiles);
    }

    public function testFilesChangedInTheStartingSecondAreReported(): void
    {
        $file = $this->tempDir . '/changed.php';
        touch($file);
        $modifiedAt = filemtime($file);
        $this->assertIsInt($modifiedAt);

        $driver = new class($this->option(), ContainerStub::getLogger(), $file, $modifiedAt) extends FindDriver {
            public function __construct(
                Option $option,
                StdoutLoggerInterface $logger,
                private string $file,
                private int $modifiedAt,
            ) {
                parent::__construct($option, $logger);
            }

            protected function exec(string $command): array
            {
                if ($command === 'which find') {
                    return ['code' => 0, 'output' => '/usr/bin/find'];
                }

                if ($command === 'find --version') {
                    return ['code' => 0, 'output' => 'GNU findutils'];
                }

                return ['code' => 0, 'output' => $this->file];
            }

            public function findForTest(): array
            {
                $this->startTime = $this->modifiedAt;

                return $this->find([], [$this->file], '-1');
            }
        };

        [, $changedFiles] = $driver->findForTest();

        $this->assertSame([$file], $changedFiles);
    }

    public function testFileDisappearanceDuringMetadataReadIsIgnored(): void
    {
        $missing = $this->tempDir . '/missing.php';
        $driver = new class($this->option(), ContainerStub::getLogger(), $missing) extends FindDriver {
            public function __construct(
                Option $option,
                StdoutLoggerInterface $logger,
                private string $missing,
            ) {
                parent::__construct($option, $logger);
            }

            protected function exec(string $command): array
            {
                if ($command === 'which find') {
                    return ['code' => 0, 'output' => '/usr/bin/find'];
                }

                if ($command === 'find --version') {
                    return ['code' => 0, 'output' => 'GNU findutils'];
                }

                return ['code' => 0, 'output' => $this->missing];
            }

            public function findForTest(): array
            {
                return $this->find([], [$this->missing], '-1');
            }
        };

        [, $changedFiles] = $driver->findForTest();

        $this->assertSame([], $changedFiles);
    }

    public function testMissingTargetsAreSkippedAndAdoptedWhenTheyAppear(): void
    {
        $missing = $this->tempDir . '/missing.php';
        $late = $this->tempDir . '/late.php';
        $option = new Option(
            driver: FindDriver::class,
            watchPaths: [
                new WatchPath('missing.php', WatchPathType::File),
                new WatchPath('late.php', WatchPathType::File),
            ],
            scanInterval: 1000,
        );
        $driver = new class($option, ContainerStub::getLogger(), $missing, $late) extends FindDriver {
            public int $scanCommands = 0;

            public string $lastCommand = '';

            public function __construct(
                Option $option,
                StdoutLoggerInterface $logger,
                private string $missing,
                private string $late,
            ) {
                parent::__construct($option, $logger);
            }

            protected function exec(string $command): array
            {
                if (str_starts_with($command, 'which ')) {
                    return ['code' => 0, 'output' => '/usr/bin/find'];
                }

                if (str_ends_with($command, ' --version')) {
                    return ['code' => 0, 'output' => 'GNU findutils'];
                }

                ++$this->scanCommands;
                $this->lastCommand = $command;

                return ['code' => 0, 'output' => $this->late];
            }

            protected function resolveTargets(array $watchPaths): array
            {
                return $watchPaths === [] ? [] : [$this->missing, $this->late];
            }

            public function scanForTest(): array
            {
                $this->startTime = 0;

                return $this->scan([], '-1');
            }
        };

        [, $initialChanges] = $driver->scanForTest();
        $this->assertSame([], $initialChanges);
        $this->assertSame(0, $driver->scanCommands);

        touch($late);

        [, $changedFiles] = $driver->scanForTest();

        $this->assertSame([$late], $changedFiles);
        $this->assertSame(1, $driver->scanCommands);
        $this->assertStringNotContainsString($missing, $driver->lastCommand);
    }

    /**
     * Create standard find options.
     */
    private function option(): Option
    {
        return new Option(driver: FindDriver::class, watchPaths: [], scanInterval: 1000);
    }
}
