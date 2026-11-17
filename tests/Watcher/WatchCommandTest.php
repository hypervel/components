<?php

declare(strict_types=1);

namespace Hypervel\Tests\Watcher;

use Closure;
use Hypervel\Config\Repository;
use Hypervel\Container\Container;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Testbench\TestCase;
use Hypervel\Watcher\Console\WatchCommand;
use Hypervel\Watcher\Driver\DriverInterface;
use Hypervel\Watcher\Driver\ScanFileDriver;
use Hypervel\Watcher\Option;
use Hypervel\Watcher\ServerRestartStrategy;
use Hypervel\Watcher\Watcher;
use Hypervel\Watcher\WatchPath;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use RuntimeException;
use Symfony\Component\Console\Exception\RuntimeException as ConsoleRuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\NullOutput;

class WatchCommandTest extends TestCase
{
    use VerifiesDoubles;

    protected function tearDown(): void
    {
        putenv('APP_RUNNING_IN_CONSOLE');

        parent::tearDown();
    }

    public function testWatchCommandFailsFastWhenRunningInConsoleIsTrue(): void
    {
        $container = new Container;
        $application = Double::for(ApplicationContract::class, override: true)->strict();
        $container->instance(ApplicationContract::class, $application->instance());
        $application->expects('runningInConsole')->returns(true);

        $command = new WatchCommand($container);
        $command->setHypervel($this->app);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('Error: APP_RUNNING_IN_CONSOLE is true. Your artisan binary may be outdated. Please update it so the serve and watch commands set APP_RUNNING_IN_CONSOLE=false before the server starts.');

        $command->run(new ArrayInput([]), new NullOutput);
    }

    public function testWatchCommandRunsWatcherWhenRunningInConsoleIsFalse(): void
    {
        $this->app->instance('config', new Repository([
            'watcher' => [
                'driver' => ScanFileDriver::class,
                'scan_interval' => 1000,
                'watch' => ['app/**/*.php', '.env'],
            ],
        ]));

        $watcher = Double::for(Watcher::class)->strict();
        $watcher->expects('run');

        $this->app->bind(ScanFileDriver::class, function ($app, array $parameters) {
            $this->assertInstanceOf(Option::class, $parameters['option']);

            return Double::for(DriverInterface::class)->strict();
        });
        $this->app->bind(ServerRestartStrategy::class, function () {
            return Double::for(ServerRestartStrategy::class)->strict();
        });
        $this->app->bind(Watcher::class, function ($app, array $parameters) use ($watcher) {
            $this->assertInstanceOf(DriverInterface::class, $parameters['driver']);
            $this->assertInstanceOf(ServerRestartStrategy::class, $parameters['strategy']);
            $this->assertNotNull($parameters['output']);

            return $watcher;
        });

        $command = new WatchCommand($this->app);
        $command->setHypervel($this->app);

        $this->app->setRunningInConsole(false);

        $result = $command->run(new ArrayInput([]), new NullOutput);

        $this->assertSame(0, $result);
    }

    public function testWatchCommandWithNoRestartPassesNullStrategy(): void
    {
        $this->app->instance('config', new Repository([
            'watcher' => [
                'driver' => ScanFileDriver::class,
                'scan_interval' => 1000,
                'watch' => ['app/**/*.php'],
            ],
        ]));

        $watcher = Double::for(Watcher::class)->strict();
        $watcher->expects('run');

        $this->app->bind(ScanFileDriver::class, function () {
            return Double::for(DriverInterface::class)->strict();
        });
        $this->app->bind(Watcher::class, function ($app, array $parameters) use ($watcher) {
            $this->assertNull($parameters['strategy']);

            return $watcher;
        });

        $command = new WatchCommand($this->app);
        $command->setHypervel($this->app);

        $this->app->setRunningInConsole(false);

        $result = $command->run(new ArrayInput(['--no-restart' => true]), new NullOutput);

        $this->assertSame(0, $result);
    }

    public function testTerminationSignalStopsDriverBeforeRestartStrategy(): void
    {
        $driver = Double::for(DriverInterface::class)->strict();
        $strategy = Double::for(ServerRestartStrategy::class)->strict();
        $stops = [];
        $driver->expects('stop')->resolves(function () use (&$stops): void {
            $stops[] = 'driver';
        });
        $strategy->expects('stop')->resolves(function () use (&$stops): void {
            $stops[] = 'strategy';
        });

        $command = $this->runSignalCapturingCommand($driver, $strategy);

        $this->assertSame([SIGINT, SIGTERM, SIGQUIT], $command->signals);

        $command->invokeSignalHandler(SIGTERM);

        $this->assertSame(['driver', 'strategy'], $stops);
    }

