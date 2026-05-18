<?php

declare(strict_types=1);

namespace Hypervel\Tests\ServerProcess;

use Hypervel\Contracts\Config\Repository;
use Hypervel\Contracts\Container\Container as ContainerContract;
use Hypervel\Contracts\ServerProcess\ProcessInterface;
use Hypervel\Core\Events\BeforeMainServerStart;
use Hypervel\ServerProcess\Listeners\BootProcessListener;
use Hypervel\ServerProcess\ProcessManager;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Swoole\Server;

class BootProcessListenerTest extends TestCase
{
    public function testBootsProcessesFromConfig()
    {
        $server = m::mock(Server::class);
        $process = new BootProcessListenerFakeProcess;

        $container = m::mock(ContainerContract::class);
        $container->shouldReceive('make')
            ->once()
            ->with(BootProcessListenerFakeProcess::class)
            ->andReturn($process);

        $config = m::mock(Repository::class);
        $config->shouldReceive('array')
            ->with('processes', [])
            ->andReturn([BootProcessListenerFakeProcess::class]);

        $listener = new BootProcessListener($container, $config);
        $event = new BeforeMainServerStart($server, ['processes' => []]);

        $listener->handle($event);

        $this->assertSame(1, $process->enableChecks);
        $this->assertSame(1, $process->binds);
        $this->assertTrue(ProcessManager::isRunning());
    }

    public function testBootsProcessesFromServerConfig()
    {
        $server = m::mock(Server::class);
        $process = new BootProcessListenerFakeProcess;

        $container = m::mock(ContainerContract::class);
        $container->shouldReceive('make')
            ->once()
            ->with(BootProcessListenerFakeProcess::class)
            ->andReturn($process);

        $config = m::mock(Repository::class);
        $config->shouldReceive('array')->with('processes', [])->andReturn([]);

        $listener = new BootProcessListener($container, $config);
        $event = new BeforeMainServerStart($server, ['processes' => [BootProcessListenerFakeProcess::class]]);

        $listener->handle($event);

        $this->assertSame(1, $process->enableChecks);
        $this->assertSame(1, $process->binds);
        $this->assertTrue(ProcessManager::isRunning());
    }

    public function testBootsProcessesFromProcessManager()
    {
        $server = m::mock(Server::class);

        $process = new BootProcessListenerFakeProcess;
        ProcessManager::register($process);

        $container = m::mock(ContainerContract::class);
        $container->shouldNotReceive('make');

        $config = m::mock(Repository::class);
        $config->shouldReceive('array')->with('processes', [])->andReturn([]);

        $listener = new BootProcessListener($container, $config);
        $event = new BeforeMainServerStart($server, []);

        $listener->handle($event);

        $this->assertSame(1, $process->enableChecks);
        $this->assertSame(1, $process->binds);
        $this->assertTrue(ProcessManager::isRunning());
    }

    public function testSkipsProcessWhereIsEnableReturnsFalse()
    {
        $server = m::mock(Server::class);
        $process = new BootProcessListenerFakeProcess(enabled: false);

        $container = m::mock(ContainerContract::class);
        $container->shouldReceive('make')
            ->once()
            ->with(BootProcessListenerFakeProcess::class)
            ->andReturn($process);

        $config = m::mock(Repository::class);
        $config->shouldReceive('array')
            ->with('processes', [])
            ->andReturn([BootProcessListenerFakeProcess::class]);

        $listener = new BootProcessListener($container, $config);
        $event = new BeforeMainServerStart($server, []);

        $listener->handle($event);

        $this->assertSame(1, $process->enableChecks);
        $this->assertSame(0, $process->binds);
    }

    public function testHandlesMissingProcessesKeyInServerConfig()
    {
        $server = m::mock(Server::class);

        $container = m::mock(ContainerContract::class);
        $container->shouldNotReceive('make');

        $config = m::mock(Repository::class);
        $config->shouldReceive('array')->with('processes', [])->andReturn([]);

        $listener = new BootProcessListener($container, $config);
        $event = new BeforeMainServerStart($server, []);

        $listener->handle($event);

        $this->assertTrue(ProcessManager::isRunning());
    }

    public function testDedupesDuplicateClassStringRegistration()
    {
        $server = m::mock(Server::class);
        $process = new BootProcessListenerFakeProcess;

        $container = m::mock(ContainerContract::class);
        $container->shouldReceive('make')
            ->once()
            ->with(BootProcessListenerFakeProcess::class)
            ->andReturn($process);

        $config = m::mock(Repository::class);
        $config->shouldReceive('array')
            ->with('processes', [])
            ->andReturn([BootProcessListenerFakeProcess::class]);

        $listener = new BootProcessListener($container, $config);
        $event = new BeforeMainServerStart($server, ['processes' => [BootProcessListenerFakeProcess::class]]);

        $listener->handle($event);

        $this->assertSame(1, $process->enableChecks);
        $this->assertSame(1, $process->binds);
    }

    public function testDoesNotDedupDistinctInstancesOfSameClass()
    {
        $server = m::mock(Server::class);

        $process1 = new BootProcessListenerFakeProcess;
        $process2 = new BootProcessListenerFakeProcess;
        ProcessManager::register($process1);
        ProcessManager::register($process2);

        $container = m::mock(ContainerContract::class);
        $container->shouldNotReceive('make');

        $config = m::mock(Repository::class);
        $config->shouldReceive('array')->with('processes', [])->andReturn([]);

        $listener = new BootProcessListener($container, $config);
        $event = new BeforeMainServerStart($server, []);

        $listener->handle($event);

        $this->assertSame(1, $process1->enableChecks);
        $this->assertSame(1, $process1->binds);
        $this->assertSame(1, $process2->enableChecks);
        $this->assertSame(1, $process2->binds);
    }
}

class BootProcessListenerFakeProcess implements ProcessInterface
{
    public int $binds = 0;

    public int $enableChecks = 0;

    public function __construct(private bool $enabled = true)
    {
    }

    /**
     * Create process objects and bind them to the server.
     */
    public function bind(Server $server): void
    {
        ++$this->binds;
    }

    /**
     * Determine if the process should start.
     */
    public function isEnable(Server $server): bool
    {
        ++$this->enableChecks;

        return $this->enabled;
    }

    /**
     * The logic of the process.
     */
    public function handle(): void
    {
    }
}
