<?php

declare(strict_types=1);

namespace Hypervel\Tests\Console;

use Closure;
use Hypervel\Console\Application as ConsoleApplication;
use Hypervel\Console\Command;
use Hypervel\Console\CommandMutex;
use Hypervel\Console\Events\AfterExecute;
use Hypervel\Container\Container;
use Hypervel\Contracts\Console\Isolatable;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Support\Defer\DeferredCallbackCollection;
use Hypervel\Testbench\TestCase;
use Mockery as m;
use RuntimeException;
use Swoole\Coroutine\CanceledException;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

class ConsoleApplicationDeferredCallbacksTest extends TestCase
{
    protected bool $runTestsInCoroutine = false;

    public function testCoroutineCommandDrainsAfterObserversAndMutexCleanup(): void
    {
        $application = $this->createConsoleApplication();
        $calls = [];
        $mutex = m::mock(CommandMutex::class);
        $mutex->shouldReceive('create')->once()->andReturnUsing(function () use (&$calls): bool {
            $calls[] = 'mutex-created';

            return true;
        });
        $mutex->shouldReceive('forget')->once()->andReturnUsing(function () use (&$calls): bool {
            $calls[] = 'mutex-released';

            return true;
        });
        $this->app->instance(CommandMutex::class, $mutex);
        $this->app->make(Dispatcher::class)->listen(AfterExecute::class, function () use (&$calls): void {
            $calls[] = 'after-execute';
        });

        $application->addCommand(new IsolatableDeferredCommand('test:coroutine-order', function () use (&$calls): int {
            $calls[] = 'handle';
            defer(function () use (&$calls): void {
                $calls[] = 'deferred';
            });

            return Command::SUCCESS;
        }));

        $application->call('test:coroutine-order', ['--isolated' => true]);

        $this->assertSame([
            'mutex-created',
            'handle',
            'after-execute',
            'mutex-released',
            'deferred',
        ], $calls);
    }

    public function testCoroutineCommandDrainsWhenItsEventDispatcherIsDisabled(): void
    {
        $application = $this->createConsoleApplication();
        $calls = [];

        $application->addCommand(new DeferredCommand('test:coroutine-disabled-events', function () use (&$calls): int {
            defer(function () use (&$calls): void {
                $calls[] = 'deferred';
            });

            return Command::SUCCESS;
        }));

        $application->call('test:coroutine-disabled-events', ['--disable-event-dispatcher' => true]);

        $this->assertSame(['deferred'], $calls);
    }

    public function testCommandsWithoutDeferredCallbacksDoNotResolveTheCollection(): void
    {
        $application = $this->createConsoleApplication();
        $application->addCommand(new DeferredCommand(
            'test:no-defer-coroutine',
            fn () => Command::SUCCESS,
        ));
        $application->addCommand(new DeferredCommand(
            'test:no-defer-non-coroutine',
            fn () => Command::SUCCESS,
            coroutine: false,
        ));

        $application->call('test:no-defer-coroutine');
        $application->call('test:no-defer-non-coroutine');

        $this->assertFalse(Container::getInstance()->resolvedScoped(DeferredCallbackCollection::class));
    }

    public function testApplicationFrameDoesNotOwnCallbacksOutsideConsoleExecution(): void
    {
        $application = $this->createConsoleApplication();
        $calls = [];
        $this->app->setRunningInConsole(false);
        $application->addCommand(new DeferredCommand('test:not-console', function () use (&$calls): int {
            defer(function () use (&$calls): void {
                $calls[] = 'deferred';
            });

            return Command::SUCCESS;
        }, coroutine: false));

        $application->call('test:not-console');

        $this->assertSame([], $calls);

        $this->app->setRunningInConsole(true);
        $application->addCommand(new DeferredCommand(
            'test:next-console-owner',
            fn () => Command::SUCCESS,
            coroutine: false,
        ));
        $application->call('test:next-console-owner');

        $this->assertSame(['deferred'], $calls);
    }

    public function testNonCoroutineCommandDrainsWithSuccessAndAlwaysFiltering(): void
    {
        $application = $this->createConsoleApplication();
        $calls = [];

        $application->addCommand(new DeferredCommand('test:non-coroutine-success', function () use (&$calls): int {
            defer(function () use (&$calls): void {
                $calls[] = 'success';
            });

            return Command::SUCCESS;
        }, coroutine: false));
        $application->addCommand(new DeferredCommand('test:non-coroutine-failure', function () use (&$calls): int {
            defer(function () use (&$calls): void {
                $calls[] = 'skipped';
            });
            defer(function () use (&$calls): void {
                $calls[] = 'always';
            }, always: true);

            return Command::FAILURE;
        }, coroutine: false));

        $this->assertSame(Command::SUCCESS, $application->call('test:non-coroutine-success'));
        $this->assertSame(Command::FAILURE, $application->call('test:non-coroutine-failure'));

        $this->assertSame(['success', 'always'], $calls);
    }

