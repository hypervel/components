<?php

declare(strict_types=1);

namespace Hypervel\Tests\Watcher\Driver;

use Hypervel\Coroutine\WaitGroup;
use Hypervel\Engine\Channel;
use Hypervel\Engine\Coroutine;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Watcher\Fixtures\FswatchDriverStub;
use Hypervel\Watcher\Driver\FswatchDriver;
use Hypervel\Watcher\Option;
use Hypervel\Watcher\WatchPath;
use Hypervel\Watcher\WatchPathType;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

class FswatchDriverTest extends TestCase
{
    public function testWatch(): void
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
            if (str_contains($e->getMessage(), 'fswatch not exists')) {
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

    public function testStopTerminatesAndClosesProcess(): void
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

            public function setProcess(mixed $process, array $pipes): void
            {
                $this->process = $process;
                $this->pipes = $pipes;
            }
        };

        // Start a real child process.
        $process = proc_open(['sleep', '60'], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        $this->assertTrue(is_resource($process));

        $pid = proc_get_status($process)['pid'];
        $this->assertTrue(posix_kill($pid, 0), 'Process should be running before stop()');

        $driver->setProcess($process, $pipes);
        $driver->stop();
        $driver->stop();

        // After stop(), the process should be killed and the handle closed.
        $this->assertFalse(is_resource($process), 'Process handle should be closed after stop()');
        $this->assertFalse(is_resource($pipes[0]), 'Process input pipe should be closed after stop()');
        $this->assertFalse(is_resource($pipes[1]), 'Process output pipe should be closed after stop()');
        $this->assertFalse(is_resource($pipes[2]), 'Process error pipe should be closed after stop()');
        $this->assertFalse(posix_kill($pid, 0), 'Process should not be running after stop()');
    }

    public function testWatchFailsWhenTheFswatchProcessExits(): void
    {
        $option = $this->option();
        $driver = new class($option) extends FswatchDriver {
            protected function exec(string $command): array
            {
                return ['code' => 0, 'output' => '/usr/bin/fswatch'];
            }

            protected function getCommand(): array
            {
                return [PHP_BINARY, '-r', ''];
            }

            public function resourcesAreClosed(): bool
            {
                return ! is_resource($this->process) && $this->pipes === [];
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

            protected function getCommand(): array
            {
                return ['sleep', '60'];
            }

            public function resourcesAreClosed(): bool
            {
                return ! is_resource($this->process) && $this->pipes === [];
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

            protected function getCommand(): array
            {
                return ['sleep', '60'];
            }

            public function terminateProcess(): void
            {
                if (is_resource($this->process)) {
                    proc_terminate($this->process, SIGKILL);
                }
            }

            public function resourcesAreClosed(): bool
            {
                return ! is_resource($this->process) && $this->pipes === [];
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
            scanInterval: 1,
        );
        $driver = new class($option) extends FswatchDriver {
            protected function exec(string $command): array
            {
                return ['code' => 0, 'output' => '/usr/bin/fswatch'];
            }

            public function commandForTest(): array
            {
                return $this->getCommand();
            }
        };
        $resolvedPaths = array_map(base_path(...), $paths);

        $expected = $driver->isDarwin()
            ? ['fswatch', ...$resolvedPaths]
            : [
                'fswatch',
                '-m',
                'inotify_monitor',
                '-E',
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

    public function testWatchDeliversEachBatchInOrderWithoutDetachedChildren(): void
    {
        $option = new Option(
            driver: FswatchDriver::class,
            watchPaths: [
                new WatchPath('first.php', WatchPathType::File),
                new WatchPath('second.php', WatchPathType::File),
            ],
            scanInterval: 1,
        );
        $driver = new OutputFswatchDriver(
            $option,
            base_path('first.php') . "\n" . base_path('second.php') . "\n",
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

        $this->assertSame(base_path('first.php'), $channel->pop());
        $this->assertSame(base_path('second.php'), $channel->pop());
        $this->assertSame(0, $channel->getLength());
        $this->assertTrue($driver->resourcesAreClosed());

        $channel->close();
    }

    public function testWatchPreservesPathsSplitAcrossReads(): void
    {
        $option = new Option(
            driver: FswatchDriver::class,
            watchPaths: [
                new WatchPath('first.php', WatchPathType::File),
                new WatchPath('second.php', WatchPathType::File),
                new WatchPath('third.php', WatchPathType::File),
            ],
            scanInterval: 1,
        );
        $driver = new class($option) extends FswatchDriver {
            protected function exec(string $command): array
            {
                return ['code' => 0, 'output' => '/usr/bin/fswatch'];
            }

            public function processChunks(Channel $channel, array $chunks): void
            {
                $buffer = '';
                $watchPaths = $this->option->getWatchPaths();

                foreach ($chunks as $chunk) {
                    $this->processOutput($buffer, $chunk, $channel, base_path(), $watchPaths);
                }

                $this->processOutput($buffer, '', $channel, base_path(), $watchPaths, final: true);
            }
        };
        $channel = new Channel(3);
        $second = base_path('second.php');

        try {
            $driver->processChunks($channel, [
                base_path('first.php') . "\n" . substr($second, 0, -3),
                substr($second, -3) . "\n" . base_path('third.php'),
            ]);

            $this->assertSame(base_path('first.php'), $channel->pop());
            $this->assertSame(base_path('second.php'), $channel->pop());
            $this->assertSame(base_path('third.php'), $channel->pop());
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
            scanInterval: 1,
        );
        $driver = new OutputFswatchDriver(
            $option,
            base_path('throw.php') . "\n",
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
                new WatchPath('/tmp', WatchPathType::Directory),
            ],
            scanInterval: 1,
        );
    }
}

class OutputFswatchDriver extends FswatchDriver
{
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

    protected function getCommand(): array
    {
        return [
            PHP_BINARY,
            '-r',
            'fwrite(STDOUT, ' . var_export($this->output, true) . ');',
        ];
    }

    public function resourcesAreClosed(): bool
    {
        return ! is_resource($this->process) && $this->pipes === [];
    }
}

readonly class ThrowingWatchPath extends WatchPath
{
    public function __construct(public RuntimeException $failure)
    {
        parent::__construct('', WatchPathType::Directory);
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
    public const PROTOCOL = 'hypervel-fswatch-driver-test';

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
     * Record stream closure.
     */
    public function stream_close(): void
    {
        ++$this->state->closeCount;
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
    protected function openProcess(): void
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
        $pipe = fopen($protocol . '://stream', 'r', false, $context);

        if (! is_resource($pipe)) {
            proc_terminate($process, SIGKILL);
            proc_close($process);

            throw new RuntimeException('Unable to open the test output stream.');
        }

        $this->process = $process;
        $this->pipes = [1 => $pipe];
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
        return ! is_resource($this->process) && $this->pipes === [];
    }
}
