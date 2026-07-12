<?php

declare(strict_types=1);

namespace Hypervel\Tests\Watcher\Driver;

use Hypervel\Coroutine\WaitGroup;
use Hypervel\Engine\Channel;
use Hypervel\Engine\Coroutine;
use Hypervel\Tests\TestCase;
use Hypervel\Tests\Watcher\Fixtures\FindNewerDriverStub;
use Hypervel\Watcher\Driver\FindNewerDriver;
use Hypervel\Watcher\Option;
use Hypervel\Watcher\WatchPath;
use Hypervel\Watcher\WatchPathType;
use InvalidArgumentException;
use RuntimeException;

class FindNewerDriverTest extends TestCase
{
    public function testWatch(): void
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

    public function testScanExceptionTerminatesTheWatchLifecycle(): void
    {
        $option = new Option(
            driver: FindNewerDriver::class,
            watchPaths: [
                new WatchPath('/tmp', WatchPathType::Directory),
            ],
            scanInterval: 1,
        );

        $driver = new class($option) extends FindNewerDriver {
            protected function exec(string $command): array
            {
                return ['code' => 0, 'output' => '/usr/bin/find'];
            }

            protected function scan(): array
            {
                throw new RuntimeException('Simulated scan failure');
            }
        };

        $channel = new Channel(10);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Simulated scan failure');

        try {
            $driver->watch($channel);
        } finally {
            $driver->stop();
            $channel->close();
        }
    }

    public function testAllChangedFilesAreReported(): void
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

                $this->stop();

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

    public function testFindEscapesTargetsAndTheReferencePath(): void
    {
        $driver = new class($this->option()) extends FindNewerDriver {
            public string $capturedCommand = '';

            protected function exec(string $command): array
            {
                if ($command === 'which find') {
                    return ['code' => 0, 'output' => '/usr/bin/find'];
                }

                $this->capturedCommand = $command;

                return ['code' => 0, 'output' => ''];
            }

            public function scanReferenceForTest(): string
            {
                return $this->getToScanFile();
            }

            public function findForTest(array $targets): void
            {
                $this->find($targets);
            }
        };
        $targets = ['/tmp/path with spaces', "/tmp/path'quoted", '/tmp/$(ignored);touch nope'];

        try {
            $driver->findForTest($targets);

            $reference = escapeshellarg($driver->scanReferenceForTest());
            $expected = implode('&', array_map(
                fn (string $target): string => 'find ' . escapeshellarg($target) . " -newer {$reference} -type f",
                $targets,
            ));
            $this->assertSame($expected, $driver->capturedCommand);
        } finally {
            $driver->stop();
        }
    }

    public function testReferenceFileUpdateCreatesAndBumpsTheFile(): void
    {
        $driver = new class($this->option()) extends FindNewerDriver {
            protected function exec(string $command): array
            {
                return ['code' => 0, 'output' => '/usr/bin/find'];
            }

            public function referenceFileForTest(): string
            {
                return $this->getToModifyFile();
            }

            public function updateForTest(string $path): void
            {
                parent::updateReferenceFile($path);
            }
        };
        $path = $driver->referenceFileForTest();

        try {
            $this->assertFileExists($path);

            touch($path, 1);
            clearstatcache(true, $path);
            $driver->updateForTest($path);
            clearstatcache(true, $path);

            $this->assertGreaterThan(1, filemtime($path));
        } finally {
            $driver->stop();
        }
    }

    public function testReferenceFileUpdateThrowsWhenTouchFails(): void
    {
        $driver = new class($this->option()) extends FindNewerDriver {
            protected function exec(string $command): array
            {
                return ['code' => 0, 'output' => '/usr/bin/find'];
            }

            public function updateForTest(string $path): void
            {
                parent::updateReferenceFile($path);
            }
        };
        $path = sys_get_temp_dir() . '/missing-hypervel-watcher-directory-' . getmypid() . '/reference';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Unable to update the watcher reference file [{$path}].");

        try {
            $driver->updateForTest($path);
        } finally {
            $driver->stop();
        }
    }

    public function testReferenceFilesAreUniquePerDriverAndRemovedOnStop(): void
    {
        $first = $this->referenceFileDriver();
        $second = $this->referenceFileDriver();
        $firstFiles = $first->referenceFilesForTest();
        $secondFiles = $second->referenceFilesForTest();

        $this->assertCount(2, $firstFiles);
        $this->assertCount(2, $secondFiles);
        $this->assertCount(4, array_unique([...$firstFiles, ...$secondFiles]));

        foreach ([...$firstFiles, ...$secondFiles] as $file) {
            $this->assertFileExists($file);
        }

        $first->stop();
        $first->stop();
        $second->stop();

        foreach ([...$firstFiles, ...$secondFiles] as $file) {
            $this->assertFileDoesNotExist($file);
        }
    }

