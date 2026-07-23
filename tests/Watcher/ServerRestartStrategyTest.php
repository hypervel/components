<?php

declare(strict_types=1);

namespace Hypervel\Tests\Watcher;

use Hypervel\Config\Repository;
use Hypervel\Contracts\Debug\ExceptionHandler as ExceptionHandlerContract;
use Hypervel\Engine\Channel;
use Hypervel\Testbench\TestCase;
use Hypervel\Watcher\ServerRestartStrategy;
use InvalidArgumentException;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

class ServerRestartStrategyTest extends TestCase
{
    public function testConstructorDoesNotRequirePidFileConfiguration(): void
    {
        $this->app->instance('config', new Repository([
            'server' => ['settings' => ['daemonize' => false]],
            'watcher' => ['bin' => PHP_BINARY, 'command' => ['artisan', 'serve']],
        ]));

        $strategy = new ServerRestartStrategy($this->app, new NullOutput);

        $this->assertInstanceOf(ServerRestartStrategy::class, $strategy);
    }

    public function testConstructorThrowsWhenDaemonizeIsTrue(): void
    {
        $this->app->instance('config', new Repository([
            'server' => ['settings' => ['pid_file' => '/tmp/test.pid', 'daemonize' => true]],
            'watcher' => ['bin' => PHP_BINARY, 'command' => ['artisan', 'serve']],
        ]));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Please set `server.settings.daemonize` to false');

        new ServerRestartStrategy($this->app, new NullOutput);
    }

    public function testServerCommandPreservesEveryArgumentLiterally(): void
    {
        $bin = "/tmp/php binary'$(ignored)";
        $script = "artisan script'$(ignored)";
        $arguments = ['serve', '--host=example value', "--name=a'b", '$(ignored)'];

        $this->app->instance('config', new Repository([
            'server' => ['settings' => ['pid_file' => '/tmp/test.pid', 'daemonize' => false]],
            'watcher' => ['bin' => $bin, 'command' => [$script, ...$arguments]],
        ]));

        $strategy = new class($this->app, new NullOutput) extends ServerRestartStrategy {
            public function commandForTest(): array
            {
                return $this->serverCommand();
            }
        };

        $this->assertSame([$bin, base_path($script), ...$arguments], $strategy->commandForTest());
    }

    public function testConstructorRejectsAnEmptyExecutablePath(): void
    {
        $this->app->instance('config', new Repository([
            'server' => ['settings' => ['pid_file' => '/tmp/test.pid', 'daemonize' => false]],
            'watcher' => ['bin' => '', 'command' => ['artisan', 'serve']],
        ]));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The watcher.bin configuration value must be a non-empty executable path.');

        new ServerRestartStrategy($this->app, new NullOutput);
    }

    #[DataProvider('invalidCommandProvider')]
    public function testConstructorRejectsInvalidCommandLists(array $command): void
    {
        $this->app->instance('config', new Repository([
            'server' => ['settings' => ['pid_file' => '/tmp/test.pid', 'daemonize' => false]],
            'watcher' => ['bin' => PHP_BINARY, 'command' => $command],
        ]));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The watcher.command configuration value must be a non-empty list of non-empty strings.');

        new ServerRestartStrategy($this->app, new NullOutput);
    }

    public static function invalidCommandProvider(): array
    {
        return [
            'empty' => [[]],
            'associative' => [['script' => 'artisan']],
            'empty argument' => [['artisan', '']],
            'non-string argument' => [['artisan', 1]],
        ];
    }

    public function testStopIsIdempotentWithoutAnOwnedProcess(): void
    {
        $strategy = $this->createProbeStrategy();

        $strategy->stop();
        $strategy->stop();

        $this->assertSame([], $strategy->signals);
    }

    public function testStopSignalsTheExactOwnedProcess(): void
    {
        $strategy = $this->createProbeStrategy();
        [$entered, $resume] = $strategy->blockNextClose();

        try {
            $strategy->start();
            $this->assertTrue($entered->pop(0.1));

            $strategy->stop();

            $this->assertSame([[1000, SIGTERM]], $strategy->signals);

            $resume->push(true);
            $this->waitFor(fn (): bool => ! $strategy->lifecycleIsRunning());
        } finally {
            $resume->push(true, 0.001);
            $entered->close();
            $resume->close();
        }
    }

    public function testRepeatedRestartsCoalesceIntoOneRelaunch(): void
    {
        $strategy = $this->createProbeStrategy();
        [$entered, $resume] = $strategy->blockNextClose();

        try {
            $strategy->start();
            $this->assertTrue($entered->pop(0.1));

            $strategy->restart();
            $strategy->restart();
            $strategy->restart();
            $resume->push(true);

            $this->waitFor(fn (): bool => ! $strategy->lifecycleIsRunning());

            $this->assertSame(2, $strategy->openCalls);
            $this->assertSame(
                [[1000, SIGTERM], [1000, SIGTERM], [1000, SIGTERM]],
                $strategy->signals,
            );
        } finally {
            $resume->push(true, 0.001);
            $entered->close();
            $resume->close();
        }
    }