    public function testPlainSymfonyCommandsDrainThroughProgrammaticAndRootExecution(): void
    {
        $programmaticApplication = $this->createConsoleApplication();
        $rootApplication = $this->createConsoleApplication();
        $calls = [];
        $programmaticApplication->addCommand(new PlainDeferredCommand('test:plain-programmatic', function () use (&$calls): int {
            defer(function () use (&$calls): void {
                $calls[] = 'programmatic';
            });

            return SymfonyCommand::SUCCESS;
        }));
        $rootApplication->addCommand(new PlainDeferredCommand('test:plain-root', function () use (&$calls): int {
            defer(function () use (&$calls): void {
                $calls[] = 'root';
            });

            return SymfonyCommand::SUCCESS;
        }));

        $this->assertSame(SymfonyCommand::SUCCESS, $programmaticApplication->call('test:plain-programmatic'));
        $this->assertSame(SymfonyCommand::SUCCESS, $rootApplication->run(
            new ArrayInput(['command' => 'test:plain-root']),
            new BufferedOutput,
        ));

        $this->assertSame(['programmatic', 'root'], $calls);
    }

    public function testNestedProgrammaticCallFromDeferredCallbackDoesNotReenterTheDrain(): void
    {
        $application = $this->createConsoleApplication();
        $calls = [];

        $application->addCommand(new DeferredCommand('test:nested-inner', function () use (&$calls): int {
            $calls[] = 'inner-handle';

            return Command::SUCCESS;
        }, coroutine: false));
        $application->addCommand(new DeferredCommand('test:nested-outer', function () use ($application, &$calls): int {
            defer(function () use ($application, &$calls): void {
                $calls[] = 'first-deferred';
                $application->call('test:nested-inner');
            });
            defer(function () use (&$calls): void {
                $calls[] = 'second-deferred';
            });

            return Command::SUCCESS;
        }, coroutine: false));

        $application->call('test:nested-outer');

        $this->assertSame(['first-deferred', 'inner-handle', 'second-deferred'], $calls);
    }

    public function testCallbackAddedDuringNonCoroutineDrainRunsAtNextOwningCall(): void
    {
        $application = $this->createConsoleApplication();
        $calls = [];

        $application->addCommand(new DeferredCommand('test:one-pass-register', function () use (&$calls): int {
            defer(function () use (&$calls): void {
                $calls[] = 'first';
                defer(function () use (&$calls): void {
                    $calls[] = 'second';
                });
            });

            return Command::SUCCESS;
        }, coroutine: false));
        $application->addCommand(new DeferredCommand(
            'test:one-pass-next',
            fn () => Command::SUCCESS,
            coroutine: false,
        ));

        $application->call('test:one-pass-register');
        $this->assertSame(['first'], $calls);
        $this->assertCount(1, $this->app->make(DeferredCallbackCollection::class));

        $application->call('test:one-pass-next');
        $this->assertSame(['first', 'second'], $calls);
        $this->assertCount(0, $this->app->make(DeferredCallbackCollection::class));
    }

    public function testCallbackAddedDuringCoroutineDrainDoesNotSurviveTheCoroutine(): void
    {
        $application = $this->createConsoleApplication();
        $calls = [];

        $application->addCommand(new DeferredCommand('test:coroutine-one-pass', function () use (&$calls): int {
            defer(function () use (&$calls): void {
                $calls[] = 'first';
                defer(function () use (&$calls): void {
                    $calls[] = 'second';
                });
            });

            return Command::SUCCESS;
        }));
        $application->addCommand(new DeferredCommand(
            'test:coroutine-next',
            fn () => Command::SUCCESS,
            coroutine: false,
        ));

        $application->call('test:coroutine-one-pass');
        $application->call('test:coroutine-next');

        $this->assertSame(['first'], $calls);
    }

