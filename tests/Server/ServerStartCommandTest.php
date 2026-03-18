<?php

declare(strict_types=1);

namespace Hypervel\Tests\Server;

use Hypervel\Contracts\Config\Repository;
use Hypervel\Contracts\Events\Dispatcher as DispatcherContract;
use Hypervel\Contracts\Log\StdoutLoggerInterface;
use Hypervel\Foundation\Application;
use Hypervel\Server\Commands\ServerStartCommand;
use Hypervel\Server\ServerFactory;
use Hypervel\Testbench\TestCase;
use Mockery as m;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

/**
 * @internal
 * @coversNothing
 */
class ServerStartCommandTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('APP_RUNNING_IN_CONSOLE');

        parent::tearDown();
    }

    public function testServeCommandFailsFastWhenRunningInConsoleIsTrue()
    {
        $command = new ServerStartCommand($this->app);
        $command->setHypervel($this->app);

        Application::getInstance()->setRunningInConsole(true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Error: APP_RUNNING_IN_CONSOLE is true. Your artisan binary may be outdated. Please update it so the serve and watch commands set APP_RUNNING_IN_CONSOLE=false before the server starts.');

        $command->run(new ArrayInput(['--disable-event-dispatcher' => true]), new NullOutput());
    }

    public function testServeCommandStartsServerWhenRunningInConsoleIsFalse()
    {
        $serverFactory = m::mock(ServerFactory::class);
        $serverFactory->shouldReceive('setEventDispatcher')->once()->andReturnSelf();
        $serverFactory->shouldReceive('setLogger')->once()->andReturnSelf();
        $serverFactory->shouldReceive('configure')->once()->with(['http' => ['port' => 9501]]);
        $serverFactory->shouldReceive('start')->once();

        $config = m::mock(Repository::class);
        $config->shouldReceive('get')->once()->with('server', [])->andReturn(['http' => ['port' => 9501]]);

        $dispatcher = m::mock(DispatcherContract::class);
        $logger = m::mock(StdoutLoggerInterface::class);

        $this->app->instance(ServerFactory::class, $serverFactory);
        $this->app->instance(DispatcherContract::class, $dispatcher);
        $this->app->instance(StdoutLoggerInterface::class, $logger);
        $this->app->instance(Repository::class, $config);

        $command = new ServerStartCommand($this->app);
        $command->setHypervel($this->app);

        Application::getInstance()->setRunningInConsole(false);

        $result = $command->run(new ArrayInput(['--disable-event-dispatcher' => true]), new NullOutput());

        $this->assertSame(0, $result);
    }
}
