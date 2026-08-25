<?php

declare(strict_types=1);

namespace Hypervel\Tests\Watcher\Driver;

use Hypervel\Coroutine\WaitGroup;
use Hypervel\Engine\Channel;
use Hypervel\Engine\Coroutine;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Testbench\TestCase;
use Hypervel\Watcher\Driver\FswatchDriver;
use Hypervel\Watcher\Option;
use Hypervel\Watcher\WatchPath;
use Hypervel\Watcher\WatchPathType;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

class FswatchDriverTest extends TestCase
{
    protected Filesystem $files;

    protected string $fixturePath;

    protected string $fixtureRelativePath = 'fswatch-driver-test';

    protected function setUp(): void
    {
        parent::setUp();

        $this->files = new Filesystem;
        $this->fixturePath = base_path($this->fixtureRelativePath);
        $this->files->deleteDirectory($this->fixturePath);
        $this->files->makeDirectory($this->fixturePath);
    }

    protected function tearDown(): void
    {
        $this->files->deleteDirectory($this->fixturePath);

        parent::tearDown();
    }

    public function testConstructorProbesFswatchWithTheShellBuiltin(): void
    {
        $driver = new InspectableFswatchDriver($this->option());

        $this->assertSame(['command -v fswatch'], $driver->executedCommands);
    }

    public function testConstructorUsesTheProbeExitCode(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The FswatchDriver requires the `fswatch` executable.');

        new InspectableFswatchDriver(
            $this->option(),
            probeResult: ['code' => 1, 'output' => '/stale/fswatch'],
        );
    }

