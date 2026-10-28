<?php

declare(strict_types=1);

namespace Hypervel\Tests\Console;

use Hypervel\Cache\Repository;
use Hypervel\Cache\StorageStore;
use Hypervel\Console\CacheCommandMutex;
use Hypervel\Console\Command;
use Hypervel\Console\CommandMutex;
use Hypervel\Console\Events\AfterExecute;
use Hypervel\Console\Events\AfterHandle;
use Hypervel\Console\Events\BeforeHandle;
use Hypervel\Contracts\Console\Isolatable;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Cache\Fixtures\ArrayFilesystem;
use Hypervel\Tests\Console\Fixtures\FakeCacheFactory;
use Mockery as m;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Swoole\Coroutine\CanceledException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

class CommandMutexTest extends TestCase
{
    protected Command $command;

    protected CommandMutex $commandMutex;

    /**
     * Set up an isolatable command and its mutex.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->command = new class extends Command implements Isolatable {
            public int $ran = 0;

            /**
             * Execute the command.
             */
            public function __invoke(): void
            {
                ++$this->ran;
            }
        };
        $this->command->setHypervel($this->app);

        $this->commandMutex = new CacheCommandMutex(new FakeCacheFactory(
            new Repository(new StorageStore(new ArrayFilesystem, 'cache'))
        ));

        $this->app->instance(CommandMutex::class, $this->commandMutex);
    }

    public function testCanRunIsolatedCommandIfNotBlocked(): void
    {
        $this->runCommand();

        $this->assertEquals(1, $this->command->ran);
    }

    public function testCannotRunIsolatedCommandIfBlocked(): void
    {
        $this->commandMutex->create($this->command);

        $this->runCommand();

        $this->assertEquals(0, $this->command->ran);
    }

    public function testBlockedIsolatedCommandNormalizesItsConfiguredExitCode(): void
    {
        $this->commandMutex->create($this->command);

        $exitCode = $this->command->run(
            new ArrayInput(['--isolated' => 300]),
            new NullOutput,
        );

        $this->assertSame(Command::INVALID, $exitCode);
        $this->assertSame(0, $this->command->ran);
    }

    public function testCanRunCommandAgainAfterOtherCommandFinished(): void
    {
        $this->runCommand();
        $this->runCommand();

        $this->assertEquals(2, $this->command->ran);
    }

    public function testCanRunCommandAgainNonAutomated(): void
    {
        $this->commandMutex->create($this->command);

        $this->runCommand(false);

        $this->assertEquals(1, $this->command->ran);
    }

    public function testCommandReleasesTheExactMutexInstanceThatItAcquired(): void
    {
        $acquiredMutex = m::mock(CommandMutex::class);
        $unusedMutex = m::mock(CommandMutex::class);
        $resolutions = 0;

        $acquiredMutex->shouldReceive('create')->once()->with($this->command)->andReturnTrue();
        $acquiredMutex->shouldReceive('forget')->once()->with($this->command)->andReturnTrue();
        $unusedMutex->shouldReceive('create', 'forget')->never();

        $this->app->bind(CommandMutex::class, function () use ($acquiredMutex, $unusedMutex, &$resolutions) {
            return $resolutions++ === 0 ? $acquiredMutex : $unusedMutex;
        });

        $this->runCommand();

        $this->assertSame(1, $resolutions);
    }

    public function testExtendedHandlerLifecycleSharesInvocationWidePhases(): void
    {
        $command = new class extends Command implements Isolatable {
            public int $ran = 0;

            public function __invoke(): void
            {
                ++$this->ran;
            }

            protected function executeCommand(InputInterface $input, OutputInterface $output): int
            {
                parent::executeCommand($input, $output);

                return parent::executeCommand($input, $output);
            }
        };
        $command->setHypervel($this->app);
        $events = [
            BeforeHandle::class => 0,
            AfterHandle::class => 0,
            AfterExecute::class => 0,
        ];

        foreach (array_keys($events) as $event) {
            $this->app->make(Dispatcher::class)->listen(
                $event,
                static function () use (&$events, $event): void {
                    ++$events[$event];
                },
            );
        }

        $commandMutex = $this->mockCommandMutex();
        $commandMutex->shouldReceive('create')->once()->with($command)->andReturnTrue();
        $commandMutex->shouldReceive('forget')->once()->with($command)->andReturnTrue();

        $command->run(
            new ArrayInput(['--isolated' => true]),
            new NullOutput,
        );

        $this->assertSame(2, $command->ran);
        $this->assertSame(2, $events[BeforeHandle::class]);
        $this->assertSame(2, $events[AfterHandle::class]);
        $this->assertSame(1, $events[AfterExecute::class]);
    }

    public function testAfterExecuteFailureStillReleasesMutex(): void
    {
        $commandMutex = $this->mockCommandMutex();
        $commandMutex->shouldReceive('create')->once()->andReturnTrue();
        $commandMutex->shouldReceive('forget')->once()->andReturnTrue();

        $this->app->make(Dispatcher::class)->listen(
            AfterExecute::class,
            fn () => throw new RuntimeException('after execute failed')
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('after execute failed');

        $this->runCommand();
    }

    public function testCommandFailureRemainsPrimaryWhenTerminalPhasesFail(): void
    {
        $command = new class extends Command implements Isolatable {
            public function __invoke(): void
            {
                throw new RuntimeException('command failed');
            }
        };
        $command->setHypervel($this->app);

        $commandMutex = $this->mockCommandMutex();
        $commandMutex->shouldReceive('create')->once()->andReturnTrue();
        $commandMutex->shouldReceive('forget')->once()->andThrow(new RuntimeException('mutex release failed'));

        $this->app->make(Dispatcher::class)->listen(
            AfterExecute::class,
            fn () => throw new RuntimeException('after execute failed')
        );

        try {
            $command->run(new ArrayInput(['--isolated' => true]), new NullOutput);
            $this->fail('The command exception was not thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('command failed', $exception->getMessage());
        }
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testAfterExecuteCancellationSupersedesAnOrdinaryCommandFailureAndStillReleasesTheMutex(bool $cleanupThrows): void
    {
        $command = new class extends Command implements Isolatable {
            public function __invoke(): void
            {
                throw new RuntimeException('command failed');
            }
        };
        $command->setHypervel($this->app);
        $cancellation = new CanceledException('canceled');
        $cleanup = $cleanupThrows ? new RuntimeException('Observer cleanup failed.') : null;

        $commandMutex = $this->mockCommandMutex();
        $commandMutex->shouldReceive('create')->once()->andReturnTrue();
        $commandMutex->shouldReceive('forget')->once()->andReturnTrue();
        $this->app->make(Dispatcher::class)->listen(
            AfterExecute::class,
            static function () use ($cancellation, $cleanup): never {
                try {
                    throw $cancellation;
                } finally {
                    if ($cleanup !== null) {
                        throw $cleanup;
                    }
                }
            },
        );

        try {
            $command->run(new ArrayInput(['--isolated' => true]), new NullOutput);
            $this->fail('Expected the after-execute observer to preserve cancellation.');
        } catch (Throwable $exception) {
            $this->assertSame($cleanup ?? $cancellation, $exception);
        }
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testMutexCancellationSupersedesAnOrdinaryCommandFailure(bool $cleanupThrows): void
    {
        $command = new class extends Command implements Isolatable {
            public function __invoke(): void
            {
                throw new RuntimeException('command failed');
            }
        };
        $command->setHypervel($this->app);
        $cancellation = new CanceledException('canceled');
        $cleanup = $cleanupThrows ? new RuntimeException('Mutex cleanup failed.') : null;

        $commandMutex = $this->mockCommandMutex();
        $commandMutex->shouldReceive('create')->once()->andReturnTrue();
        $commandMutex->shouldReceive('forget')->once()->andReturnUsing(static function () use ($cancellation, $cleanup): never {
            try {
                throw $cancellation;
            } finally {
                if ($cleanup !== null) {
                    throw $cleanup;
                }
            }
        });

        try {
            $command->run(new ArrayInput(['--isolated' => true]), new NullOutput);
            $this->fail('Expected mutex release to preserve cancellation.');
        } catch (Throwable $exception) {
            $this->assertSame($cleanup ?? $cancellation, $exception);
        }
    }

    public function testCommandCancellationStillRunsAfterExecuteAndReleasesTheMutex(): void
    {
        $cancellation = new CanceledException('canceled');
        $command = new class($cancellation) extends Command implements Isolatable {
            public function __construct(private readonly CanceledException $cancellation)
            {
                parent::__construct('cancelling');
            }

            public function __invoke(): void
            {
                throw $this->cancellation;
            }
        };
        $command->setHypervel($this->app);
        $observed = null;

        $commandMutex = $this->mockCommandMutex();
        $commandMutex->shouldReceive('create')->once()->andReturnTrue();
        $commandMutex->shouldReceive('forget')->once()->andReturnTrue();
        $this->app->make(Dispatcher::class)->listen(
            AfterExecute::class,
            function (AfterExecute $event) use (&$observed): void {
                $observed = $event->throwable;
            },
        );

        try {
            $command->run(new ArrayInput(['--isolated' => true]), new NullOutput);
            $this->fail('Expected command execution to preserve cancellation.');
        } catch (CanceledException $exception) {
            $this->assertSame($cancellation, $exception);
        }

        $this->assertSame($cancellation, $observed);
    }

    /**
     * Replace the bound command mutex with a mock.
     */
    protected function mockCommandMutex(): CommandMutex&MockInterface
    {
        $commandMutex = m::mock(CommandMutex::class);

        $this->app->instance(CommandMutex::class, $commandMutex);

        return $commandMutex;
    }

    /**
     * Run the command with optional isolation.
     */
    protected function runCommand(bool $withIsolated = true): void
    {
        $input = new ArrayInput(['--isolated' => $withIsolated]);
        $output = new NullOutput;
        $this->command->run($input, $output);
    }
}
