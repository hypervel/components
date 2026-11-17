<?php

declare(strict_types=1);

namespace Hypervel\Tests\ServerProcess;

use Hypervel\Contracts\Container\Container as ContainerContract;
use Hypervel\Contracts\ServerProcess\ProcessInterface;
use Hypervel\Core\Events\BeforeMainServerStart;
use Hypervel\ServerProcess\Listeners\BootProcessListener;
use Hypervel\ServerProcess\ProcessManager;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use Swoole\Server;

class BootProcessListenerTest extends TestCase
{
    use VerifiesDoubles;

    public function testBootsProcessesFromServerConfig(): void
    {
        $server = Double::for(Server::class)->strict();
        $process = new BootProcessListenerFakeProcess;

        $container = Double::for(ContainerContract::class, override: true)->strict();
        $container->expects('make')
            ->with(BootProcessListenerFakeProcess::class)
            ->returns($process);

        $listener = new BootProcessListener($container->instance());
        $event = new BeforeMainServerStart($server, ['processes' => [BootProcessListenerFakeProcess::class]]);

        $listener->handle($event);

        $this->assertSame(1, $process->enableChecks);
        $this->assertSame(1, $process->binds);
        $this->assertTrue(ProcessManager::isRunning());
    }

    public function testBootsProcessesFromProcessManager(): void
    {
        $server = Double::for(Server::class)->strict();

        $process = new BootProcessListenerFakeProcess;
        ProcessManager::register($process);

        $container = Double::for(ContainerContract::class, override: true)->strict();
        $container->expects('make')->never();

        $listener = new BootProcessListener($container->instance());
        $event = new BeforeMainServerStart($server, []);

        $listener->handle($event);

        $this->assertSame(1, $process->enableChecks);
        $this->assertSame(1, $process->binds);
        $this->assertTrue(ProcessManager::isRunning());
    }

    public function testSkipsProcessWhereIsEnabledReturnsFalse(): void
    {
        $server = Double::for(Server::class)->strict();
        $process = new BootProcessListenerFakeProcess(enabled: false);

        $container = Double::for(ContainerContract::class, override: true)->strict();
        $container->expects('make')
            ->with(BootProcessListenerFakeProcess::class)
            ->returns($process);

        $listener = new BootProcessListener($container->instance());
        $event = new BeforeMainServerStart($server, ['processes' => [BootProcessListenerFakeProcess::class]]);

        $listener->handle($event);

        $this->assertSame(1, $process->enableChecks);
        $this->assertSame(0, $process->binds);
    }

    public function testHandlesMissingProcessesKeyInServerConfig(): void
    {
        $server = Double::for(Server::class)->strict();

        $container = Double::for(ContainerContract::class, override: true)->strict();
        $container->expects('make')->never();

        $listener = new BootProcessListener($container->instance());
        $event = new BeforeMainServerStart($server, []);

        $listener->handle($event);

        $this->assertTrue(ProcessManager::isRunning());
    }

    public function testDedupesDuplicateClassStringRegistration(): void
    {
        $server = Double::for(Server::class)->strict();
        $process = new BootProcessListenerFakeProcess;

        $container = Double::for(ContainerContract::class, override: true)->strict();
        $container->expects('make')
            ->with(BootProcessListenerFakeProcess::class)
            ->returns($process);

        $listener = new BootProcessListener($container->instance());
        $event = new BeforeMainServerStart($server, [
            'processes' => [BootProcessListenerFakeProcess::class, BootProcessListenerFakeProcess::class],
        ]);

        $listener->handle($event);

        $this->assertSame(1, $process->enableChecks);
        $this->assertSame(1, $process->binds);
    }

    public function testDoesNotDedupDistinctInstancesOfSameClass(): void
    {
        $server = Double::for(Server::class)->strict();

        $process1 = new BootProcessListenerFakeProcess;
        $process2 = new BootProcessListenerFakeProcess;
        ProcessManager::register($process1);
        ProcessManager::register($process2);

        $container = Double::for(ContainerContract::class, override: true)->strict();
        $container->expects('make')->never();

        $listener = new BootProcessListener($container->instance());
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

    /**
     * Create a process with the selected enabled state.
     */
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
    public function isEnabled(Server $server): bool
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