    public function testStoppedDriverCannotStartAnotherWatchLifecycle(): void
    {
        $driver = $this->referenceFileDriver();
        $oldFiles = $driver->referenceFilesForTest();
        $driver->stop();
        $channel = new Channel(1);

        try {
            $driver->watch($channel);
            $this->assertSame([], $driver->referenceFilesForTest());

            foreach ($oldFiles as $file) {
                $this->assertFileDoesNotExist($file);
            }
        } finally {
            $driver->stop();
            $channel->close();
        }
    }

    public function testChangeMadeDuringScanRemainsEligibleForTheNextScan(): void
    {
        $completed = new Channel(1);
        $driver = new class($this->option(), $completed) extends FindNewerDriver {
            /** @var array<string, int> */
            public array $cutoffs = [];

            /** @var list<string> */
            public array $scanReferences = [];

            /** @var list<string> */
            public array $updatedReferences = [];

            public bool $lateChangeEligible = false;

            private int $clock = 0;

            private int $lateChangeAt = 0;

            private int $scanCount = 0;

            public function __construct(Option $option, private Channel $completed)
            {
                parent::__construct($option);
            }

            protected function exec(string $command): array
            {
                return ['code' => 0, 'output' => '/usr/bin/find'];
            }

            protected function createReferenceFile(): string
            {
                $path = parent::createReferenceFile();
                $this->cutoffs[$path] = 0;

                return $path;
            }

            protected function updateReferenceFile(string $path): void
            {
                $this->updatedReferences[] = $path;
                $this->cutoffs[$path] = ++$this->clock;
            }

            protected function scan(): array
            {
                $reference = $this->getToScanFile();
                $this->scanReferences[] = $reference;

                if (++$this->scanCount === 1) {
                    // This change happens after the active scan has passed its path.
                    $this->lateChangeAt = ++$this->clock;

                    return ['/tmp/another-change.php'];
                }

                $this->lateChangeEligible = $this->lateChangeAt > $this->cutoffs[$reference];
                $this->stop();
                $this->completed->push(true);

                return $this->lateChangeEligible ? ['/tmp/late-change.php'] : [];
            }
        };
        $output = new Channel(2);

        try {
            $driver->watch($output);
            $this->assertTrue($completed->pop(0.2));

            $this->assertTrue($driver->lateChangeEligible);
            $this->assertCount(2, $driver->updatedReferences);
            $this->assertCount(2, $driver->scanReferences);
            $this->assertSame($driver->updatedReferences[0], $driver->scanReferences[1]);
            $this->assertSame($driver->scanReferences[0], $driver->updatedReferences[1]);
        } finally {
            $driver->stop();
            $completed->close();
            $output->close();
        }
    }

    public function testQuietSuccessfulScanStillRotatesReferenceRoles(): void
    {
        $completed = new Channel(1);
        $driver = new class($this->option(), $completed) extends FindNewerDriver {
            /** @var list<string> */
            public array $scanReferences = [];

            /** @var list<string> */
            public array $updatedReferences = [];

            public function __construct(Option $option, private Channel $completed)
            {
                parent::__construct($option);
            }

            protected function exec(string $command): array
            {
                return ['code' => 0, 'output' => '/usr/bin/find'];
            }

            protected function updateReferenceFile(string $path): void
            {
                $this->updatedReferences[] = $path;
            }

            protected function scan(): array
            {
                $this->scanReferences[] = $this->getToScanFile();

                if (count($this->scanReferences) === 2) {
                    $this->stop();
                    $this->completed->push(true);
                }

                return [];
            }
        };
        $output = new Channel(1);

        try {
            $driver->watch($output);
            $this->assertTrue($completed->pop(0.2));

            $this->assertCount(2, $driver->updatedReferences);
            $this->assertCount(2, $driver->scanReferences);
            $this->assertSame($driver->updatedReferences[0], $driver->scanReferences[1]);
            $this->assertSame($driver->scanReferences[0], $driver->updatedReferences[1]);
        } finally {
            $driver->stop();
            $completed->close();
            $output->close();
        }
    }

    public function testStopDuringAYieldingReferenceUpdateSkipsTheScanAndDefersCleanup(): void
    {
        $entered = new Channel(1);
        $resume = new Channel(1);
        $removed = new Channel(1);
        $output = new Channel(1);
        $driver = new class($this->option(), $entered, $resume, $removed) extends FindNewerDriver {
            public bool $scanCalled = false;

            public int $updateCount = 0;

            public function __construct(
                Option $option,
                private Channel $entered,
                private Channel $resume,
                private Channel $removed,
            ) {
                parent::__construct($option);
            }

            protected function exec(string $command): array
            {
                return ['code' => 0, 'output' => '/usr/bin/find'];
            }

            protected function updateReferenceFile(string $path): void
            {
                ++$this->updateCount;
                $this->entered->push(true);
                $this->resume->pop();
            }

            protected function scan(): array
            {
                $this->scanCalled = true;

                return [];
            }

            protected function removeReferenceFiles(): void
            {
                $hadFiles = $this->referenceFiles !== [];

                parent::removeReferenceFiles();

                if ($hadFiles) {
                    $this->removed->push(true);
                }
            }

            public function referenceFilesForTest(): array
            {
                return $this->referenceFiles;
            }
        };
        $finished = new WaitGroup(1);

        try {
            Coroutine::create(function () use ($driver, $finished, $output): void {
                try {
                    $driver->watch($output);
                } finally {
                    $finished->done();
                }
            });
            $this->assertTrue($entered->pop(0.2));

            $driver->stop();
            $resume->push(true);

            $this->assertTrue($removed->pop(0.2));
            $this->assertTrue($finished->wait(0.2));
            $this->assertSame(1, $driver->updateCount);
            $this->assertFalse($driver->scanCalled);
            $this->assertSame([], $driver->referenceFilesForTest());
        } finally {
            $driver->stop();
            $entered->close();
            $resume->close();
            $removed->close();
            $output->close();
        }
    }

