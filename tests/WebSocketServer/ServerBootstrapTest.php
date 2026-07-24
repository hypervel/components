<?php

declare(strict_types=1);

namespace Hypervel\Tests\WebSocketServer;

use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Http\Kernel;
use Hypervel\Contracts\Log\StdoutLoggerInterface;
use Hypervel\Routing\Router;
use Hypervel\Tests\TestCase;
use Hypervel\WebSocketServer\Server;
use Mockery as m;

class ServerBootstrapTest extends TestCase
{
    public function testBootstrapCompilesTheRouterFromTheExtensionHook(): void
    {
        $kernel = m::mock(Kernel::class);
        $kernel->shouldReceive('bootstrap')->once();

        $router = m::mock(Router::class);
        $router->shouldReceive('compileAndWarm')->once();

        $container = m::mock(Container::class);
        $container->shouldReceive('make')->once()->with(StdoutLoggerInterface::class)
            ->andReturn(m::mock(StdoutLoggerInterface::class));
        $container->shouldReceive('bound')->once()->with('events')->andReturnFalse();
        $container->shouldReceive('make')->once()->with(Kernel::class)->andReturn($kernel);

        $server = new BootstrapRouterServer($container, $router);
        $server->bootstrapForServer('reverb');

        $this->assertSame('reverb', $server->getServerName());
    }
}

class BootstrapRouterServer extends Server
{
    public function __construct(Container $container, protected Router $router)
    {
        parent::__construct($container);
    }

    /**
     * Get the test router.
     */
    protected function getRouter(): Router
    {
        return $this->router;
    }
}
