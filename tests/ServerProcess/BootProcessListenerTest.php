<?php

declare(strict_types=1);

namespace Hypervel\Tests\ServerProcess;

use Hypervel\Contracts\Config\Repository;
use Hypervel\Contracts\Container\Container as ContainerContract;
use Hypervel\Contracts\Event\Dispatcher as DispatcherContract;
use Hypervel\Framework\Events\BeforeMainServerStart;
use Hypervel\ServerProcess\Listeners\BootProcessListener;
use Hypervel\ServerProcess\ProcessManager;
use Hypervel\Tests\ServerProcess\Stub\FooProcess;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Swoole\Server;

/**
 * @internal
 * @coversNothing
 */
class BootProcessListenerTest extends TestCase
{
    protected function tearDown(): void
    {
        parent::tearDown();
        ProcessManager::clear();
        ProcessManager::setRunning(false);
    }

    public function testBootsProcessesFromConfig()
    {
        $server = m::mock(Server::class);
        $server->shouldReceive('addProcess')->once()->andReturn(1);

        $fooProcess = new FooProcess($this->makeSimpleContainer());

        $container = m::mock(ContainerContract::class);
        $container->shouldReceive('make')->with(FooProcess::class)->andReturn($fooProcess);

        $config = m::mock(Repository::class);
        $config->shouldReceive('get')->with('processes', [])->andReturn([FooProcess::class]);

        $listener = new BootProcessListener($container, $config);
        $event = new BeforeMainServerStart($server, ['processes' => []]);

        $listener->handle($event);

        $this->assertTrue(ProcessManager::isRunning());
    }

    public function testBootsProcessesFromServerConfig()
    {
        $server = m::mock(Server::class);
        $server->shouldReceive('addProcess')->once()->andReturn(1);

        $fooProcess = new FooProcess($this->makeSimpleContainer());

        $container = m::mock(ContainerContract::class);
        $container->shouldReceive('make')->with(FooProcess::class)->andReturn($fooProcess);

        $config = m::mock(Repository::class);
        $config->shouldReceive('get')->with('processes', [])->andReturn([]);

        $listener = new BootProcessListener($container, $config);
        $event = new BeforeMainServerStart($server, ['processes' => [FooProcess::class]]);

        $listener->handle($event);

        $this->assertTrue(ProcessManager::isRunning());
    }

    public function testBootsProcessesFromProcessManager()
    {
        $server = m::mock(Server::class);
        $server->shouldReceive('addProcess')->once()->andReturn(1);

        $fooProcess = new FooProcess($this->makeSimpleContainer());
        ProcessManager::register($fooProcess);

        $container = m::mock(ContainerContract::class);

        $config = m::mock(Repository::class);
        $config->shouldReceive('get')->with('processes', [])->andReturn([]);

        $listener = new BootProcessListener($container, $config);
        $event = new BeforeMainServerStart($server, []);

        $listener->handle($event);

        $this->assertTrue(ProcessManager::isRunning());
    }

    public function testSkipsProcessWhereIsEnableReturnsFalse()
    {
        $server = m::mock(Server::class);
        $server->shouldNotReceive('addProcess');

        $container = m::mock(ContainerContract::class);
        $simpleContainer = $this->makeSimpleContainer();

        $disabledProcess = new class($simpleContainer) extends FooProcess {
            public function isEnable(Server $server): bool
            {
                return false;
            }
        };

        $container->shouldReceive('make')->andReturn($disabledProcess);

        $config = m::mock(Repository::class);
        $config->shouldReceive('get')->with('processes', [])->andReturn([get_class($disabledProcess)]);

        $listener = new BootProcessListener($container, $config);
        $event = new BeforeMainServerStart($server, []);

        $listener->handle($event);
    }

    public function testHandlesMissingProcessesKeyInServerConfig()
    {
        $server = m::mock(Server::class);

        $container = m::mock(ContainerContract::class);
        $config = m::mock(Repository::class);
        $config->shouldReceive('get')->with('processes', [])->andReturn([]);

        $listener = new BootProcessListener($container, $config);
        $event = new BeforeMainServerStart($server, []);

        $listener->handle($event);

        $this->assertTrue(ProcessManager::isRunning());
    }

    private function makeSimpleContainer(): ContainerContract
    {
        $container = m::mock(ContainerContract::class);
        $container->shouldReceive('has')->with(DispatcherContract::class)->andReturn(false);
        return $container;
    }
}