    public function testTerminationSignalStopsRestartStrategyWhenDriverCleanupFails(): void
    {
        $failure = new RuntimeException('driver cleanup failed');
        $driver = Double::for(DriverInterface::class)->strict();
        $strategy = Double::for(ServerRestartStrategy::class)->strict();
        $driver->expects('stop')->throws($failure);
        $strategy->expects('stop');

        $command = $this->runSignalCapturingCommand($driver, $strategy);

        try {
            $command->invokeSignalHandler(SIGTERM);
            $this->fail('Expected driver cleanup to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame($failure, $exception);
        }
    }

    public function testWatchCommandWithExtraPaths(): void
    {
        $this->app->instance('config', new Repository([
            'watcher' => [
                'driver' => ScanFileDriver::class,
                'scan_interval' => 1000,
                'watch' => ['app/**/*.php'],
            ],
        ]));

        $watcher = Double::for(Watcher::class)->strict();
        $watcher->expects('run');

        $capturedOption = null;
        $this->app->bind(ScanFileDriver::class, function ($app, array $parameters) use (&$capturedOption) {
            $capturedOption = $parameters['option'];

            return Double::for(DriverInterface::class)->strict();
        });
        $this->app->bind(ServerRestartStrategy::class, function () {
            return Double::for(ServerRestartStrategy::class)->strict();
        });
        $this->app->bind(Watcher::class, function () use ($watcher) {
            return $watcher;
        });

        $command = new WatchCommand($this->app);
        $command->setHypervel($this->app);

        $this->app->setRunningInConsole(false);

        $result = $command->run(
            new ArrayInput(['--path' => ['.env', 'composer.json']]),
            new NullOutput,
        );

        $this->assertSame(0, $result);
        $this->assertInstanceOf(Option::class, $capturedOption);

        $paths = $capturedOption->getWatchPaths();
        $pathStrings = array_map(fn (WatchPath $path): string => $path->path, $paths);
        $this->assertContains('.env', $pathStrings);
        $this->assertContains('composer.json', $pathStrings);

        // .env and composer.json should be File type (they're not directories)
        $filePaths = $capturedOption->getFilePaths();
        $filePathStrings = array_map(fn (WatchPath $path): string => $path->path, $filePaths);
        $this->assertContains('.env', $filePathStrings);
        $this->assertContains('composer.json', $filePathStrings);
    }

    public function testPathOptionRequiresAValue(): void
    {
        $command = new WatchCommand($this->app);
        $input = new StringInput('--path');

        $this->expectException(ConsoleRuntimeException::class);
        $this->expectExceptionMessageIs('The "--path" option requires a value.');

        $input->bind($command->getDefinition());
    }

    /**
     * Run a watch command that captures its termination signal handler.
     */
    protected function runSignalCapturingCommand(
        DriverInterface $driver,
        ServerRestartStrategy $strategy,
    ): SignalCapturingWatchCommand {
        $this->app->instance('config', new Repository([
            'watcher' => [
                'driver' => ScanFileDriver::class,
                'scan_interval' => 1000,
                'watch' => ['app/**/*.php'],
            ],
        ]));

        $watcher = Double::for(Watcher::class)->strict();
        $watcher->expects('run');

        $this->app->bind(ScanFileDriver::class, fn () => $driver);
        $this->app->bind(ServerRestartStrategy::class, fn () => $strategy);
        $this->app->bind(Watcher::class, fn () => $watcher);

        $command = new SignalCapturingWatchCommand($this->app);
        $command->setHypervel($this->app);
        $this->app->setRunningInConsole(false);

        $this->assertSame(0, $command->run(new ArrayInput([]), new NullOutput));

        return $command;
    }
}

class SignalCapturingWatchCommand extends WatchCommand
{
    /** @var int[] */
    public array $signals = [];

    public Closure $signalHandler;

    /**
     * Capture the command's signal handler.
     */
    public function trap(Closure|int|iterable $signals, callable $callback): void
    {
        $signals = value($signals);
        $this->signals = is_int($signals) ? [$signals] : [...$signals];
        $this->signalHandler = Closure::fromCallable($callback);
    }

    public function invokeSignalHandler(int $signal): void
    {
        ($this->signalHandler)($signal);
    }
}
