<?php

declare(strict_types=1);

namespace Hypervel\Tests\Watcher;

use Hypervel\Config\Repository;
use Hypervel\Foundation\Application;
use Hypervel\Testbench\TestCase;
use Hypervel\Watcher\Console\WatchCommand;
use Hypervel\Watcher\Driver\DriverInterface;
use Hypervel\Watcher\Driver\ScanFileDriver;
use Hypervel\Watcher\Option;
use Hypervel\Watcher\ServerRestartStrategy;
use Hypervel\Watcher\Watcher;
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

    public function testWatchCommandFailsFastWhenRunningInConsoleIsTrue()
    {
        $command = new WatchCommand($this->app);
        $command->setHypervel($this->app);

        Application::getInstance()->setRunningInConsole(true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Error: APP_RUNNING_IN_CONSOLE is true. Your artisan binary may be outdated. Please update it so the serve and watch commands set APP_RUNNING_IN_CONSOLE=false before the server starts.');

        $command->run(new ArrayInput([]), new NullOutput);
    }

    public function testWatchCommandRunsWatcherWhenRunningInConsoleIsFalse()
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

        Application::getInstance()->setRunningInConsole(false);

        $result = $command->run(new ArrayInput([]), new NullOutput);

        $this->assertSame(0, $result);
    }

    public function testWatchCommandWithNoRestartPassesNullStrategy()
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

        Application::getInstance()->setRunningInConsole(false);

        $result = $command->run(new ArrayInput(['--no-restart' => true]), new NullOutput);

        $this->assertSame(0, $result);
    }

    public function testWatchCommandWithExtraPaths()
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

        Application::getInstance()->setRunningInConsole(false);

        $result = $command->run(
            new ArrayInput(['--path' => ['.env', 'composer.json']]),
            new NullOutput,
        );

        $this->assertSame(0, $result);
        $this->assertInstanceOf(Option::class, $capturedOption);

        $paths = $capturedOption->getWatchPaths();
        $pathStrings = array_map(fn ($p) => $p->path, $paths);
        $this->assertContains('.env', $pathStrings);
        $this->assertContains('composer.json', $pathStrings);

        // .env and composer.json should be File type (they're not directories)
        $filePaths = $capturedOption->getFilePaths();
        $filePathStrings = array_map(fn ($p) => $p->path, $filePaths);
        $this->assertContains('.env', $filePathStrings);
        $this->assertContains('composer.json', $filePathStrings);
    }
}
