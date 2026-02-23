<?php

declare(strict_types=1);

namespace Hypervel\Tests\ServerProcess;

use Hypervel\Contracts\Container\Container as ContainerContract;
use Hypervel\ServerProcess\Events\AfterProcessHandle;
use Hypervel\ServerProcess\Events\BeforeProcessHandle;
use Hypervel\Tests\ServerProcess\Stub\FooProcess;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Psr\EventDispatcher\EventDispatcherInterface;
use ReflectionClass;
use Swoole\Server;

/**
 * @internal
 * @coversNothing
 */
class ProcessTest extends TestCase
{
    /** @var array<int, object> */
    public static array $dispatched = [];

    protected function tearDown(): void
    {
        parent::tearDown();
        self::$dispatched = [];
    }

    public function testEventWhenThrowExceptionInProcess()
    {
        $container = $this->getContainer();
        $process = new FooProcess($container);
        $process->bind($this->getServer());

        $this->assertInstanceOf(BeforeProcessHandle::class, self::$dispatched[0]);
        $this->assertInstanceOf(AfterProcessHandle::class, self::$dispatched[1]);
    }

    protected function getContainer(): ContainerContract
    {
        $container = m::mock(ContainerContract::class);

        $container->shouldReceive('has')->with(EventDispatcherInterface::class)->andReturn(true);
        $container->shouldReceive('make')->with(EventDispatcherInterface::class)->andReturnUsing(function () {
            $dispatcher = m::mock(EventDispatcherInterface::class);
            $dispatcher->shouldReceive('dispatch')->withAnyArgs()->andReturnUsing(function ($event) {
                self::$dispatched[] = $event;
            });
            return $dispatcher;
        });

        return $container;
    }

    protected function getServer(): Server
    {
        $server = m::mock(Server::class);
        $server->shouldReceive('addProcess')->withAnyArgs()->andReturnUsing(function ($process) {
            $ref = new ReflectionClass($process);
            $property = $ref->getProperty('callback');
            $callback = $property->getValue($process);
            $callback($process);
            return 1;
        });
        return $server;
    }
}
