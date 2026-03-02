<?php

declare(strict_types=1);

namespace Hypervel\Tests\Signal;

use Hypervel\Config\Repository;
use Hypervel\Contracts\Container\Container as ContainerContract;
use Hypervel\Contracts\Signal\SignalHandlerInterface;
use Hypervel\Signal\WorkerStopHandler;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Swoole\Server;

/**
 * @internal
 * @coversNothing
 */
class WorkerStopHandlerTest extends TestCase
{
    public function testImplementsSignalHandlerInterface()
    {
        $container = m::mock(ContainerContract::class);
        $handler = new WorkerStopHandler($container);

        $this->assertInstanceOf(SignalHandlerInterface::class, $handler);
    }

    public function testListensForSigtermAndSigintOnWorker()
    {
        $container = m::mock(ContainerContract::class);
        $handler = new WorkerStopHandler($container);
        $signals = $handler->listen();

        $this->assertCount(2, $signals);
        $this->assertSame([SignalHandlerInterface::WORKER, SIGTERM], $signals[0]);
        $this->assertSame([SignalHandlerInterface::WORKER, SIGINT], $signals[1]);
    }

    public function testHandleSigintStopsServerImmediatelyWithoutSleeping()
    {
        $container = m::mock(ContainerContract::class);
        $server = m::mock(Server::class);

        // SIGINT should NOT read config (no sleep)
        $container->shouldNotReceive('make')->with('config');
        $container->shouldReceive('make')->with(Server::class)->once()->andReturn($server);
        $server->shouldReceive('stop')->once();

        $handler = new WorkerStopHandler($container);
        $handler->handle(SIGINT);
    }

    public function testHandleSigtermSleepsForConfiguredTimeThenStopsServer()
    {
        $container = m::mock(ContainerContract::class);
        $server = m::mock(Server::class);
        $config = new Repository([
            'server' => [
                'settings' => [
                    'max_wait_time' => 0, // Zero to avoid blocking in tests
                ],
            ],
        ]);

        $container->shouldReceive('make')->with('config')->once()->andReturn($config);
        $container->shouldReceive('make')->with(Server::class)->once()->andReturn($server);
        $server->shouldReceive('stop')->once();

        $handler = new WorkerStopHandler($container);
        $handler->handle(SIGTERM);
    }

    public function testHandleSigtermReadsConfigWithCorrectKeyAndDefault()
    {
        $container = m::mock(ContainerContract::class);
        $server = m::mock(Server::class);
        $config = m::mock(Repository::class);

        // Verify the exact config key and default value of 3
        $config->shouldReceive('get')
            ->with('server.settings.max_wait_time', 3)
            ->once()
            ->andReturn(0); // Return 0 to avoid blocking in tests

        $container->shouldReceive('make')->with('config')->once()->andReturn($config);
        $container->shouldReceive('make')->with(Server::class)->once()->andReturn($server);
        $server->shouldReceive('stop')->once();

        $handler = new WorkerStopHandler($container);
        $handler->handle(SIGTERM);
    }

    public function testHandleOtherSignalsBehaveLikeSigterm()
    {
        $container = m::mock(ContainerContract::class);
        $server = m::mock(Server::class);
        $config = new Repository([
            'server' => [
                'settings' => [
                    'max_wait_time' => 0,
                ],
            ],
        ]);

        $container->shouldReceive('make')->with('config')->once()->andReturn($config);
        $container->shouldReceive('make')->with(Server::class)->once()->andReturn($server);
        $server->shouldReceive('stop')->once();

        $handler = new WorkerStopHandler($container);

        // Any signal other than SIGINT should follow the sleep-then-stop path
        $handler->handle(SIGUSR1);
    }

    public function testInterfaceConstantsHaveExpectedValues()
    {
        $this->assertSame(1, SignalHandlerInterface::WORKER);
        $this->assertSame(2, SignalHandlerInterface::PROCESS);
    }
}
