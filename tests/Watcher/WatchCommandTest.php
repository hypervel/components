<?php

declare(strict_types=1);

namespace Hypervel\Tests\Watcher;

use Closure;
use Hypervel\Config\Repository;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Testbench\TestCase;
use Hypervel\Watcher\Console\WatchCommand;
use Hypervel\Watcher\Driver\DriverInterface;
use Hypervel\Watcher\Driver\ScanFileDriver;
use Hypervel\Watcher\Option;
use Hypervel\Watcher\ServerRestartStrategy;
use Hypervel\Watcher\Watcher;
use Hypervel\Watcher\WatchPath;
use Mockery as m;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

class WatchCommandTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('APP_RUNNING_IN_CONSOLE');

        parent::tearDown();
    }

    public function testWatchCommandFailsFastWhenRunningInConsoleIsTrue(): void
    {
        $container = m::mock(Container::class);
        $application = m::mock(ApplicationContract::class);
        $container->shouldReceive('make')->with(ApplicationContract::class)->once()->andReturn($application);
        $application->shouldReceive('runningInConsole')->once()->andReturnTrue();

        $command = new WatchCommand($container);
        $command->setHypervel($this->app);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Error: APP_RUNNING_IN_CONSOLE is true. Your artisan binary may be outdated. Please update it so the serve and watch commands set APP_RUNNING_IN_CONSOLE=false before the server starts.');

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

        $watcher = m::mock(Watcher::class);
        $watcher->shouldReceive('run')->once();

        $this->app->bind(ScanFileDriver::class, function ($app, array $parameters) {
            $this->assertInstanceOf(Option::class, $parameters['option']);

            return m::mock(DriverInterface::class);
        });
        $this->app->bind(ServerRestartStrategy::class, function () {
            return m::mock(ServerRestartStrategy::class);
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

        $watcher = m::mock(Watcher::class);
        $watcher->shouldReceive('run')->once();

        $this->app->bind(ScanFileDriver::class, function () {
            return m::mock(DriverInterface::class);
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
        $driver = m::mock(DriverInterface::class);
        $strategy = m::mock(ServerRestartStrategy::class);
        $driver->shouldReceive('stop')->once()->ordered();
        $strategy->shouldReceive('stop')->once()->ordered();

        $command = $this->runSignalCapturingCommand($driver, $strategy);

        $this->assertSame([SIGINT, SIGTERM, SIGQUIT], $command->signals);

        $command->invokeSignalHandler(SIGTERM);
    }

    public function testTerminationSignalStopsRestartStrategyWhenDriverCleanupFails(): void
    {
        $failure = new RuntimeException('driver cleanup failed');
        $driver = m::mock(DriverInterface::class);
        $strategy = m::mock(ServerRestartStrategy::class);
        $driver->shouldReceive('stop')->once()->andThrow($failure);
        $strategy->shouldReceive('stop')->once();

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

        $watcher = m::mock(Watcher::class);
        $watcher->shouldReceive('run')->once();

        $capturedOption = null;
        $this->app->bind(ScanFileDriver::class, function ($app, array $parameters) use (&$capturedOption) {
            $capturedOption = $parameters['option'];

            return m::mock(DriverInterface::class);
        });
        $this->app->bind(ServerRestartStrategy::class, function () {
            return m::mock(ServerRestartStrategy::class);
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

        $watcher = m::mock(Watcher::class);
        $watcher->shouldReceive('run')->once();

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

    public function trap(array|int $signo, callable $callback): void
    {
        $this->signals = (array) $signo;
        $this->signalHandler = Closure::fromCallable($callback);
    }

    public function invokeSignalHandler(int $signal): void
    {
        ($this->signalHandler)($signal);
    }
}
