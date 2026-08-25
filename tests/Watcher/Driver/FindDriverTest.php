<?php

declare(strict_types=1);

namespace Hypervel\Tests\Watcher\Driver;

use Hypervel\Contracts\Log\StdoutLoggerInterface;
use Hypervel\Coroutine\WaitGroup;
use Hypervel\Engine\Channel;
use Hypervel\Engine\Coroutine;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Testbench\TestCase;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\Watcher\Fixtures\ContainerStub;
use Hypervel\Tests\Watcher\Fixtures\FindDriverStub;
use Hypervel\Watcher\Driver\FindDriver;
use Hypervel\Watcher\Option;
use Hypervel\Watcher\WatchPath;
use Hypervel\Watcher\WatchPathType;
use InvalidArgumentException;
use Mockery as m;
use RuntimeException;

class FindDriverTest extends TestCase
{
    protected string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = ParallelTesting::tempDir('watcher-find-driver');
        $filesystem = new Filesystem;
        $filesystem->deleteDirectory($this->tempDir);
        $filesystem->ensureDirectoryExists($this->tempDir);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->tempDir);

        parent::tearDown();
    }

    public function testWatchPublishesEveryChangedFileAndCleansItsReferences(): void
    {
        $option = new Option(
            driver: FindDriver::class,
            watchPaths: [new WatchPath('.env', WatchPathType::File)],
            scanInterval: 1,
        );
        $channel = new Channel(2);

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

            $this->assertSame('.env', $channel->pop(0.1));
        } catch (InvalidArgumentException $exception) {
            if (str_contains($exception->getMessage(), 'requires the `find` executable')) {
                $this->markTestSkipped();
            }

            throw $exception;
        } finally {
            if (isset($driver)) {
                $driver->stop();
            }

            if (isset($finished)) {
                $this->assertTrue($finished->wait(0.1));
                $this->assertSame([], $driver->referenceFilesForTest());
            }

            $channel->close();
        }
    }

    public function testConstructorProbesThePortableSystemFindCommand(): void
    {
        $driver = new ScriptedFindDriver($this->option(), ContainerStub::getLogger());

        $this->assertSame('command -v find', $driver->commands[0]);
    }

    public function testConstructorUsesTheProbeExitCode(): void
    {
        $exception = null;

        try {
            new class($this->option(), ContainerStub::getLogger()) extends FindDriver {
                protected function exec(string $command): array
                {
                    return ['code' => 1, 'output' => '/unexpected/output'];
                }
            };
        } catch (InvalidArgumentException $caughtException) {
            $exception = $caughtException;
        }

        $this->assertSame(
            'The FindDriver requires the `find` executable.',
            $exception?->getMessage(),
        );
    }

    public function testBuildsEscapedDirectAndRecursiveFindCommands(): void
    {
        $driver = new ScriptedFindDriver(
            $this->option(),
            ContainerStub::getLogger(),
            [
                ['code' => 0, 'output' => ''],
                ['code' => 0, 'output' => ''],
            ],
        );
        $targets = [
            '/tmp/path with spaces',
            "/tmp/path'quoted",
            "/tmp/path\nwith-newline",
            '/tmp/$(ignored);touch nope',
        ];

        try {
            $driver->ensureReferenceFilesForTest();
            $driver->findForTest($targets, recursive: false, changed: true);
            $driver->findForTest($targets, recursive: true, changed: false);

            $arguments = implode(' ', array_map(escapeshellarg(...), $targets));
            $reference = escapeshellarg($driver->activeReferenceForTest());
            $this->assertSame(
                "find -H {$arguments} -maxdepth 1 -newer {$reference} -type f -print0",
                $driver->commands[1],
            );
            $this->assertSame(
                "find -H {$arguments} -type f -print0",
                $driver->commands[2],
            );
        } finally {
            $driver->removeReferenceFilesForTest();
        }
    }

    public function testParsesOnlyCompleteMatchingNulRecordsWithoutCorruptingNewlines(): void
    {
        $matching = base_path("app/file\nname.php");
        $unmatched = base_path('config/app.php');
        $unterminated = base_path('app/incomplete.php');
        $driver = new ScriptedFindDriver(new Option(watchPaths: [
            new WatchPath('app', WatchPathType::Directory, 'app/**/*.php'),
        ]), ContainerStub::getLogger());

        $files = $driver->matchingFilesForTest(
            $matching . "\0" . $unmatched . "\0" . $unterminated,
        );

        $this->assertSame([$matching => true], $files);
    }

    public function testPreservesParentSegmentsWhenMatchingASiblingPath(): void
    {
        $siblingBase = 'app/../sibling';
        $file = base_path($siblingBase . '/File.php');
        $driver = new ScriptedFindDriver(new Option(watchPaths: [
            new WatchPath($siblingBase, WatchPathType::Directory, $siblingBase . '/*.php'),
        ]), ContainerStub::getLogger());

        $this->assertSame([$file => true], $driver->matchingFilesForTest($file . "\0"));
    }

    public function testGroupsIdenticalTargetsOnceAndLetsRecursiveTraversalWin(): void
    {
        $option = new Option(watchPaths: [
            new WatchPath('.', WatchPathType::Directory, '.env*'),
            new WatchPath('.', WatchPathType::Directory),
            new WatchPath('composer.json', WatchPathType::File),
            new WatchPath('composer.json', WatchPathType::File),
        ]);
        $driver = new ScriptedFindDriver(
            $option,
            ContainerStub::getLogger(),
            array_fill(0, 4, ['code' => 0, 'output' => '']),
        );

        try {
            $driver->ensureReferenceFilesForTest();
            $driver->scanForTest();

            $commands = array_slice($driver->commands, 1);
            $this->assertCount(4, $commands);
            $this->assertStringContainsString(escapeshellarg(base_path('composer.json')), $commands[0]);
            $this->assertStringContainsString(' -maxdepth 1 ', $commands[0]);
            $this->assertStringContainsString(escapeshellarg(base_path()), $commands[2]);
            $this->assertStringNotContainsString(' -maxdepth 1 ', $commands[2]);
            $this->assertSame(1, substr_count($commands[0], escapeshellarg(base_path('composer.json'))));
            $this->assertSame(1, substr_count($commands[2], escapeshellarg(base_path())));
        } finally {
            $driver->removeReferenceFilesForTest();
        }
    }

    public function testMissingTargetIsACompleteEmptyInventoryAndIsDetectedWhenItAppears(): void
    {
        $path = base_path('late-watcher-target.php');
        @unlink($path);
        $driver = new ScriptedFindDriver(
            new Option(watchPaths: [new WatchPath('late-watcher-target.php', WatchPathType::File)]),
            ContainerStub::getLogger(),
            [
                ['code' => 0, 'output' => $path . "\0"],
                ['code' => 0, 'output' => $path . "\0"],
            ],
        );

        try {
            $driver->ensureReferenceFilesForTest();

            $this->assertSame([
                'files' => [],
                'changedComplete' => true,
                'inventoryComplete' => true,
                'failureCode' => null,
            ], $driver->scanForTest());
            $this->assertTrue($driver->hasCompleteInventoryForTest());
            $this->assertCount(1, $driver->commands);

            touch($path);

            $this->assertSame([
                'files' => [$path],
                'changedComplete' => true,
                'inventoryComplete' => true,
                'failureCode' => null,
            ], $driver->scanForTest());
            $this->assertCount(3, $driver->commands);
        } finally {
            @unlink($path);
            $driver->removeReferenceFilesForTest();
        }
    }

    public function testFirstCompleteInventoryEstablishesASilentBaseline(): void
    {
        $driver = new ScriptedFindDriver($this->option(), ContainerStub::getLogger());
        $existing = base_path('app/Existing.php');

        $changes = $driver->reconcileForTest([], $this->set($existing), complete: true);

        $this->assertSame([], $changes);
        $this->assertSame($this->set($existing), $driver->inventoryForTest());
        $this->assertTrue($driver->hasCompleteInventoryForTest());
    }

    public function testFirstCompleteInventoryPublishesOnlyFilesAlsoProvedChanged(): void
    {
        $driver = new ScriptedFindDriver($this->option(), ContainerStub::getLogger());
        $existing = base_path('app/Existing.php');
        $created = base_path('app/Created.php');

        $changes = $driver->reconcileForTest(
            $this->set($created),
            $this->set($existing, $created),
            complete: true,
        );

        $this->assertSame([$created], $changes);
        $this->assertSame($this->set($existing, $created), $driver->inventoryForTest());
    }

    public function testCompleteInventoryPublishesAdditionsDeletionsAndModificationsOnce(): void
    {
        $driver = new ScriptedFindDriver($this->option(), ContainerStub::getLogger());
        $unchanged = base_path('app/Unchanged.php');
        $modified = base_path('app/Modified.php');
        $deleted = base_path('app/Deleted.php');
        $added = base_path('app/Added.php');
        $driver->reconcileForTest([], $this->set($unchanged, $modified, $deleted), complete: true);

        $changes = $driver->reconcileForTest(
            $this->set($modified, $added),
            $this->set($unchanged, $modified, $added),
            complete: true,
        );

        $this->assertSame([$added, $deleted, $modified], $changes);
        $this->assertSame($this->set($unchanged, $modified, $added), $driver->inventoryForTest());
    }

    public function testRenameIsDetectedByInventoryWhenModificationTimeDoesNotChange(): void
    {
        $directory = $this->tempDir . '/rename';
        $oldPath = $directory . '/old.php';
        $newPath = $directory . '/new.php';
        mkdir($directory);
        file_put_contents($oldPath, 'contents');
        $driver = new RawOutputFindDriver($this->option(), ContainerStub::getLogger());

        try {
            $driver->ensureReferenceFilesForTest();
            [$initialInventory, $initialExitCode] = $driver->findForTest(
                [$directory],
                recursive: true,
                changed: false,
            );

            $this->assertSame(0, $initialExitCode);
            $this->assertSame([], $driver->reconcileForTest([], $initialInventory, complete: true));

            rename($oldPath, $newPath);

            [$changedFiles, $changedExitCode] = $driver->findForTest(
                [$directory],
                recursive: true,
                changed: true,
            );
            [$currentInventory, $inventoryExitCode] = $driver->findForTest(
                [$directory],
                recursive: true,
                changed: false,
            );

            $this->assertSame(0, $changedExitCode);
            $this->assertSame([], $changedFiles);
            $this->assertSame(0, $inventoryExitCode);
            $this->assertEqualsCanonicalizing(
                [$oldPath, $newPath],
                $driver->reconcileForTest($changedFiles, $currentInventory, complete: true),
            );
        } finally {
            $driver->removeReferenceFilesForTest();
        }
    }

    public function testChangedPathDeletedBeforeInventoryIsReportedOnlyAsADeletion(): void
    {
        $driver = new ScriptedFindDriver($this->option(), ContainerStub::getLogger());
        $path = base_path('app/ChangedThenDeleted.php');
        $driver->reconcileForTest([], $this->set($path), complete: true);

        $this->assertSame(
            [$path],
            $driver->reconcileForTest($this->set($path), [], complete: true),
        );
    }

    public function testCreationBetweenChangedAndInventoryPassesRemainsEligibleNextCycle(): void
    {
        $driver = new ScriptedFindDriver($this->option(), ContainerStub::getLogger());
        $path = base_path('app/CreatedBetweenPasses.php');
        $driver->reconcileForTest([], [], complete: true);

        $this->assertSame(
            [$path],
            $driver->reconcileForTest([], $this->set($path), complete: true),
        );
        $this->assertSame(
            [$path],
            $driver->reconcileForTest($this->set($path), $this->set($path), complete: true),
        );
    }

    public function testIncompleteInventoryPublishesChangedFilesButNeverInventsDeletions(): void
    {
        $driver = new ScriptedFindDriver($this->option(), ContainerStub::getLogger());
        $existing = base_path('app/Existing.php');
        $changed = base_path('app/Changed.php');
        $driver->reconcileForTest([], $this->set($existing), complete: true);

        $changes = $driver->reconcileForTest(
            $this->set($changed),
            [],
            complete: false,
        );

        $this->assertSame([$changed], $changes);
        $this->assertSame($this->set($existing, $changed), $driver->inventoryForTest());
    }

    public function testRecoveryAfterFailedFirstInventoryAvoidsABaselineFloodAndKeepsRealDeletion(): void
    {
        $driver = new ScriptedFindDriver($this->option(), ContainerStub::getLogger());
        $changed = base_path('app/Changed.php');
        $untouched = base_path('app/Untouched.php');

        $this->assertSame(
            [$changed],
            $driver->reconcileForTest($this->set($changed), [], complete: false),
        );
        $this->assertFalse($driver->hasCompleteInventoryForTest());

        $this->assertSame(
            [$changed],
            $driver->reconcileForTest([], $this->set($untouched), complete: true),
        );
        $this->assertSame($this->set($untouched), $driver->inventoryForTest());
    }

    public function testEmptyCompleteInventoryDeletesRetainedPathsAndBoundsState(): void
    {
        $driver = new ScriptedFindDriver($this->option(), ContainerStub::getLogger());

        for ($index = 0; $index < 100; ++$index) {
            $path = base_path("app/{$index}.php");
            $driver->reconcileForTest($this->set($path), $this->set($path), complete: true);
            $this->assertSame([$path], $driver->reconcileForTest([], [], complete: true));
        }

        $this->assertSame([], $driver->inventoryForTest());
    }

    public function testChangedTraversalFailurePublishesCompleteRecordsAndHoldsTheCutoff(): void
    {
        $path = base_path('composer.json');
        $logger = m::mock(StdoutLoggerInterface::class);
        $logger->shouldReceive('warning')
            ->once()
            ->with(
                'One or more find commands exited with code 1. '
                . 'Detected changes may repeat until the filesystem error is fixed.',
            );
        $driver = new SingleCycleFindDriver(
            new Option(watchPaths: [new WatchPath('composer.json', WatchPathType::File)]),
            $logger,
            [
                ['code' => 1, 'output' => $path . "\0" . base_path('unterminated.php')],
                ['code' => 0, 'output' => $path . "\0"],
            ],
        );
        $channel = new Channel(1);

        try {
            $driver->watch($channel);

            $this->assertSame($path, $channel->pop(0.1));
            $this->assertSame(0, $driver->rotationCount);
            $this->assertSame([], $driver->referenceFilesForTest());
        } finally {
            $driver->stop();
            $channel->close();
        }
    }

    public function testInventoryFailureSuspendsDeletionsButAdvancesACompleteChangedCutoff(): void
    {
        $path = base_path('composer.json');
        $logger = m::mock(StdoutLoggerInterface::class);
        $logger->shouldReceive('warning')
            ->once()
            ->with(
                'One or more find commands exited with code 2. '
                . 'Deletion detection is suspended until the filesystem error is fixed.',
            );
        $driver = new SingleCycleFindDriver(
            new Option(watchPaths: [new WatchPath('composer.json', WatchPathType::File)]),
            $logger,
            [
                ['code' => 0, 'output' => $path . "\0"],
                ['code' => 2, 'output' => ''],
            ],
        );
        $channel = new Channel(1);

        try {
            $driver->watch($channel);

            $this->assertSame($path, $channel->pop(0.1));
            $this->assertSame(1, $driver->rotationCount);
        } finally {
            $driver->stop();
            $channel->close();
        }
    }

    public function testMultipleTraversalFailuresLogTheFirstCodeAndBothAffectedGuaranteesOnce(): void
    {
        $logger = m::mock(StdoutLoggerInterface::class);
        $logger->shouldReceive('warning')
            ->once()
            ->with(
                'One or more find commands exited with code 2. '
                . 'Detected changes may repeat until the filesystem error is fixed. '
                . 'Deletion detection is suspended until the filesystem error is fixed.',
            );
        $driver = new SingleCycleFindDriver(
            new Option(watchPaths: [
                new WatchPath('composer.json', WatchPathType::File),
                new WatchPath('.', WatchPathType::Directory),
            ]),
            $logger,
            [
                ['code' => 2, 'output' => ''],
                ['code' => 3, 'output' => ''],
                ['code' => 4, 'output' => ''],
                ['code' => 5, 'output' => ''],
            ],
        );
        $channel = new Channel(1);

        try {
            $driver->watch($channel);

            $this->assertSame(0, $driver->rotationCount);
        } finally {
            $driver->stop();
            $channel->close();
        }
    }

    public function testReferenceFilesAreCreatedForTheLifecycleAndUniquePerDriver(): void
    {
        $first = new ScriptedFindDriver($this->option(), ContainerStub::getLogger());
        $second = new ScriptedFindDriver($this->option(), ContainerStub::getLogger());

        $this->assertSame([], $first->referenceFilesForTest());
        $this->assertSame([], $second->referenceFilesForTest());

        try {
            $first->ensureReferenceFilesForTest();
            $second->ensureReferenceFilesForTest();
            $files = [...$first->referenceFilesForTest(), ...$second->referenceFilesForTest()];

            $this->assertCount(4, array_unique($files));
            foreach ($files as $file) {
                $this->assertFileExists($file);
            }
        } finally {
            $first->removeReferenceFilesForTest();
            $second->removeReferenceFilesForTest();
        }
    }

    public function testStoppedDriverDoesNotCreateAnotherReferenceLifecycle(): void
    {
        $driver = new ScriptedFindDriver($this->option(), ContainerStub::getLogger());
        $channel = new Channel(1);

        try {
            $driver->stop();
            $driver->stop();
            $driver->watch($channel);

            $this->assertSame([], $driver->referenceFilesForTest());
            $this->assertSame(['command -v find'], $driver->commands);
        } finally {
            $channel->close();
        }
    }

    public function testSecondReferenceCreationFailureRemovesTheFirstFile(): void
    {
        $createdFiles = [];
        $driver = new class($this->option(), ContainerStub::getLogger(), $createdFiles) extends FindDriver {
            private int $creationCount = 0;

            /** @var list<string> */
            private array $createdFiles;

            public function __construct(
                Option $option,
                StdoutLoggerInterface $logger,
                array &$createdFiles,
            ) {
                $this->createdFiles = &$createdFiles;

                parent::__construct($option, $logger);
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

            public function ensureReferenceFilesForTest(): void
            {
                $this->ensureReferenceFiles();
            }

            public function referenceFilesForTest(): array
            {
                return $this->referenceFiles;
            }
        };
        $exception = null;

        try {
            $driver->ensureReferenceFilesForTest();
        } catch (RuntimeException $caughtException) {
            $exception = $caughtException;
        }

        $this->assertSame('Unable to create a watcher reference file.', $exception?->getMessage());
        $this->assertCount(1, $createdFiles);
        $this->assertFileDoesNotExist($createdFiles[0]);
        $this->assertSame([], $driver->referenceFilesForTest());
    }

    public function testTouchFailureTerminatesTheLifecycleAndCleansReferences(): void
    {
        $driver = new class($this->option(), ContainerStub::getLogger()) extends FindDriver {
            /** @var list<string> */
            public array $createdFiles = [];

            protected function exec(string $command): array
            {
                return ['code' => 0, 'output' => '/usr/bin/find'];
            }

            protected function createReferenceFile(): string
            {
                $path = parent::createReferenceFile();
                $this->createdFiles[] = $path;

                return $path;
            }

            protected function updateReferenceFile(string $path): void
            {
                throw new RuntimeException('touch failed');
            }

            public function referenceFilesForTest(): array
            {
                return $this->referenceFiles;
            }
        };
        $exception = null;
        $channel = new Channel(1);

        try {
            $driver->watch($channel);
        } catch (RuntimeException $caughtException) {
            $exception = $caughtException;
        } finally {
            $channel->close();
        }

        $this->assertSame('touch failed', $exception?->getMessage());
        $this->assertSame([], $driver->referenceFilesForTest());
        foreach ($driver->createdFiles as $file) {
            $this->assertFileDoesNotExist($file);
        }
    }

    public function testScanExceptionTerminatesTheLifecycleAndCleansReferences(): void
    {
        $driver = new class($this->option(), ContainerStub::getLogger()) extends FindDriver {
            /** @var list<string> */
            public array $createdFiles = [];

            protected function exec(string $command): array
            {
                return ['code' => 0, 'output' => '/usr/bin/find'];
            }

            protected function createReferenceFile(): string
            {
                $path = parent::createReferenceFile();
                $this->createdFiles[] = $path;

                return $path;
            }

            protected function scan(): array
            {
                throw new RuntimeException('scan failed');
            }

            public function referenceFilesForTest(): array
            {
                return $this->referenceFiles;
            }
        };
        $exception = null;
        $channel = new Channel(1);

        try {
            $driver->watch($channel);
        } catch (RuntimeException $caughtException) {
            $exception = $caughtException;
        } finally {
            $channel->close();
        }

        $this->assertSame('scan failed', $exception?->getMessage());
        $this->assertSame([], $driver->referenceFilesForTest());
        foreach ($driver->createdFiles as $file) {
            $this->assertFileDoesNotExist($file);
        }
    }

    public function testStopDuringReferenceUpdateSkipsTheScanAndCleansAfterOwnershipReturns(): void
    {
        $entered = new Channel(1);
        $resume = new Channel(1);
        $output = new Channel(1);
        $driver = new class($this->option(), ContainerStub::getLogger(), $entered, $resume) extends FindDriver {
            public bool $scanCalled = false;

            /** @var list<string> */
            public array $filesDuringUpdate = [];

            public function __construct(
                Option $option,
                StdoutLoggerInterface $logger,
                private Channel $entered,
                private Channel $resume,
            ) {
                parent::__construct($option, $logger);
            }

            protected function exec(string $command): array
            {
                return ['code' => 0, 'output' => '/usr/bin/find'];
            }

            protected function updateReferenceFile(string $path): void
            {
                $this->filesDuringUpdate = $this->referenceFiles;
                $this->entered->push(true);
                $this->resume->pop();
            }

            protected function scan(): array
            {
                $this->scanCalled = true;

                return [
                    'files' => [],
                    'changedComplete' => true,
                    'inventoryComplete' => true,
                    'failureCode' => null,
                ];
            }

            public function referenceFilesForTest(): array
            {
                return $this->referenceFiles;
            }
        };
        $finished = new WaitGroup(1);

        Coroutine::create(function () use ($driver, $finished, $output): void {
            try {
                $driver->watch($output);
            } finally {
                $finished->done();
            }
        });

        try {
            $this->assertTrue($entered->pop(0.1));
            $driver->stop();

            foreach ($driver->filesDuringUpdate as $file) {
                $this->assertFileExists($file);
            }

            $resume->push(true);
            $this->assertTrue($finished->wait(0.1));
            $this->assertFalse($driver->scanCalled);
            $this->assertSame([], $driver->referenceFilesForTest());
        } finally {
            $driver->stop();
            $entered->close();
            $resume->close();
            $output->close();
        }
    }

    public function testStopDuringScanPublishesNothingDoesNotRotateAndCleansAfterTheCommandReturns(): void
    {
        $entered = new Channel(1);
        $resume = new Channel(1);
        $output = new Channel(1);
        $driver = new class($this->option(), ContainerStub::getLogger(), $entered, $resume) extends FindDriver {
            public int $rotationCount = 0;

            /** @var list<string> */
            public array $filesDuringScan = [];

            public function __construct(
                Option $option,
                StdoutLoggerInterface $logger,
                private Channel $entered,
                private Channel $resume,
            ) {
                parent::__construct($option, $logger);
            }

            protected function exec(string $command): array
            {
                return ['code' => 0, 'output' => '/usr/bin/find'];
            }

            protected function scan(): array
            {
                $this->filesDuringScan = $this->referenceFiles;
                $this->entered->push(true);
                $this->resume->pop();

                return [
                    'files' => ['/tmp/changed.php'],
                    'changedComplete' => true,
                    'inventoryComplete' => true,
                    'failureCode' => null,
                ];
            }

            protected function rotateReferenceFiles(): void
            {
                ++$this->rotationCount;
                parent::rotateReferenceFiles();
            }

            public function referenceFilesForTest(): array
            {
                return $this->referenceFiles;
            }
        };
        $finished = new WaitGroup(1);

        Coroutine::create(function () use ($driver, $finished, $output): void {
            try {
                $driver->watch($output);
            } finally {
                $finished->done();
            }
        });

        try {
            $this->assertTrue($entered->pop(0.1));
            $driver->stop();

            foreach ($driver->filesDuringScan as $file) {
                $this->assertFileExists($file);
            }

            $resume->push(true);
            $this->assertTrue($finished->wait(0.1));
            $this->assertFalse($output->pop(0.01));
            $this->assertSame(0, $driver->rotationCount);
            $this->assertSame([], $driver->referenceFilesForTest());
        } finally {
            $driver->stop();
            $entered->close();
            $resume->close();
            $output->close();
        }
    }

    public function testCutoffRecordedBeforeASlowScanKeepsLateChangesEligible(): void
    {
        $completed = new Channel(1);
        $output = new Channel(1);
        $driver = new class(new Option(scanInterval: 1), ContainerStub::getLogger(), $completed) extends FindDriver {
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

            public function __construct(
                Option $option,
                StdoutLoggerInterface $logger,
                private Channel $completed,
            ) {
                parent::__construct($option, $logger);
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
                $reference = $this->activeReferenceFile();
                $this->scanReferences[] = $reference;

                if (++$this->scanCount === 1) {
                    $this->lateChangeAt = ++$this->clock;

                    return [
                        'files' => [],
                        'changedComplete' => true,
                        'inventoryComplete' => true,
                        'failureCode' => null,
                    ];
                }

                $this->lateChangeEligible = $this->lateChangeAt > $this->cutoffs[$reference];
                $this->completed->push(true);
                $this->stop();

                return [
                    'files' => [],
                    'changedComplete' => true,
                    'inventoryComplete' => true,
                    'failureCode' => null,
                ];
            }
        };

        try {
            $driver->watch($output);

            $this->assertTrue($completed->pop(0.1));
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

    public function testFindFollowsASymlinkOperandButNotSymlinksFoundDuringDescent(): void
    {
        $realDirectory = $this->tempDir . '/real';
        $externalDirectory = $this->tempDir . '/external';
        $linkDirectory = $this->tempDir . '/root-link';
        mkdir($realDirectory);
        mkdir($externalDirectory);
        touch($realDirectory . '/direct.php');
        touch($externalDirectory . '/nested.php');
        symlink($externalDirectory, $realDirectory . '/nested-link');
        symlink($realDirectory, $linkDirectory);
        $driver = new RawOutputFindDriver($this->option(), ContainerStub::getLogger());

        try {
            $driver->ensureReferenceFilesForTest();
            [$files, $exitCode] = $driver->findForTest([$linkDirectory], recursive: true, changed: false);

            $this->assertSame(0, $exitCode);
            $this->assertArrayHasKey($linkDirectory . '/direct.php', $files);
            $this->assertArrayNotHasKey($linkDirectory . '/nested-link/nested.php', $files);
        } finally {
            $driver->removeReferenceFilesForTest();
        }
    }

    /**
     * Create standard find options.
     */
    private function option(): Option
    {
        return new Option(driver: FindDriver::class, scanInterval: 1);
    }

    /**
     * Create a string-keyed set.
     *
     * @return array<string, true>
     */
    private function set(string ...$paths): array
    {
        return array_fill_keys($paths, true);
    }
}

class ScriptedFindDriver extends FindDriver
{
    /** @var list<array{code: int, output: string}> */
    protected array $results;

    /** @var list<string> */
    public array $commands = [];

    public int $rotationCount = 0;

    /**
     * @param list<array{code: int, output: string}> $results
     */
    public function __construct(
        Option $option,
        StdoutLoggerInterface $logger,
        array $results = [],
    ) {
        $this->results = $results;

        parent::__construct($option, $logger);
    }

    protected function exec(string $command): array
    {
        $this->commands[] = $command;

        if ($command === 'command -v find') {
            return ['code' => 0, 'output' => '/usr/bin/find'];
        }

        return array_shift($this->results) ?? ['code' => 0, 'output' => ''];
    }

    protected function rotateReferenceFiles(): void
    {
        ++$this->rotationCount;
        parent::rotateReferenceFiles();
    }

    public function ensureReferenceFilesForTest(): void
    {
        $this->ensureReferenceFiles();
    }

    public function removeReferenceFilesForTest(): void
    {
        $this->removeReferenceFiles();
    }

    public function referenceFilesForTest(): array
    {
        return $this->referenceFiles;
    }

    public function activeReferenceForTest(): string
    {
        return $this->activeReferenceFile();
    }

    public function findForTest(array $targets, bool $recursive, bool $changed): array
    {
        return $this->find($targets, $recursive, $changed);
    }

    public function matchingFilesForTest(string $output): array
    {
        return $this->matchingFiles($output);
    }

    public function scanForTest(): array
    {
        return $this->scan();
    }

    public function reconcileForTest(array $changedFiles, array $inventory, bool $complete): array
    {
        return $this->reconcileInventory($changedFiles, $inventory, $complete);
    }

    public function inventoryForTest(): array
    {
        return $this->inventory;
    }

    public function hasCompleteInventoryForTest(): bool
    {
        return $this->hasCompleteInventory;
    }
}

class SingleCycleFindDriver extends ScriptedFindDriver
{
    protected function watchAtInterval(float $seconds, callable $scan): void
    {
        $scan();
    }
}

class RawOutputFindDriver extends FindDriver
{
    protected function matchingFiles(string $output): array
    {
        $files = [];
        $offset = 0;

        while (($separator = strpos($output, "\0", $offset)) !== false) {
            $file = substr($output, $offset, $separator - $offset);
            $offset = $separator + 1;

            if ($file !== '') {
                $files[$file] = true;
            }
        }

        return $files;
    }

    public function ensureReferenceFilesForTest(): void
    {
        $this->ensureReferenceFiles();
    }

    public function removeReferenceFilesForTest(): void
    {
        $this->removeReferenceFiles();
    }

    public function findForTest(array $targets, bool $recursive, bool $changed): array
    {
        return $this->find($targets, $recursive, $changed);
    }

    public function reconcileForTest(array $changedFiles, array $inventory, bool $complete): array
    {
        return $this->reconcileInventory($changedFiles, $inventory, $complete);
    }
}
