<?php

declare(strict_types=1);

namespace Hypervel\Tests\Reverb\Servers\Hypervel;

use Hypervel\Reverb\Servers\Hypervel\ReverbRouter;
use Hypervel\Tests\Reverb\ReverbTestCase;

class ReverbRouterTest extends ReverbTestCase
{
    public function testReverbRouterIsSeparateFromGlobalRouter()
    {
        $reverbRouter = $this->app->make(ReverbRouter::class);
        $globalRouter = $this->app->make('router');

        $this->assertNotSame($reverbRouter, $globalRouter);
    }

    public function testReverbRouterContainsExpectedRoutes()
    {
        $router = $this->app->make(ReverbRouter::class);
        $routes = $router->getRoutes()->getRoutes();

        $uris = array_map(fn ($route) => $route->methods()[0] . ' ' . $route->uri(), $routes);

        $this->assertContains('GET app/{appKey}', $uris);
        $this->assertContains('POST apps/{appId}/events', $uris);
        $this->assertContains('POST apps/{appId}/batch_events', $uris);
        $this->assertContains('GET apps/{appId}/connections', $uris);
        $this->assertContains('GET apps/{appId}/channels', $uris);
        $this->assertContains('GET apps/{appId}/channels/{channel}', $uris);
        $this->assertContains('GET apps/{appId}/channels/{channel}/users', $uris);
        $this->assertContains('POST apps/{appId}/users/{userId}/terminate_connections', $uris);
        $this->assertContains('GET up', $uris);
    }

    public function testGlobalRouterDoesNotContainReverbRoutes()
    {
        $globalRouter = $this->app->make('router');
        $routes = $globalRouter->getRoutes()->getRoutes();

        $uris = array_map(fn ($route) => $route->uri(), $routes);

        $this->assertNotContains('apps/{appId}/events', $uris);
        $this->assertNotContains('app/{appKey}', $uris);
        $this->assertNotContains('up', $uris);
    }

    public function testReverbRouterIsSingleton()
    {
        $first = $this->app->make(ReverbRouter::class);
        $second = $this->app->make(ReverbRouter::class);

        $this->assertSame($first, $second);
    }
}