    public function testApplicationOwnerRunsAlwaysCallbacksAndPreservesCommandFailure(): void
    {
        $application = $this->createConsoleApplication();
        $failure = new RuntimeException('command failed');
        $calls = [];

        $application->addCommand(new DeferredCommand('test:application-failure', function () use (&$calls, $failure): never {
            defer(function () use (&$calls): void {
                $calls[] = 'skipped';
            });
            defer(function () use (&$calls): void {
                $calls[] = 'always';
            }, always: true);

            throw $failure;
        }, coroutine: false));

        try {
            $application->call('test:application-failure');
            $this->fail('Expected the command to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame($failure, $exception);
        }

        $application->addCommand(new DeferredCommand('test:application-recovery', function () use (&$calls): int {
            defer(function () use (&$calls): void {
                $calls[] = 'recovery';
            });

            return Command::SUCCESS;
        }, coroutine: false));
        $application->call('test:application-recovery');

        $this->assertSame(['always', 'recovery'], $calls);
    }

    public function testApplicationOwnerCancellationSkipsDeferredCallbacks(): void
    {
        $application = $this->createConsoleApplication();
        $cancellation = new CanceledException;
        $calls = [];

        $application->addCommand(new PlainDeferredCommand('test:application-cancellation', function () use (&$calls, $cancellation): never {
            defer(function () use (&$calls): void {
                $calls[] = 'always';
            }, always: true);

            throw $cancellation;
        }));

        try {
            $application->call('test:application-cancellation');
            $this->fail('Expected the command to be cancelled.');
        } catch (CanceledException $exception) {
            $this->assertSame($cancellation, $exception);
        }

        $this->assertSame([], $calls);
    }

    public function testCommandOwnerCancellationSkipsDeferredCallbacks(): void
    {
        $application = $this->createConsoleApplication();
        $cancellation = new CanceledException;
        $calls = [];

        $application->addCommand(new DeferredCommand('test:command-cancellation', function () use (&$calls, $cancellation): never {
            defer(function () use (&$calls): void {
                $calls[] = 'always';
            }, always: true);

            throw $cancellation;
        }));

        try {
            $application->call('test:command-cancellation');
            $this->fail('Expected the command to be cancelled.');
        } catch (CanceledException $exception) {
            $this->assertSame($cancellation, $exception);
        }

        $this->assertSame([], $calls);
    }

    public function testApplicationOwnerPreservesEarlierFailureWhenDrainAlsoFails(): void
    {
        $application = $this->createConsoleApplication();
        $commandFailure = new RuntimeException('command failed');
        $drainFailure = new RuntimeException('drain failed');
        $this->app->scoped(
            DeferredCallbackCollection::class,
            fn () => new ThrowingDeferredCallbackCollection($drainFailure),
        );

        $application->addCommand(new DeferredCommand('test:application-drain-failure', function () use ($commandFailure): never {
            defer(fn () => null);

            throw $commandFailure;
        }, coroutine: false));

        try {
            $application->call('test:application-drain-failure');
            $this->fail('Expected the command to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame($commandFailure, $exception);
        }
    }

    public function testCommandOwnerPreservesEarlierFailureWhenDrainAlsoFails(): void
    {
        $application = $this->createConsoleApplication();
        $commandFailure = new RuntimeException('command failed');
        $drainFailure = new RuntimeException('drain failed');
        $this->app->scoped(
            DeferredCallbackCollection::class,
            fn () => new ThrowingDeferredCallbackCollection($drainFailure),
        );

        $application->addCommand(new DeferredCommand('test:command-drain-failure', function () use ($commandFailure): never {
            defer(fn () => null);

            throw $commandFailure;
        }));

        try {
            $application->call('test:command-drain-failure');
            $this->fail('Expected the command to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame($commandFailure, $exception);
        }
    }

    private function createConsoleApplication(): ConsoleApplication
    {
        return new ConsoleApplication(
            $this->app,
            $this->app->make('events'),
            '1.0',
        );
    }
}

class DeferredCommand extends Command
{
    public function __construct(
        string $name,
        private readonly Closure $callback,
        bool $coroutine = true,
    ) {
        $this->coroutine = $coroutine;

        parent::__construct($name);
    }

    public function handle(): int
    {
        return ($this->callback)();
    }
}

class IsolatableDeferredCommand extends DeferredCommand implements Isolatable
{
}

class PlainDeferredCommand extends SymfonyCommand
{
    public function __construct(string $name, private readonly Closure $callback)
    {
        parent::__construct($name);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return ($this->callback)();
    }
}

class ThrowingDeferredCallbackCollection extends DeferredCallbackCollection
{
    public function __construct(private readonly RuntimeException $exception)
    {
    }

    public function invokeWhen(?Closure $when = null): void
    {
        throw $this->exception;
    }
}