    public function testStopDuringAYieldingScanDefersCleanupAndRejectsAnImmediateRestart(): void
    {
        $entered = new Channel(1);
        $resume = new Channel(1);
        $removed = new Channel(1);
        $output = new Channel(1);
        $driver = new class($this->option(), $entered, $resume, $removed) extends FindNewerDriver {
            public int $updateCount = 0;

            public function __construct(
                Option $option,
                private Channel $entered,
                private Channel $resume,
                private Channel $removed,
            ) {
                parent::__construct($option);
            }

            protected function exec(string $command): array
            {
                return ['code' => 0, 'output' => '/usr/bin/find'];
            }

            protected function scan(): array
            {
                $this->entered->push(true);
                $this->resume->pop();

                return ['/tmp/changed.php'];
            }

            protected function updateReferenceFile(string $path): void
            {
                ++$this->updateCount;

                parent::updateReferenceFile($path);
            }

            protected function removeReferenceFiles(): void
            {
                $hadFiles = $this->referenceFiles !== [];

                parent::removeReferenceFiles();

                if ($hadFiles) {
                    $this->removed->push(true);
                }
            }

            public function referenceFilesForTest(): array
            {
                return $this->referenceFiles;
            }
        };
        $finished = new WaitGroup(1);

        try {
            Coroutine::create(function () use ($driver, $finished, $output): void {
                try {
                    $driver->watch($output);
                } finally {
                    $finished->done();
                }
            });
            $this->assertTrue($entered->pop(0.2));
            $files = $driver->referenceFilesForTest();

            $driver->stop();

            foreach ($files as $file) {
                $this->assertFileExists($file);
            }

            try {
                $driver->watch($output);
                $this->fail('Expected an immediate restart during scan shutdown to fail.');
            } catch (RuntimeException $exception) {
                $this->assertSame(
                    'Cannot restart the find-newer watcher while its previous scan is still stopping.',
                    $exception->getMessage(),
                );
            }

            $resume->push(true);
            $this->assertTrue($removed->pop(0.2));
            $this->assertTrue($finished->wait(0.2));
            $this->assertSame(1, $driver->updateCount);
            $this->assertSame([], $driver->referenceFilesForTest());

            foreach ($files as $file) {
                $this->assertFileDoesNotExist($file);
            }
        } finally {
            $driver->stop();
            $entered->close();
            $resume->close();
            $removed->close();
            $output->close();
        }
    }

    public function testSecondReferenceFileCreationFailureRemovesTheFirstFile(): void
    {
        $createdFiles = [];

        try {
            new class($this->option(), $createdFiles) extends FindNewerDriver {
                private int $creationCount = 0;

                /** @var list<string> */
                private array $createdFiles;

                public function __construct(Option $option, array &$createdFiles)
                {
                    $this->createdFiles = &$createdFiles;

                    parent::__construct($option);
                }

                protected function exec(string $command): array
                {
                    return ['code' => 0, 'output' => '/usr/bin/find'];
                }

                protected function createReferenceFile(): string
                {
                    if (++$this->creationCount === 2) {
                        throw new RuntimeException('Unable to create a watcher reference file.');
                    }

                    $path = parent::createReferenceFile();
                    $this->createdFiles[] = $path;

                    return $path;
                }
            };

            $this->fail('Expected reference-file creation to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Unable to create a watcher reference file.', $exception->getMessage());
        }

        $this->assertCount(1, $createdFiles);
        $this->assertFileDoesNotExist($createdFiles[0]);
    }

    /**
     * Create standard find-newer options.
     */
    private function option(): Option
    {
        return new Option(driver: FindNewerDriver::class, watchPaths: [], scanInterval: 1);
    }

    /**
     * Create a driver exposing its owned reference files.
     */
    private function referenceFileDriver(): FindNewerDriver
    {
        return new class($this->option()) extends FindNewerDriver {
            protected function exec(string $command): array
            {
                return ['code' => 0, 'output' => '/usr/bin/find'];
            }

            public function referenceFilesForTest(): array
            {
                return $this->referenceFiles;
            }
        };
    }
}