    public function testStopTerminatesProcessButLeavesResourceClosureToTheWatchOwner(): void
    {
        $driver = new class($this->option()) extends FswatchDriver {
            protected function exec(string $command): array
            {
                return ['code' => 0, 'output' => '/usr/bin/fswatch'];
            }

            public function setResources(mixed $process, array $pipes): void
            {
                $this->processes = ['shallow' => $process];
                $this->pipes = ['shallow' => $pipes[1]];
            }

            public function closeResources(): void
            {
                $this->closeProcesses();
            }
        };

        $process = proc_open(['sleep', '60'], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        $this->assertTrue(is_resource($process));

        $pid = proc_get_status($process)['pid'];
        $this->assertTrue(posix_kill($pid, 0), 'Process should be running before stop()');

        $driver->setResources($process, $pipes);
        $driver->stop();
        $driver->stop();

        $this->assertTrue(is_resource($process), 'The watch owner should retain the process handle until cleanup.');
        $this->assertTrue(is_resource($pipes[1]), 'The stopper must not close a pipe selected by the watch owner.');

        fclose($pipes[0]);
        fclose($pipes[2]);
        $driver->closeResources();

        $this->assertFalse(is_resource($process), 'The watch owner should close the process handle.');
        $this->assertFalse(is_resource($pipes[1]), 'The watch owner should close the output pipe.');
        $this->assertFalse(posix_kill($pid, 0), 'The direct child should not be running after cleanup.');
    }

    public function testWatchFailsWhenTheFswatchProcessExits(): void
    {
        $option = $this->option();
        $driver = new class($option) extends FswatchDriver {
            protected function exec(string $command): array
            {
                return ['code' => 0, 'output' => '/usr/bin/fswatch'];
            }

            protected function getCommand(array $operands = [], bool $recursive = false): array
            {
                return [PHP_BINARY, '-r', ''];
            }

            public function resourcesAreClosed(): bool
            {
                return $this->processes === [] && $this->pipes === [];
            }
        };
        $channel = new Channel(1);

        try {
            $driver->watch($channel);
            $this->fail('Expected the exited fswatch process to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('The fswatch process exited unexpectedly.', $exception->getMessage());
        } finally {
            $driver->stop();
            $driver->stop();
            $channel->close();
        }

        $this->assertTrue($driver->resourcesAreClosed());
    }

    #[DataProvider('readFailureProvider')]
    public function testWatchRejectsPipeReadFailures(false|string $readResult, bool $eof): void
    {
        $state = new FswatchDriverStreamState($readResult, $eof);
        $driver = new FswatchDriverReadFailureStub($this->option(), $state);
        $channel = new Channel(1);

        try {
            $driver->watch($channel);
            $this->fail('Expected the fswatch pipe read to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Unable to read output from the fswatch process.', $exception->getMessage());
        } finally {
            $driver->stop();
            $channel->close();
        }

        $this->assertSame(1, $state->readCount);
        $this->assertSame(1, $state->closeCount);
        $this->assertTrue($driver->resourcesAreClosed());
    }

    public static function readFailureProvider(): array
    {
        return [
            'read failure' => [false, false],
            'empty read before EOF' => ['', false],
        ];
    }

    public function testExplicitStopReturnsCleanlyBeforeTheChannelCloses(): void
    {
        $option = $this->option();
        $driver = new class($option) extends FswatchDriver {
            protected function exec(string $command): array
            {
                return ['code' => 0, 'output' => '/usr/bin/fswatch'];
            }

            protected function getCommand(array $operands = [], bool $recursive = false): array
            {
                return ['sleep', '60'];
            }

            public function resourcesAreClosed(): bool
            {
                return $this->processes === [] && $this->pipes === [];
            }
        };
        $channel = new Channel(1);

        Coroutine::create(function () use ($driver): void {
            usleep(10_000);
            $driver->stop();
        });

        try {
            $driver->watch($channel);
            $this->assertFalse($channel->isClosing());
            $this->assertTrue($driver->resourcesAreClosed());
        } finally {
            $driver->stop();
            $channel->close();
        }
    }

    public function testChannelClosureReturnsCleanlyWhenTheReadIsUnblocked(): void
    {
        $option = $this->option();
        $driver = new class($option) extends FswatchDriver {
            protected function exec(string $command): array
            {
                return ['code' => 0, 'output' => '/usr/bin/fswatch'];
            }

            protected function getCommand(array $operands = [], bool $recursive = false): array
            {
                return ['sleep', '60'];
            }

            public function terminateProcess(): void
            {
                foreach ($this->processes as $process) {
                    if (is_resource($process)) {
                        proc_terminate($process, SIGKILL);
                    }
                }
            }

            public function resourcesAreClosed(): bool
            {
                return $this->processes === [] && $this->pipes === [];
            }
        };
        $channel = new Channel(1);

        Coroutine::create(function () use ($channel, $driver): void {
            usleep(10_000);
            $channel->close();
            $driver->terminateProcess();
        });

        try {
            $driver->watch($channel);
            $this->assertTrue($channel->isClosing());
            $this->assertTrue($driver->resourcesAreClosed());
        } finally {
            $driver->stop();
        }
    }

    public function testCommandPreservesWatchPathsAsLiteralArguments(): void
    {
        $paths = ['path with spaces', "path'quoted", '$(ignored);touch nope'];
        $option = new Option(
            driver: FswatchDriver::class,
            watchPaths: array_map(
                fn (string $path): WatchPath => new WatchPath($path, WatchPathType::Directory),
                $paths,
            ),
        );
        $driver = new InspectableFswatchDriver($option);
        $resolvedPaths = array_map(base_path(...), $paths);

        $expected = $driver->isDarwin()
            ? [
                'fswatch',
                '-0',
                '--format',
                '%p',
                '-r',
                '--event',
                'Created',
                '--event',
                'Updated',
                '--event',
                'Removed',
                '--event',
                'Renamed',
                ...$resolvedPaths,
            ]
            : [
                'fswatch',
                '-m',
                'inotify_monitor',
                '-0',
                '--format',
                '%p',
                '-r',
                '--event',
                'Created',
                '--event',
                'Updated',
                '--event',
                'Removed',
                '--event',
                'Renamed',
                ...$resolvedPaths,
            ];

        $this->assertSame($expected, $driver->commandForTest());
    }

    public function testTargetsNormalizeRootAndTrailingSlashes(): void
    {
        $watchPaths = [
            new WatchPath('.', WatchPathType::Directory),
            new WatchPath('app/', WatchPathType::Directory),
        ];
        $driver = new InspectableFswatchDriver(new Option(driver: FswatchDriver::class, watchPaths: $watchPaths));

        $this->assertSame(
            [base_path(), base_path('app')],
            $driver->operandsForTest(),
        );
    }

    #[DataProvider('commandProvider')]
    public function testCommandUsesTheSameNulProtocolAndEventFiltersOnEveryPlatform(
        bool $darwin,
        bool $recursive,
        array $platformArguments,
    ): void {
        $watchPath = $recursive
            ? new WatchPath($this->fixtureRelativePath, WatchPathType::Directory)
            : new WatchPath(
                $this->fixtureRelativePath,
                WatchPathType::Directory,
                $this->fixtureRelativePath . '/*.php',
            );
        $driver = new InspectableFswatchDriver(
            new Option(driver: FswatchDriver::class, watchPaths: [$watchPath]),
            darwin: $darwin,
        );

        $expected = [
            'fswatch',
            ...$platformArguments,
            '-0',
            '--format',
            '%p',
            ...($recursive ? ['-r'] : []),
            '--event',
            'Created',
            '--event',
            'Updated',
            '--event',
            'Removed',
            '--event',
            'Renamed',
            $this->fixturePath,
        ];

        $this->assertSame($expected, $driver->commandForTest());
    }

    public static function commandProvider(): array
    {
        return [
            'Linux shallow' => [false, false, ['-m', 'inotify_monitor']],
            'Linux recursive' => [false, true, ['-m', 'inotify_monitor']],
            'Darwin shallow' => [true, false, []],
            'Darwin recursive' => [true, true, []],
        ];
    }

    public function testLinuxSeparatesShallowAndRecursiveOperands(): void
    {
        $this->files->makeDirectory($this->fixturePath . '/app');
        $this->files->put($this->fixturePath . '/.env', 'APP_ENV=local');
        $appBase = $this->fixtureRelativePath . '/app';
        $envPath = $this->fixtureRelativePath . '/.env';
        $watchPaths = [
            new WatchPath($appBase, WatchPathType::Directory, $appBase . '/**/*.php'),
            new WatchPath($envPath, WatchPathType::File),
        ];
        $driver = new InspectableFswatchDriver(
            new Option(driver: FswatchDriver::class, watchPaths: $watchPaths),
            darwin: false,
        );

        $this->assertSame([
            'shallow' => [
                'recursive' => false,
                'operands' => [$this->fixturePath],
            ],
            'recursive' => [
                'recursive' => true,
                'operands' => [$this->fixturePath . '/app'],
            ],
        ], $driver->targetsForTest()['groups']);
    }

    public function testSharedOperandPromotesToRecursive(): void
    {
        $this->files->makeDirectory($this->fixturePath . '/app');
        $appBase = $this->fixtureRelativePath . '/app';
        $watchPaths = [
            new WatchPath($appBase, WatchPathType::Directory, $appBase . '/*.php'),
            new WatchPath($appBase, WatchPathType::Directory, $appBase . '/**/*.js'),
        ];
        $driver = new InspectableFswatchDriver(
            new Option(driver: FswatchDriver::class, watchPaths: $watchPaths),
            darwin: false,
        );

        $this->assertSame([
            'recursive' => [
                'recursive' => true,
                'operands' => [$this->fixturePath . '/app'],
            ],
        ], $driver->targetsForTest()['groups']);
    }

    public function testContainmentOnlyRemovesShallowOperandsFromTheRecursiveGroup(): void
    {
        $this->files->makeDirectory($this->fixturePath . '/app/Http', 0755, true);
        $appBase = $this->fixtureRelativePath . '/app';
        $httpBase = $appBase . '/Http';
        $watchPaths = [
            new WatchPath($appBase, WatchPathType::Directory, $appBase . '/**/*.php'),
            new WatchPath($httpBase, WatchPathType::Directory, $httpBase . '/*.js'),
        ];
        $driver = new InspectableFswatchDriver(
            new Option(driver: FswatchDriver::class, watchPaths: $watchPaths),
            darwin: false,
        );
        $targets = $driver->targetsForTest();
        $channel = new Channel(1);
        $file = $this->fixturePath . '/app/Http/Controller.js';

        try {
            $driver->processChunksWithTargets($channel, [$file . "\0"], $targets);

            $this->assertSame([
                'recursive' => [
                    'recursive' => true,
                    'operands' => [$this->fixturePath . '/app'],
                ],
            ], $targets['groups']);
            $this->assertSame(2, count($targets['entries']));
            $this->assertSame($file, $channel->pop());
            $this->assertSame(0, $channel->getLength());
        } finally {
            $driver->stop();
            $channel->close();
        }
    }

    public function testShallowSymlinkOutsideRecursiveTreeRetainsItsOperandAndPublishes(): void
    {
        $this->files->makeDirectory($this->fixturePath . '/app');
        $this->files->makeDirectory($this->fixturePath . '/outside');
        symlink($this->fixturePath . '/outside', $this->fixturePath . '/app/link');
        $appBase = $this->fixtureRelativePath . '/app';
        $linkBase = $appBase . '/link';
        $watchPaths = [
            new WatchPath($appBase, WatchPathType::Directory, $appBase . '/**/*.php'),
            new WatchPath($linkBase, WatchPathType::Directory, $linkBase . '/*.js'),
        ];
        $driver = new InspectableFswatchDriver(
            new Option(driver: FswatchDriver::class, watchPaths: $watchPaths),
            darwin: false,
        );
        $targets = $driver->targetsForTest();
        $channel = new Channel(1);
        $file = $this->fixturePath . '/outside/Foo.js';

        try {
            $driver->processChunksWithTargets($channel, [$file . "\0"], $targets);

            $this->assertSame([
                'shallow' => [
                    'recursive' => false,
                    'operands' => [$this->fixturePath . '/outside'],
                ],
                'recursive' => [
                    'recursive' => true,
                    'operands' => [$this->fixturePath . '/app'],
                ],
            ], $targets['groups']);
            $this->assertSame($file, $channel->pop());
        } finally {
            $driver->stop();
            $channel->close();
        }
    }

    public function testShallowSymlinkInsideRecursiveTreeDropsItsOperandButKeepsItsMatcher(): void
    {
        $this->files->makeDirectory($this->fixturePath . '/app/real', 0755, true);
        symlink($this->fixturePath . '/app/real', $this->fixturePath . '/app/link');
        $appBase = $this->fixtureRelativePath . '/app';
        $linkBase = $appBase . '/link';
        $watchPaths = [
            new WatchPath($appBase, WatchPathType::Directory, $appBase . '/**/*.php'),
            new WatchPath($linkBase, WatchPathType::Directory, $linkBase . '/*.js'),
        ];
        $driver = new InspectableFswatchDriver(
            new Option(driver: FswatchDriver::class, watchPaths: $watchPaths),
            darwin: false,
        );
        $targets = $driver->targetsForTest();
        $channel = new Channel(1);
        $file = $this->fixturePath . '/app/real/Foo.js';

        try {
            $driver->processChunksWithTargets($channel, [$file . "\0"], $targets);

            $this->assertSame([
                'recursive' => [
                    'recursive' => true,
                    'operands' => [$this->fixturePath . '/app'],
                ],
            ], $targets['groups']);
            $this->assertSame(2, count($targets['entries']));
            $this->assertSame($file, $channel->pop());
            $this->assertSame(0, $channel->getLength());
        } finally {
            $driver->stop();
            $channel->close();
        }
    }

    public function testNestedOperandsWithinOneGroupAreRetained(): void
    {
        $this->files->makeDirectory($this->fixturePath . '/app/Http', 0755, true);
        $appBase = $this->fixtureRelativePath . '/app';
        $httpBase = $appBase . '/Http';

        $recursiveDriver = new InspectableFswatchDriver(
            new Option(driver: FswatchDriver::class, watchPaths: [
                new WatchPath($appBase, WatchPathType::Directory, $appBase . '/**/*.php'),
                new WatchPath($httpBase, WatchPathType::Directory, $httpBase . '/**/*.js'),
            ]),
            darwin: false,
        );
        $shallowDriver = new InspectableFswatchDriver(
            new Option(driver: FswatchDriver::class, watchPaths: [
                new WatchPath($appBase, WatchPathType::Directory, $appBase . '/*.php'),
                new WatchPath($httpBase, WatchPathType::Directory, $httpBase . '/*.js'),
            ]),
            darwin: false,
        );

        $this->assertSame(
            [$this->fixturePath . '/app', $this->fixturePath . '/app/Http'],
            $recursiveDriver->targetsForTest()['groups']['recursive']['operands'],
        );
        $this->assertSame(
            [$this->fixturePath . '/app', $this->fixturePath . '/app/Http'],
            $shallowDriver->targetsForTest()['groups']['shallow']['operands'],
        );
    }

    public function testSiblingPathWithParentSegmentsRemainsASeparateOperand(): void
    {
        $siblingBase = '../packages/foo';
        $watchPaths = [
            new WatchPath('.', WatchPathType::Directory),
            new WatchPath($siblingBase, WatchPathType::Directory, $siblingBase . '/*.php'),
        ];
        $driver = new InspectableFswatchDriver(
            new Option(driver: FswatchDriver::class, watchPaths: $watchPaths),
            darwin: false,
        );
        $targets = $driver->targetsForTest();
        $channel = new Channel(1);
        $file = base_path('../packages/foo/Package.php');

        try {
            $driver->processChunksWithTargets($channel, [$file . "\0"], $targets);

            $this->assertSame(
                [base_path('../packages/foo')],
                $targets['groups']['shallow']['operands'],
            );
            $this->assertSame(
                [realpath(base_path())],
                $targets['groups']['recursive']['operands'],
            );
            $this->assertSame($file, $channel->pop());
        } finally {
            $driver->stop();
            $channel->close();
        }
    }

    public function testStopBeforeWatchDoesNotOpenProcessResources(): void
    {
        $driver = new InspectableFswatchDriver($this->option());
        $channel = new Channel(1);

        try {
            $driver->stop();
            $driver->watch($channel);

            $this->assertSame([], $driver->openedResourcesForTest());
        } finally {
            $driver->stop();
            $channel->close();
        }
    }

    public function testExactFilesShareTheirParentMappingAndMissingOperandsAreRetained(): void
    {
        $configPath = $this->fixturePath . '/config';
        $this->files->makeDirectory($configPath);
        $missingRelativePath = $this->fixtureRelativePath . '/missing';
        $watchPaths = [
            new WatchPath($this->fixtureRelativePath . '/config/app.php', WatchPathType::File),
            new WatchPath($this->fixtureRelativePath . '/config/queue.php', WatchPathType::File),
            new WatchPath(
                $missingRelativePath,
                WatchPathType::Directory,
                $missingRelativePath . '/*.php',
            ),
        ];
        $driver = new InspectableFswatchDriver(new Option(driver: FswatchDriver::class, watchPaths: $watchPaths));

        $this->assertSame(
            [$configPath, $this->fixturePath . '/missing'],
            $driver->operandsForTest(),
        );
        $this->assertSame(
            [
                [
                    'prefix' => $configPath . '/',
                    'base' => $this->fixtureRelativePath . '/config',
                ],
                [
                    'prefix' => $this->fixturePath . '/missing/',
                    'base' => $missingRelativePath,
                ],
            ],
            $driver->targetsForTest()['entries'],
        );
    }

    public function testMissingOperandKeepsItsLiteralPrefixThroughASymlinkedAncestor(): void
    {
        $this->files->makeDirectory($this->fixturePath . '/real');
        symlink($this->fixturePath . '/real', $this->fixturePath . '/link');
        $missingBase = $this->fixtureRelativePath . '/link/later';
        $watchPath = new WatchPath($missingBase, WatchPathType::Directory, $missingBase . '/*.php');
        $driver = new InspectableFswatchDriver(
            new Option(driver: FswatchDriver::class, watchPaths: [$watchPath]),
            darwin: false,
        );
        $targets = $driver->targetsForTest();
        $channel = new Channel(1);
        $file = $this->fixturePath . '/link/later/Foo.php';

        try {
            $driver->processChunksWithTargets($channel, [$file . "\0"], $targets);

            $this->assertSame([$this->fixturePath . '/link/later'], $driver->operandsForTest());
            $this->assertSame([[
                'prefix' => $this->fixturePath . '/link/later/',
                'base' => $missingBase,
            ]], $targets['entries']);
            $this->assertSame($file, $channel->pop());
        } finally {
            $driver->stop();
            $channel->close();
        }
    }

    public function testCanonicalEventsRecoverParentAndSymlinkConfiguredPaths(): void
    {
        $this->files->makeDirectory($this->fixturePath . '/app');
        $this->files->makeDirectory($this->fixturePath . '/sibling');
        $this->files->makeDirectory($this->fixturePath . '/real');
        symlink($this->fixturePath . '/real', $this->fixturePath . '/link');

        $parentBase = $this->fixtureRelativePath . '/app/../sibling';
        $symlinkBase = $this->fixtureRelativePath . '/link';
        $combinedBase = $this->fixtureRelativePath . '/app/../link';
        $watchPaths = [
            new WatchPath($parentBase, WatchPathType::Directory, $parentBase . '/parent.php'),
            new WatchPath($symlinkBase, WatchPathType::Directory, $symlinkBase . '/symlink.php'),
            new WatchPath($combinedBase, WatchPathType::Directory, $combinedBase . '/combined.php'),
        ];
        $driver = new InspectableFswatchDriver(new Option(driver: FswatchDriver::class, watchPaths: $watchPaths));
        $channel = new Channel(3);
        $files = [
            $this->fixturePath . '/sibling/parent.php',
            $this->fixturePath . '/real/symlink.php',
            $this->fixturePath . '/real/combined.php',
        ];

        try {
            $driver->processChunks($channel, [implode("\0", $files) . "\0"]);

            foreach ($files as $file) {
                $this->assertSame($file, $channel->pop());
            }

            $this->assertSame(0, $channel->getLength());
        } finally {
            $driver->stop();
            $channel->close();
        }
    }

    public function testMissingShallowOperandThatBecomesOutsideSymlinkKeepsLiteralCoverage(): void
    {
        $this->files->makeDirectory($this->fixturePath . '/app');
        $this->files->makeDirectory($this->fixturePath . '/outside');
        $generatedBase = $this->fixtureRelativePath . '/app/Generated';
        $watchPaths = [
            new WatchPath(
                $this->fixtureRelativePath . '/app',
                WatchPathType::Directory,
                $this->fixtureRelativePath . '/app/**/*.php',
            ),
            new WatchPath($generatedBase, WatchPathType::Directory, $generatedBase . '/*.js'),
        ];
        $driver = new InspectableFswatchDriver(new Option(driver: FswatchDriver::class, watchPaths: $watchPaths));
        $targets = $driver->targetsForTest();
        $channel = new Channel(1);
        $generatedFile = $this->fixturePath . '/app/Generated/Foo.js';

        symlink($this->fixturePath . '/outside', dirname($generatedFile));

        try {
            $driver->processChunksWithTargets($channel, [$generatedFile . "\0"], $targets);

            $this->assertSame($generatedFile, $channel->pop());
            $this->assertSame([
                'shallow' => [
                    'recursive' => false,
                    'operands' => [$this->fixturePath . '/app/Generated'],
                ],
                'recursive' => [
                    'recursive' => true,
                    'operands' => [$this->fixturePath . '/app'],
                ],
            ], $targets['groups']);
            $this->assertSame(2, count($targets['entries']));
        } finally {
            $driver->stop();
            $channel->close();
        }
    }

    public function testAliasedMappingsPublishARecordOnce(): void
    {
        $this->files->makeDirectory($this->fixturePath . '/real');
        symlink($this->fixturePath . '/real', $this->fixturePath . '/link');
        $realBase = $this->fixtureRelativePath . '/real';
        $linkBase = $this->fixtureRelativePath . '/link';
        $watchPaths = [
            new WatchPath($realBase, WatchPathType::Directory, $realBase . '/*.php'),
            new WatchPath($linkBase, WatchPathType::Directory, $linkBase . '/*.php'),
        ];
        $driver = new InspectableFswatchDriver(new Option(driver: FswatchDriver::class, watchPaths: $watchPaths));
        $targets = $driver->targetsForTest();
        $channel = new Channel(2);
        $file = $this->fixturePath . '/real/Foo.php';

        try {
            $driver->processChunksWithTargets($channel, [$file . "\0"], $targets);

            $this->assertSame(1, count($driver->operandsForTest()));
            $this->assertSame(2, count($targets['entries']));
            $this->assertSame($file, $channel->pop());
            $this->assertSame(0, $channel->getLength());
        } finally {
            $driver->stop();
            $channel->close();
        }
    }

    public function testEventsOutsideEveryCanonicalTargetAreIgnored(): void
    {
        $watchPath = new WatchPath($this->fixtureRelativePath, WatchPathType::Directory);
        $driver = new InspectableFswatchDriver(
            new Option(driver: FswatchDriver::class, watchPaths: [$watchPath]),
        );
        $channel = new Channel(1);

        try {
            $driver->processChunks($channel, [base_path('outside.php') . "\0"]);

            $this->assertSame(0, $channel->getLength());
        } finally {
            $driver->stop();
            $channel->close();
        }
    }

    public function testAtomicFileReplacementIsObservedThroughTheWatchedParent(): void
    {
        $relativePath = $this->fixtureRelativePath . '/watched.php';
        $path = base_path($relativePath);
        $replacementPath = $this->fixturePath . '/replacement.php';
        $this->files->put($path, 'before');
        $option = new Option(
            driver: FswatchDriver::class,
            watchPaths: [new WatchPath($relativePath, WatchPathType::File)],
        );

        try {
            $driver = new FswatchDriver($option);
        } catch (InvalidArgumentException $exception) {
            if ($exception->getMessage() === 'The FswatchDriver requires the `fswatch` executable.') {
                $this->markTestSkipped('The fswatch executable is not available.');
            }

            throw $exception;
        }

        $channel = new Channel(20);
        $finished = new WaitGroup(1);
        $failure = null;

        Coroutine::create(function () use ($channel, $driver, $finished, &$failure): void {
            try {
                $driver->watch($channel);
            } catch (RuntimeException $exception) {
                $failure = $exception;
            } finally {
                $finished->done();
            }
        });

        try {
            $deadline = hrtime(true) + 5_000_000_000;
            $received = false;

            // Fswatch registers after startup and batches records on its default latency.
            while (! $received && hrtime(true) < $deadline) {
                $this->files->put($replacementPath, 'replacement');
                rename($replacementPath, $path);
                $received = $channel->pop(0.25) === $path;
            }

            $this->assertTrue($received, 'fswatch did not report an atomic file replacement.');
        } finally {
            $driver->stop();
            $this->assertTrue($finished->wait(1));
            $channel->close();
        }

        $this->assertNull($failure);
    }

    public function testWatchPassesTheCommandAsAnArgumentListAndDeliversEachBatchInOrder(): void
    {
        $literalPath = 'shell $(literal).php';
        $option = new Option(
            driver: FswatchDriver::class,
            watchPaths: [
                new WatchPath($literalPath, WatchPathType::File),
                new WatchPath('second.php', WatchPathType::File),
            ],
        );
        $driver = new OutputFswatchDriver(
            $option,
            base_path($literalPath) . "\0" . base_path('second.php') . "\0",
        );
        $channel = new Channel(2);

        try {
            $driver->watch($channel);
            $this->fail('Expected the completed fswatch process to exit.');
        } catch (RuntimeException $exception) {
            $this->assertSame('The fswatch process exited unexpectedly.', $exception->getMessage());
        } finally {
            $driver->stop();
        }

        $this->assertSame(base_path($literalPath), $channel->pop());
        $this->assertSame(base_path('second.php'), $channel->pop());
        $this->assertSame(0, $channel->getLength());
        $this->assertSame(['shallow'], $driver->openedPipeGroups);
        $this->assertTrue($driver->resourcesAreClosed());

        $channel->close();
    }

    public function testWatchReadsBothLinuxProcessGroupsAndOwnerCleanupReleasesThem(): void
    {
        $this->files->makeDirectory($this->fixturePath . '/app');
        $this->files->put($this->fixturePath . '/.env', 'APP_ENV=local');
        $appBase = $this->fixtureRelativePath . '/app';
        $envPath = $this->fixtureRelativePath . '/.env';
        $option = new Option(
            driver: FswatchDriver::class,
            watchPaths: [
                new WatchPath($appBase, WatchPathType::Directory, $appBase . '/**/*.php'),
                new WatchPath($envPath, WatchPathType::File),
            ],
        );
        $files = [
            'shallow' => $this->fixturePath . '/.env',
            'recursive' => $this->fixturePath . '/app/Model.php',
        ];
        $driver = new GroupedOutputFswatchDriver($option, $files);
        $channel = new Channel(2);
        $finished = new WaitGroup(1);
        $failure = null;

        Coroutine::create(function () use ($channel, $driver, $finished, &$failure): void {
            try {
                $driver->watch($channel);
            } catch (RuntimeException $exception) {
                $failure = $exception;
            } finally {
                $finished->done();
            }
        });

        try {
            $published = [$channel->pop(1), $channel->pop(1)];
            sort($published);
            sort($files);

            $this->assertSame(array_values($files), $published);
            $this->assertSame(['recursive', 'shallow'], $driver->openedGroups());
        } finally {
            $driver->stop();
            $this->assertTrue($finished->wait(1));
            $channel->close();
        }

        $this->assertNull($failure);
        $this->assertTrue($driver->resourcesAreClosed());
    }

    public function testNulParserPreservesFragmentsAndNewlinesAndIgnoresEmptyAndIncompleteRecords(): void
    {
        $newlinePath = "line\nbreak.php";
        $option = new Option(
            driver: FswatchDriver::class,
            watchPaths: [
                new WatchPath('first.php', WatchPathType::File),
                new WatchPath('second.php', WatchPathType::File),
                new WatchPath($newlinePath, WatchPathType::File),
                new WatchPath('incomplete.php', WatchPathType::File),
            ],
        );
        $driver = new InspectableFswatchDriver($option);
        $channel = new Channel(3);
        $second = base_path('second.php');

        try {
            $driver->processChunks($channel, [
                base_path('first.php') . "\0\0" . substr($second, 0, -3),
                substr($second, -3) . "\0" . base_path($newlinePath) . "\0" . base_path('incomplete.php'),
            ]);

            $this->assertSame(base_path('first.php'), $channel->pop());
            $this->assertSame(base_path('second.php'), $channel->pop());
            $this->assertSame(base_path($newlinePath), $channel->pop());
            $this->assertSame(0, $channel->getLength());
        } finally {
            $driver->stop();
            $channel->close();
        }
    }

    public function testWatchPathFailureEscapesThroughTheOwnedDriverCoroutine(): void
    {
        $failure = new RuntimeException('expected match failure');
        $option = new Option(
            driver: FswatchDriver::class,
            watchPaths: [new ThrowingWatchPath($failure)],
        );
        $driver = new OutputFswatchDriver(
            $option,
            base_path('throw.php') . "\0",
        );
        $channel = new Channel(1);

        try {
            $driver->watch($channel);
            $this->fail('Expected path matching to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame($failure, $exception);
        } finally {
            $driver->stop();
            $channel->close();
        }

        $this->assertTrue($driver->resourcesAreClosed());
    }

    /**
     * Create the standard fswatch test options.
     */
    private function option(): Option
    {
        return new Option(
            driver: FswatchDriver::class,
            watchPaths: [
                new WatchPath($this->fixtureRelativePath, WatchPathType::Directory),
            ],
        );
    }
}

class InspectableFswatchDriver extends FswatchDriver
{
    /** @var list<string> */
    public array $executedCommands = [];

    /**
     * Create an inspectable fswatch driver.
     *
     * @param array{code: int, output: string} $probeResult
     */
    public function __construct(
        Option $option,
        protected bool $darwin = false,
        protected array $probeResult = ['code' => 0, 'output' => '/usr/bin/fswatch'],
    ) {
        parent::__construct($option);
    }

    protected function exec(string $command): array
    {
        $this->executedCommands[] = $command;

        return $this->probeResult;
    }

    public function isDarwin(): bool
    {
        return $this->darwin;
    }

    /**
     * Return the command for the configured paths.
     *
     * @return list<string>
     */
    public function commandForTest(): array
    {
        $targets = $this->targetsForTest();
        $group = array_values($targets['groups'])[0];

        return $this->getCommand($group['operands'], $group['recursive']);
    }

    /**
     * Return commands for every configured process group.
     *
     * @return array<string, list<string>>
     */
    public function commandsForTest(): array
    {
        $commands = [];

        foreach ($this->targetsForTest()['groups'] as $name => $group) {
            $commands[$name] = $this->getCommand($group['operands'], $group['recursive']);
        }

        return $commands;
    }

    /**
     * Return every configured command operand.
     *
     * @return list<string>
     */
    public function operandsForTest(): array
    {
        return array_merge(...array_column($this->targetsForTest()['groups'], 'operands'));
    }

    /**
     * Return the names of groups holding process resources.
     *
     * @return list<string>
     */
    public function openedResourcesForTest(): array
    {
        return array_values(array_unique([
            ...array_keys($this->processes),
            ...array_keys($this->pipes),
        ]));
    }

    /**
     * Resolve the configured command operands and matcher mappings.
     *
     * @return array{
     *     groups: array<string, array{recursive: bool, operands: list<string>}>,
     *     entries: list<array{prefix: string, base: string}>
     * }
     */
    public function targetsForTest(): array
    {
        return $this->resolveWatchTargets($this->option->getWatchPaths());
    }

    /**
     * Process scripted output chunks using the configured target mappings.
     *
     * @param list<string> $chunks
     */
    public function processChunks(Channel $channel, array $chunks): void
    {
        $this->processChunksWithTargets($channel, $chunks, $this->targetsForTest());
    }

    /**
     * Process scripted output chunks using supplied target mappings.
     *
     * @param list<string> $chunks
     * @param array{
     *     groups: array<string, array{recursive: bool, operands: list<string>}>,
     *     entries: list<array{prefix: string, base: string}>
     * } $targets
     */
    public function processChunksWithTargets(Channel $channel, array $chunks, array $targets): void
    {
        $buffer = '';
        $watchPaths = $this->option->getWatchPaths();

        foreach ($chunks as $chunk) {
            $this->processOutput($buffer, $chunk, $channel, $watchPaths, $targets['entries']);
        }
    }
}

class OutputFswatchDriver extends FswatchDriver
{
    /** @var list<string> */
    public array $openedPipeGroups = [];

    public function __construct(
        Option $option,
        protected string $output,
    ) {
        parent::__construct($option);
    }

    protected function exec(string $command): array
    {
        return ['code' => 0, 'output' => '/usr/bin/fswatch'];
    }

    protected function getCommand(array $operands = [], bool $recursive = false): array
    {
        return [
            PHP_BINARY,
            '-r',
            'fwrite(STDOUT, base64_decode($argv[1], true));',
            base64_encode($this->output),
        ];
    }

    protected function openProcess(string $group, array $operands, bool $recursive): void
    {
        parent::openProcess($group, $operands, $recursive);

        $this->openedPipeGroups = array_keys($this->pipes);
    }

    public function resourcesAreClosed(): bool
    {
        return $this->processes === [] && $this->pipes === [];
    }
}

class GroupedOutputFswatchDriver extends FswatchDriver
{
    /**
     * Create a driver with one scripted record per process group.
     *
     * @param array{shallow: string, recursive: string} $outputs
     */
    public function __construct(
        Option $option,
        protected array $outputs,
    ) {
        parent::__construct($option);
    }

    protected function exec(string $command): array
    {
        return ['code' => 0, 'output' => '/usr/bin/fswatch'];
    }

    protected function getCommand(array $operands = [], bool $recursive = false): array
    {
        $group = $recursive ? 'recursive' : 'shallow';

        return [
            PHP_BINARY,
            '-r',
            'usleep((int) $argv[1]); fwrite(STDOUT, base64_decode($argv[2], true)); usleep(500000);',
            $recursive ? '20000' : '10000',
            base64_encode($this->outputs[$group] . "\0"),
        ];
    }

    /**
     * Return the opened process groups.
     *
     * @return list<string>
     */
    public function openedGroups(): array
    {
        $groups = array_keys($this->processes);
        sort($groups);

        return $groups;
    }

    /**
     * Determine whether every process resource was released.
     */
    public function resourcesAreClosed(): bool
    {
        return $this->processes === [] && $this->pipes === [];
    }
}

readonly class ThrowingWatchPath extends WatchPath
{
    public function __construct(public RuntimeException $failure)
    {
        parent::__construct('.', WatchPathType::Directory);
    }

    public function matches(string $relativePath): bool
    {
        throw $this->failure;
    }
}

class FswatchDriverStreamState
{
    public int $readCount = 0;

    public int $closeCount = 0;

    /** @var null|resource */
    public mixed $selectStream = null;

    /** @var null|resource */
    public mixed $selectPeer = null;

    /**
     * Create a scripted stream state.
     */
    public function __construct(
        public false|string $readResult,
        public bool $eof,
    ) {
    }
}

class FswatchDriverStreamWrapper
{
    public const string PROTOCOL = 'hypervel-fswatch-driver-test';

    /** @var resource */
    public $context;

    private FswatchDriverStreamState $state;

    /**
     * Open the test stream from its context state.
     */
    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        $state = stream_context_get_options($this->context)[self::PROTOCOL]['state'] ?? null;

        if (! $state instanceof FswatchDriverStreamState) {
            return false;
        }

        $this->state = $state;

        return true;
    }

    /**
     * Return the configured read result.
     */
    public function stream_read(int $count): false|string
    {
        ++$this->state->readCount;

        return $this->state->readResult;
    }

    /**
     * Return the configured EOF state.
     */
    public function stream_eof(): bool
    {
        return $this->state->eof;
    }

    /**
     * Return the selectable stream behind the scripted wrapper.
     *
     * @return resource
     */
    public function stream_cast(int $castAs): mixed
    {
        return $this->state->selectStream;
    }

    /**
     * Record stream closure.
     */
    public function stream_close(): void
    {
        ++$this->state->closeCount;

        foreach (['selectStream', 'selectPeer'] as $property) {
            if (is_resource($this->state->{$property})) {
                fclose($this->state->{$property});
            }

            $this->state->{$property} = null;
        }
    }
}

class FswatchDriverReadFailureStub extends FswatchDriver
{
    protected bool $registeredWrapper = false;

    /**
     * Create a driver with scripted pipe state.
     */
    public function __construct(
        Option $option,
        protected FswatchDriverStreamState $state,
    ) {
        parent::__construct($option);
    }

    /**
     * Bypass the fswatch availability probe.
     */
    protected function exec(string $command): array
    {
        return ['code' => 0, 'output' => '/usr/bin/fswatch'];
    }

    /**
     * Open a live child with a scripted output stream.
     */
    protected function openProcess(string $group, array $operands, bool $recursive): void
    {
        $process = proc_open(['sleep', '60'], [['pipe', 'r'], ['pipe', 'w']], $pipes);

        if (! is_resource($process)) {
            throw new RuntimeException('Unable to open the test process.');
        }

        foreach ($pipes as $pipe) {
            fclose($pipe);
        }

        $protocol = FswatchDriverStreamWrapper::PROTOCOL;
        if (! in_array($protocol, stream_get_wrappers(), true)) {
            $this->registeredWrapper = stream_wrapper_register($protocol, FswatchDriverStreamWrapper::class);
        }

        $context = stream_context_create([$protocol => ['state' => $this->state]]);
        [$this->state->selectStream, $this->state->selectPeer] = stream_socket_pair(
            STREAM_PF_UNIX,
            STREAM_SOCK_STREAM,
            STREAM_IPPROTO_IP,
        );
        fwrite($this->state->selectPeer, 'x');
        $pipe = fopen($protocol . '://stream', 'r', false, $context);

        if (! is_resource($pipe)) {
            proc_terminate($process, SIGKILL);
            proc_close($process);

            throw new RuntimeException('Unable to open the test output stream.');
        }

        $this->processes = [$group => $process];
        $this->pipes = [$group => $pipe];
    }

    /**
     * Stop the child and unregister the scripted stream wrapper.
     */
    public function stop(): void
    {
        parent::stop();

        if ($this->registeredWrapper) {
            stream_wrapper_unregister(FswatchDriverStreamWrapper::PROTOCOL);
            $this->registeredWrapper = false;
        }
    }

    /**
     * Determine whether all subprocess resources were released.
     */
    public function resourcesAreClosed(): bool
    {
        return $this->processes === [] && $this->pipes === [];
    }
}
