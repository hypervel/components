<?php

declare(strict_types=1);

namespace Hypervel\Tests\WebSocketServer;

use Hypervel\Container\Container;
use Hypervel\Contracts\Container\Container as ContainerContract;
use Hypervel\Contracts\Http\Kernel;
use Hypervel\Contracts\Log\StdoutLoggerInterface;
use Hypervel\Routing\Router;
use Hypervel\Tests\TestCase;
use Hypervel\WebSocketServer\Server;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;

class ServerBootstrapTest extends TestCase
{
    use VerifiesDoubles;

    public function testBootstrapCompilesTheRouterFromTheExtensionHook(): void
    {
        $kernel = Double::for(Kernel::class)->strict();
        $kernel->expects('bootstrap');

        $router = Double::for(Router::class)->strict();
        $router->expects('compileAndWarm');

        $container = new Container;
        $container->instance(StdoutLoggerInterface::class, Double::for(StdoutLoggerInterface::class)->strict());
        $container->instance(Kernel::class, $kernel);

        $server = new BootstrapRouterServer($container, $router);
        $server->bootstrapForServer('reverb');

        $this->assertSame('reverb', $server->getServerName());
    }
}

class BootstrapRouterServer extends Server
{
    public function __construct(ContainerContract $container, protected Router $router)
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
