<?php

declare(strict_types=1);

namespace Hypervel\Tests\Reverb\Servers\Hypervel;

use Hypervel\Reverb\Servers\Hypervel\ReverbRouter;
use Hypervel\Reverb\Servers\Hypervel\WebSocketServer;
use Hypervel\Tests\Reverb\ReverbTestCase;
use ReflectionMethod;

/**
 * @internal
 * @coversNothing
 */
class RouteIsolationTest extends ReverbTestCase
{
    public function testReverbRouterRejectsNonReverbPaths()
    {
        // Register a route on the global Router (simulates an app route)
        $this->app->make('router')->get('/api/users', fn () => 'app response');

        // Dispatch through the Reverb Router — should 404
        $response = $this->reverbGet('/api/users');

        $response->assertNotFound();
    }

    public function testReverbRouterServesReverbRoutes()
    {
        $response = $this->reverbGet('/up');

        $response->assertOk();
        $this->assertSame('{"health":"OK"}', $response->getContent());
    }

    public function testWebSocketServerUsesReverbRouter()
    {
        $wsServer = $this->app->make(WebSocketServer::class);

        // Use reflection to call the protected getRouter() method
        $method = new ReflectionMethod($wsServer, 'getRouter');

        $router = $method->invoke($wsServer);

        $this->assertInstanceOf(ReverbRouter::class, $router);
    }
}