    public function testRestartBeforePidPublicationTerminatesThenRelaunchesTheNewProcess(): void
    {
        $strategy = $this->createProbeStrategy();
        [$entered, $resume] = $strategy->blockNextOpen();

        try {
            $strategy->start();
            $this->assertTrue($entered->pop(0.1));

            $strategy->restart();
            $this->assertSame([], $strategy->signals);

            $resume->push(true);
            $this->waitFor(fn (): bool => ! $strategy->lifecycleIsRunning());

            $this->assertSame([[1000, SIGTERM]], $strategy->signals);
            $this->assertSame(2, $strategy->openCalls);
        } finally {
            $resume->push(true, 0.001);
            $entered->close();
            $resume->close();
        }
    }

    public function testStopBeforePidPublicationTerminatesWithoutRelaunching(): void
    {
        $strategy = $this->createProbeStrategy();
        [$entered, $resume] = $strategy->blockNextOpen();

        try {
            $strategy->start();
            $this->assertTrue($entered->pop(0.1));

            $strategy->stop();
            $this->assertSame([], $strategy->signals);

            $resume->push(true);
            $this->waitFor(fn (): bool => ! $strategy->lifecycleIsRunning());

            $this->assertSame([[1000, SIGTERM]], $strategy->signals);
            $this->assertSame(1, $strategy->openCalls);
        } finally {
            $resume->push(true, 0.001);
            $entered->close();
            $resume->close();
        }
    }

    public function testFinalStopClearsAPendingRestart(): void
    {
        $strategy = $this->createProbeStrategy();
        [$entered, $resume] = $strategy->blockNextClose();

        try {
            $strategy->start();
            $this->assertTrue($entered->pop(0.1));

            $strategy->restart();
            $strategy->stop();
            $resume->push(true);

            $this->waitFor(fn (): bool => ! $strategy->lifecycleIsRunning());

            $this->assertSame(1, $strategy->openCalls);
            $this->assertSame([[1000, SIGTERM], [1000, SIGTERM]], $strategy->signals);
        } finally {
            $resume->push(true, 0.001);
            $entered->close();
            $resume->close();
        }
    }

    public function testOpenFailureClearsLifecycleOwnershipAndAllowsRetry(): void
    {
        $handler = m::mock(ExceptionHandlerContract::class);
        $handler->shouldReceive('report')
            ->once()
            ->with(m::on(
                fn (mixed $exception): bool => $exception instanceof RuntimeException
                    && $exception->getMessage() === 'Unable to launch the watched server process.',
            ));
        $this->app->instance(ExceptionHandlerContract::class, $handler);
        $strategy = $this->createProbeStrategy();
        $strategy->openModes = ['fail', 'success'];

        $strategy->start();
        $this->waitFor(fn (): bool => ! $strategy->lifecycleIsRunning());

        $strategy->start();
        $this->waitFor(fn (): bool => ! $strategy->lifecycleIsRunning());

        $this->assertSame(2, $strategy->openCalls);
        $this->assertSame(2, $strategy->reloadCalls);
    }

    public function testCloseFailureClearsLifecycleOwnershipAndAllowsRetry(): void
    {
        $failure = new RuntimeException('expected close failure');
        $handler = m::mock(ExceptionHandlerContract::class);
        $handler->shouldReceive('report')->once()->with($failure);
        $this->app->instance(ExceptionHandlerContract::class, $handler);
        $strategy = $this->createProbeStrategy();
        $strategy->closeFailures = [$failure, null];

        $strategy->start();
        $this->waitFor(fn (): bool => ! $strategy->lifecycleIsRunning());

        $strategy->start();
        $this->waitFor(fn (): bool => ! $strategy->lifecycleIsRunning());

        $this->assertSame(2, $strategy->openCalls);
    }

    public function testMalformedEnvironmentDuringRestartClearsOwnershipAndAllowsRetry(): void
    {
        $failure = new RuntimeException('malformed environment');
        $handler = m::mock(ExceptionHandlerContract::class);
        $handler->shouldReceive('report')->once()->with($failure);
        $this->app->instance(ExceptionHandlerContract::class, $handler);
        $strategy = $this->createProbeStrategy();
        $strategy->reloadFailures = [null, $failure, null];
        [$entered, $resume] = $strategy->blockNextClose();

        try {
            $strategy->start();
            $this->assertTrue($entered->pop(0.1));

            $strategy->restart();
            $resume->push(true);
            $this->waitFor(fn (): bool => ! $strategy->lifecycleIsRunning());

            $strategy->restart();
            $this->waitFor(fn (): bool => ! $strategy->lifecycleIsRunning());

            $this->assertSame(3, $strategy->reloadCalls);
            $this->assertSame(2, $strategy->openCalls);
        } finally {
            $resume->push(true, 0.001);
            $entered->close();
            $resume->close();
        }
    }

