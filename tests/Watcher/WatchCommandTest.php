<?php

declare(strict_types=1);

namespace Hypervel\Tests\Watcher;

use Hypervel\Config\Repository;
use Hypervel\Foundation\Application;
use Hypervel\Testbench\TestCase;
use Hypervel\Watcher\Console\WatchCommand;
use Hypervel\Watcher\Option;
use Hypervel\Watcher\Watcher;
use Mockery as m;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

/**
 * @internal
 * @coversNothing
 */
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
        $config = new Repository([
            'watcher' => [
                'driver' => 'scan',
                'watch' => [
                    'scan_interval' => 1000,
                ],
            ],
        ]);

        $watcher = m::mock(Watcher::class);
        $watcher->shouldReceive('run')->once();

        $this->app->instance('config', $config);
        $this->app->bind(Option::class, function ($app, array $parameters) use ($config) {
            if ($parameters['options']['driver'] !== 'scan'
                || $parameters['dir'] !== []
                || $parameters['file'] !== []
                || $parameters['restart'] !== true) {
                throw new RuntimeException('Unexpected watch command option parameters.');
            }

            return new Option($config->get('watcher'), [], []);
        });
        $this->app->bind(Watcher::class, function ($app, array $parameters) use ($watcher) {
            if (! isset($parameters['option'], $parameters['output'])) {
                throw new RuntimeException('Watcher command did not pass the expected watcher parameters.');
            }

            return $watcher;
        });

        $command = new WatchCommand($this->app);
        $command->setHypervel($this->app);

        Application::getInstance()->setRunningInConsole(false);

        $result = $command->run(new ArrayInput([]), new NullOutput);

        $this->assertSame(0, $result);
    }
}
