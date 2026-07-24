<?php

declare(strict_types=1);

namespace Hypervel\Tests\Reverb\Servers\Hypervel;

use Hypervel\Reverb\Servers\Hypervel\ReverbRouter;
use Hypervel\Routing\UrlGenerator;
use Hypervel\Tests\Reverb\ReverbTestCase;

class ReverbRouterTest extends ReverbTestCase
{
    public function testReverbRouterIsSeparateFromGlobalRouter(): void
    {
        $reverbRouter = $this->app->make(ReverbRouter::class);
        $globalRouter = $this->app->make('router');

        $this->assertNotSame($reverbRouter, $globalRouter);
    }

    public function testReverbRouterContainsExpectedRoutes(): void
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

    public function testGlobalRouterDoesNotContainReverbRoutes(): void
    {
        $globalRouter = $this->app->make('router');
        $routes = $globalRouter->getRoutes()->getRoutes();

        $uris = array_map(fn ($route) => $route->uri(), $routes);

        $this->assertNotContains('apps/{appId}/events', $uris);
        $this->assertNotContains('app/{appKey}', $uris);
        $this->assertNotContains('up', $uris);
    }

    public function testReverbRouterIsSingleton(): void
    {
        $first = $this->app->make(ReverbRouter::class);
        $second = $this->app->make(ReverbRouter::class);

        $this->assertSame($first, $second);
    }

    public function testCompilingReverbRoutesPreservesTheGlobalCollectionAndUrlGenerator(): void
    {
        $globalRouter = $this->app->make('router');
        $globalRouter->get('/application-only', static fn (): string => 'application')
            ->name('application-only');
        $globalRoutes = $globalRouter->getRoutes();
        $globalRoutes->refreshNameLookups();
        $url = $this->app->make('url');

        $this->assertInstanceOf(UrlGenerator::class, $url);
        $this->assertSame($globalRoutes, $this->app->make('routes'));

        $this->app->make(ReverbRouter::class)->compileAndWarm();

        $this->assertSame($globalRoutes, $this->app->make('routes'));
        $this->assertStringEndsWith('/application-only', $url->route('application-only'));
    }
}
