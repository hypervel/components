<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Console;

use Composer\Autoload\ClassLoader;
use Hypervel\Console\Application as ConsoleApplication;
use Hypervel\Console\Command;
use Hypervel\Console\Scheduling\CacheEventMutex;
use Hypervel\Console\Scheduling\CacheSchedulingMutex;
use Hypervel\Contracts\Console\Application as ConsoleApplicationContract;
use Hypervel\Contracts\Console\Kernel as KernelContract;
use Hypervel\Contracts\Debug\ExceptionHandler as ExceptionHandlerContract;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Events\Dispatcher;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Foundation\Application;
use Hypervel\Foundation\Bootstrap\BootProviders;
use Hypervel\Foundation\Console\Kernel;
use Hypervel\Foundation\Events\Terminating;
use Hypervel\Testbench\Attributes\DefineEnvironment;
use Hypervel\Testbench\TestCase;
use Mockery as m;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use Swoole\Coroutine\CanceledException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class KernelTest extends TestCase
{
    protected function useTokyoApplicationTimezone(ApplicationContract $app): void
    {
        $app->make('config')->set('app.timezone', 'Asia/Tokyo');
    }

    public function testHandleCatchesExceptionsAndReturnsOne(): void
    {
        $handler = m::mock(ExceptionHandlerContract::class);
        $handler->shouldReceive('report')->once();
        $handler->shouldReceive('renderForConsole')->once();
        $this->app->instance(ExceptionHandlerContract::class, $handler);

        $kernel = new class($this->app, $this->app->make('events')) extends Kernel {
            protected function bootstrappers(): array
            {
                return [];
            }

            public function bootstrap(): void
            {
                // Throw during bootstrap to trigger the catch block.
                throw new RuntimeException('Bootstrap failed');
            }
        };

        $result = $kernel->handle(new StringInput(''), new BufferedOutput);

        $this->assertSame(1, $result);
    }

    public function testHandleRetainsTheOriginalExceptionWhenReportingFails(): void
    {
        $original = new RuntimeException('Bootstrap failed');
        $reportingFailure = new RuntimeException('Reporting failed');

        $handler = m::mock(ExceptionHandlerContract::class);
        $handler->shouldReceive('report')->once()->with($original)->andThrow($reportingFailure);
        $handler->shouldNotReceive('renderForConsole');
        $this->app->instance(ExceptionHandlerContract::class, $handler);

        $kernel = new class($this->app, $this->app->make('events'), $original) extends Kernel {
            public function __construct(Application $app, Dispatcher $events, private readonly RuntimeException $exception)
            {
                parent::__construct($app, $events);
            }

            protected function bootstrappers(): array
            {
                return [];
            }

            public function bootstrap(): void
            {
                throw $this->exception;
            }
        };

        try {
            $kernel->handle(new StringInput(''), new BufferedOutput);
            $this->fail('Expected exception reporting to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame($reportingFailure, $exception);
            $this->assertSame($original, $exception->getPrevious());
        }
    }

    public function testHandlePreservesCancellationWithoutReportingOrRenderingIt(): void
    {
        $cancellation = new CanceledException('canceled');
        $handler = m::mock(ExceptionHandlerContract::class);
        $handler->shouldNotReceive('report', 'renderForConsole');
        $this->app->instance(ExceptionHandlerContract::class, $handler);

        $kernel = new class($this->app, $this->app->make('events'), $cancellation) extends Kernel {
            public function __construct(Application $app, Dispatcher $events, private readonly CanceledException $cancellation)
            {
                parent::__construct($app, $events);
            }

            protected function bootstrappers(): array
            {
                return [];
            }

            public function bootstrap(): void
            {
                throw $this->cancellation;
            }
        };

        try {
            $kernel->handle(new StringInput(''), new BufferedOutput);
            $this->fail('Expected console handling to preserve cancellation.');
        } catch (CanceledException $exception) {
            $this->assertSame($cancellation, $exception);
        }
    }

    public function testBootstrapWithoutBootingProvidersSkipsBootProviders(): void
    {
        $bootstrappedWith = null;

        $kernel = $this->app->make(KernelContract::class);

        // Replace the app with a spy that captures what bootstrappers are used.
        $app = m::mock($this->app)->makePartial();
        $app->shouldReceive('bootstrapWith')->once()->with(m::on(function (array $bootstrappers) use (&$bootstrappedWith) {
            $bootstrappedWith = $bootstrappers;
            return true;
        }));

        // Use reflection to replace the app on the kernel.
        $reflection = new ReflectionProperty($kernel, 'app');
        $reflection->setValue($kernel, $app);

        $kernel->bootstrapWithoutBootingProviders();

        $this->assertNotNull($bootstrappedWith);
        $this->assertNotContains(BootProviders::class, $bootstrappedWith);
    }

    #[DefineEnvironment('useTokyoApplicationTimezone')]
    public function testMissingScheduleTimezoneUsesTheApplicationTimezone(): void
    {
        $event = $this->app->make(KernelContract::class)
            ->resolveConsoleSchedule()
            ->call(static fn (): null => null);

        $this->assertSame('Asia/Tokyo', $event->nextRunDate()->getTimezone()->getName());
    }

    public function testNullScheduleCacheUsesTheDefaultStoreForBothMutexes(): void
    {
        $this->app->make('config')->set('cache.schedule_store', null);

        $this->app->make(KernelContract::class)->resolveConsoleSchedule();

        $this->assertNull($this->app->make(CacheEventMutex::class)->store);
        $this->assertNull($this->app->make(CacheSchedulingMutex::class)->store);
    }

    public function testConfiguredScheduleCacheUsesTheSelectedStoreForBothMutexes(): void
    {
        $this->app->make('config')->set('cache.schedule_store', 'scheduling');

        $this->app->make(KernelContract::class)->resolveConsoleSchedule();

        $this->assertSame('scheduling', $this->app->make(CacheEventMutex::class)->store);
        $this->assertSame('scheduling', $this->app->make(CacheSchedulingMutex::class)->store);
    }

    public function testLoadIgnoresTestFiles(): void
    {
        $files = new Filesystem;
        $directory = $this->app->path('Console/Commands/Discovery');
        $loader = new ClassLoader;
        $loader->addPsr4('App\Console\Commands\Discovery\\', $directory);

        try {
            $files->ensureDirectoryExists($directory);
            $files->put($directory . '/ExampleCommand.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Console\Commands\Discovery;

use Hypervel\Console\Command;

class ExampleCommand extends Command
{
    protected ?string $signature = 'example';

    public function handle(): void {}
}
PHP);
            $files->put($directory . '/ExampleCommandTest.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Console\Commands\Discovery;

use Hypervel\Console\Command;

class ExampleCommandTest extends Command
{
    protected ?string $signature = 'example-test';

    public function handle(): void {}
}
PHP);
            $files->put($directory . '/ExampleCommandUnitTest.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Console\Commands\Discovery;

use Hypervel\Tests\TestCase;

class ExampleCommandUnitTest extends TestCase
{
    public function testCommand(): void
    {
        $this->assertTrue(true);
    }
}
PHP);
            $loader->register();

            $kernel = new Kernel($this->app, $this->app->make('events'));
            $kernel->addCommandPaths([$directory]);

            $commands = collect($kernel->getArtisan()->all())
                ->map(static fn (SymfonyCommand $command): string => $command::class)->all();

            $this->assertContains('App\Console\Commands\Discovery\ExampleCommand', $commands);
            $this->assertContains('App\Console\Commands\Discovery\ExampleCommandTest', $commands);
            $this->assertNotContains('App\Console\Commands\Discovery\ExampleCommandUnitTest', $commands);
        } finally {
            $loader->unregister();
            $files->deleteDirectory($directory);
        }
    }

    public function testSetArtisanSynchronizesTheKernelAndContainerBeforeReboundCallbacks(): void
    {
        $kernel = $this->app->make(KernelContract::class);
        $kernel->getArtisan();
        $reboundApplication = null;

        $this->app->rebinding(ConsoleApplicationContract::class, function () use ($kernel, &$reboundApplication): void {
            $reboundApplication = $kernel->getArtisan();
        });

        $replacement = new ConsoleApplication($this->app, $this->app->make('events'), $this->app->version());
        $kernel->setArtisan($replacement);

        $this->assertSame($replacement, $kernel->getArtisan());
        $this->assertSame($replacement, $this->app->make(ConsoleApplicationContract::class));
        $this->assertSame($replacement, $reboundApplication);
    }

    public function testClearingArtisanPreservesTheBindingAndLazilyBuildsAFreshApplication(): void
    {
        $kernel = $this->app->make(KernelContract::class);
        $first = $this->app->make(ConsoleApplicationContract::class);
        $resolvedFreshApplication = false;

        $this->app->resolving(ConsoleApplicationContract::class, function () use (&$resolvedFreshApplication): void {
            $resolvedFreshApplication = true;
        });

        $kernel->setArtisan(null);

        $this->assertFalse($resolvedFreshApplication);
        $this->assertTrue($this->app->resolved(ConsoleApplicationContract::class));

        $fresh = $this->app->make(ConsoleApplicationContract::class);

        $this->assertTrue($resolvedFreshApplication);
        $this->assertNotSame($first, $fresh);
        $this->assertSame($fresh, $kernel->getArtisan());
    }

    public function testClearingDirectlyConstructedArtisanLetsTheNextAccessRebuildIt(): void
    {
        $kernel = $this->app->make(KernelContract::class);
        $first = $kernel->getArtisan();

        $kernel->setArtisan(null);

        $this->assertFalse($this->app->resolved(ConsoleApplicationContract::class));

        $fresh = $kernel->getArtisan();

        $this->assertNotSame($first, $fresh);
        $this->assertSame($fresh, $this->app->make(ConsoleApplicationContract::class));
    }

    public function testReportExceptionDelegatesToExceptionHandler(): void
    {
        $exception = new RuntimeException('Test exception');

        $handler = m::mock(ExceptionHandlerContract::class);
        $handler->shouldReceive('report')->once()->with($exception);
        $this->app->instance(ExceptionHandlerContract::class, $handler);

        $kernel = new Kernel($this->app, $this->app->make('events'));

        $method = new ReflectionMethod($kernel, 'reportException');
        $method->invoke($kernel, $exception);
    }

    public function testRenderExceptionDelegatesToExceptionHandler(): void
    {
        $exception = new RuntimeException('Test exception');
        $output = new BufferedOutput;

        $handler = m::mock(ExceptionHandlerContract::class);
        $handler->shouldReceive('renderForConsole')->once()->with($output, $exception);
        $this->app->instance(ExceptionHandlerContract::class, $handler);

        $kernel = new Kernel($this->app, $this->app->make('events'));

        $method = new ReflectionMethod($kernel, 'renderException');
        $method->invoke($kernel, $output, $exception);
    }

    public function testRenderThrowableUsesConsoleErrorOutput(): void
    {
        $exception = new RuntimeException('Test exception');
        $output = new ConsoleOutput;
        $errorOutput = new BufferedOutput;
        $output->setErrorOutput($errorOutput);

        $handler = m::mock(ExceptionHandlerContract::class);
        $handler->shouldReceive('renderForConsole')->once()->with($errorOutput, $exception);
        $this->app->instance(ExceptionHandlerContract::class, $handler);

        $kernel = new Kernel($this->app, $this->app->make('events'));

        $method = new ReflectionMethod($kernel, 'renderException');
        $method->invoke($kernel, $output, $exception);
    }

    public function testHandleWithNullOutputPreservesOriginalThrowable(): void
    {
        $reportedException = null;

        $handler = m::mock(ExceptionHandlerContract::class);
        $handler->shouldReceive('report')->once()->andReturnUsing(function (RuntimeException $exception) use (&$reportedException): void {
            $reportedException = $exception;
        });
        $handler->shouldReceive('renderForConsole')->once()->with(
            m::on(fn (OutputInterface $output): bool => ! $output instanceof ConsoleOutputInterface),
            m::on(function (RuntimeException $exception) use (&$reportedException): bool {
                return $exception === $reportedException && $exception->getMessage() === 'Bootstrap failed';
            }),
        );
        $this->app->instance(ExceptionHandlerContract::class, $handler);

        $kernel = new class($this->app, $this->app->make('events')) extends Kernel {
            protected function bootstrappers(): array
            {
                return [];
            }

            public function bootstrap(): void
            {
                throw new RuntimeException('Bootstrap failed');
            }
        };

        $this->assertSame(1, $kernel->handle(new StringInput('')));
    }

    public function testHandleRebindsInputAfterPreBootstrapCommandResolution(): void
    {
        $kernel = $this->app->make(KernelContract::class);
        $kernel->registerCommand(new KernelPreboundInputCommand);
        $input = new ArgvInput([
            'artisan',
            'test:prebound-input',
            '--value=configured',
        ]);
        $output = new BufferedOutput;

        $this->assertSame(
            'test:prebound-input',
            ConsoleApplication::resolveCommandName($input),
        );

        $status = $kernel->handle($input, $output);
        $kernel->terminate($input, $status);

        $this->assertSame(0, $status);
        $this->assertSame('configured', trim($output->fetch()));
    }

    public function testItDispatchesTerminatingEvent(): void
    {
        $called = [];
        $app = new Application;
        $events = new Dispatcher($app);
        $app->instance('events', $events);
        $kernel = new Kernel($app, $events);
        $events->listen(function (Terminating $terminating) use (&$called) {
            $called[] = 'terminating event';
        });
        $app->terminating(function () use (&$called) {
            $called[] = 'terminating callback';
        });

        $kernel->terminate(new StringInput('tinker'), 0);

        $this->assertSame([
            'terminating event',
            'terminating callback',
        ], $called);
    }

    public function testFindCommandReturnsNullWhenTheCommandDoesNotExist(): void
    {
        $kernel = $this->makeKernel();

        $command = $kernel->findCommand('not-a-real-command');

        $this->assertNull($command);
    }

    public function testFindCommandRetrievesTheCommand(): void
    {
        $kernel = $this->makeKernel();
        $kernel->registerCommand(new KernelTestCommand);

        $command = $kernel->findCommand('kernel-test-command');

        $this->assertInstanceOf(KernelTestCommand::class, $command);
    }

    public function testFindCommandDoesNotResolveOtherLazilyRegisteredCommands(): void
    {
        KernelTestLazyCommand::$constructionAttempts = 0;
        $kernel = $this->makeKernel();
        $artisan = $kernel->getArtisan();
        $artisan->resolveCommands([KernelTestLazyCommand::class]);
        $artisan->setContainerCommandLoader();

        $kernel->registerCommand(new KernelTestCommand);

        $command = $kernel->findCommand('kernel-test-command');

        $this->assertInstanceOf(KernelTestCommand::class, $command);
        $this->assertSame(0, KernelTestLazyCommand::$constructionAttempts);

        $command = $kernel->findCommand('kernel-test-lazy-command');
        $this->assertSame(1, KernelTestLazyCommand::$constructionAttempts);
        $this->assertInstanceOf(KernelTestLazyCommand::class, $command);
    }

    public function testFindCommandReturnsTheSameInstanceOnSubsequentCalls(): void
    {
        KernelTestLazyCommand::$constructionAttempts = 0;
        $kernel = $this->makeKernel();
        $artisan = $kernel->getArtisan();
        $artisan->resolveCommands([KernelTestLazyCommand::class]);
        $artisan->setContainerCommandLoader();

        $first = $kernel->findCommand('kernel-test-lazy-command');
        $second = $kernel->findCommand('kernel-test-lazy-command');

        $this->assertSame($first, $second);
        $this->assertSame(1, KernelTestLazyCommand::$constructionAttempts);
    }

    /**
     * Create a console kernel.
     */
    protected function makeKernel(): Kernel
    {
        return new Kernel($this->app, $this->app->make('events'));
    }
}

class KernelTestCommand extends Command
{
    protected ?string $signature = 'kernel-test-command';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
    }
}

#[AsCommand(name: 'kernel-test-lazy-command')]
class KernelTestLazyCommand extends Command
{
    public static int $constructionAttempts = 0;

    /**
     * Create a new command instance.
     */
    public function __construct()
    {
        parent::__construct();

        ++static::$constructionAttempts;
    }

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
    }
}

class KernelPreboundInputCommand extends Command
{
    protected ?string $signature = 'test:prebound-input {--value=}';

    public function handle(): void
    {
        $this->line((string) $this->option('value'));
    }
}