    public function testNormalServerExitClearsLifecycleOwnership(): void
    {
        $handler = m::mock(ExceptionHandlerContract::class);
        $handler->shouldNotReceive('report');
        $this->app->instance(ExceptionHandlerContract::class, $handler);
        $strategy = $this->createProbeStrategy();

        $strategy->start();
        $this->waitFor(fn (): bool => ! $strategy->lifecycleIsRunning());

        $this->assertSame(1, $strategy->openCalls);
        $this->assertSame(1, $strategy->reloadCalls);
    }

    public function testProcessIdIsUnpublishedBeforePostReapOutput(): void
    {
        $output = new class extends NullOutput {
            public ?ServerRestartStrategyProbe $strategy = null;

            public ?int $processIdAtExit = -1;

            public function writeln(
                string|iterable $messages,
                int $options = self::OUTPUT_NORMAL,
            ): void {
                if ($messages === 'Server exited.') {
                    $this->processIdAtExit = $this->strategy?->publishedProcessId();
                }
            }
        };
        $strategy = $this->createProbeStrategy($output);
        $output->strategy = $strategy;

        $strategy->start();
        $this->waitFor(fn (): bool => ! $strategy->lifecycleIsRunning());

        $this->assertNull($output->processIdAtExit);
    }

    /**
     * Wait for a lifecycle assertion without relying on scheduler luck.
     */
    private function waitFor(callable $condition): void
    {
        $deadline = hrtime(true) + 200_000_000;

        while (! $condition() && hrtime(true) < $deadline) {
            usleep(1_000);
        }

        $this->assertTrue($condition(), 'The server lifecycle did not reach the expected state.');
    }

    private function createProbeStrategy(?OutputInterface $output = null): ServerRestartStrategyProbe
    {
        $this->app->instance('config', new Repository([
            'server' => ['settings' => ['daemonize' => false]],
            'watcher' => ['bin' => PHP_BINARY, 'command' => ['artisan', 'serve']],
        ]));

        return new ServerRestartStrategyProbe($this->app, $output ?? new NullOutput);
    }
}

class ServerRestartStrategyProbe extends ServerRestartStrategy
{
    /** @var list<array{int, int}> */
    public array $signals = [];

    public int $openCalls = 0;

    public int $reloadCalls = 0;

    /** @var list<string> */
    public array $openModes = [];

    /** @var list<null|Throwable> */
    public array $closeFailures = [];

    /** @var list<null|Throwable> */
    public array $reloadFailures = [];

    protected int $nextPid = 1000;

    protected int $blockedOpenCalls = 0;

    protected int $blockedCloseCalls = 0;

    protected ?Channel $openEntered = null;

    protected ?Channel $openResume = null;

    protected ?Channel $closeEntered = null;

    protected ?Channel $closeResume = null;

    protected function openProcess(array $descriptorSpec, array &$pipes): mixed
    {
        ++$this->openCalls;

        if ($this->blockedOpenCalls > 0) {
            --$this->blockedOpenCalls;
            $this->openEntered?->push(true);
            $this->openResume?->pop();
        }

        if (array_shift($this->openModes) === 'fail') {
            return false;
        }

        return fopen('php://temp', 'r+');
    }

    protected function closeProcess(mixed $process): int
    {
        if ($this->blockedCloseCalls > 0) {
            --$this->blockedCloseCalls;
            $this->closeEntered?->push(true);
            $this->closeResume?->pop();
        }

        fclose($process);

        $failure = array_shift($this->closeFailures);
        if ($failure !== null) {
            throw $failure;
        }

        return 0;
    }

    protected function processStatus(mixed $process): array
    {
        return ['pid' => $this->nextPid++];
    }

    protected function signalProcess(int $pid, int $signal): bool
    {
        $this->signals[] = [$pid, $signal];

        return true;
    }

    protected function reloadEnvironment(): void
    {
        ++$this->reloadCalls;

        $failure = array_shift($this->reloadFailures);
        if ($failure !== null) {
            throw $failure;
        }
    }

    public function lifecycleIsRunning(): bool
    {
        return $this->lifecycleRunning;
    }

    /**
     * Return the currently published process ID.
     */
    public function publishedProcessId(): ?int
    {
        return $this->processId;
    }

    /**
     * Block the next process creation before PID publication.
     *
     * @return array{Channel, Channel}
     */
    public function blockNextOpen(): array
    {
        ++$this->blockedOpenCalls;
        $this->openEntered = new Channel(1);
        $this->openResume = new Channel(1);

        return [$this->openEntered, $this->openResume];
    }

    /**
     * Block the next process close after PID publication.
     *
     * @return array{Channel, Channel}
     */
    public function blockNextClose(): array
    {
        ++$this->blockedCloseCalls;
        $this->closeEntered = new Channel(1);
        $this->closeResume = new Channel(1);

        return [$this->closeEntered, $this->closeResume];
    }
}
