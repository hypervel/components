<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Concerns;

use Hypervel\Router\Router;
use Hypervel\Testbench\TestCase;

/**
 * @internal
 * @coversNothing
 */
class HandlesRoutesTest extends TestCase
{
    protected bool $defineRoutesCalled = false;

    protected bool $defineWebRoutesCalled = false;

    protected function defineRoutes($router): void
    {
        $this->defineRoutesCalled = true;

        $router->get('/api/test', fn () => 'api_response');
    }

    protected function defineWebRoutes($router): void
    {
        $this->defineWebRoutesCalled = true;

        $router->get('/web/test', fn () => 'web_response');
    }

    public function testDefineRoutesMethodExists(): void
    {
        $this->assertTrue(method_exists($this, 'defineRoutes'));
    }

    public function testDefineWebRoutesMethodExists(): void
    {
        $this->assertTrue(method_exists($this, 'defineWebRoutes'));
    }

    public function testSetUpApplicationRoutesMethodExists(): void
    {
        $this->assertTrue(method_exists($this, 'setUpApplicationRoutes'));
    }

    public function testSetUpApplicationRoutesCallsDefineRoutes(): void
    {
        $this->defineRoutesCalled = false;
        $this->defineWebRoutesCalled = false;

        $this->setUpApplicationRoutes($this->app);

        $this->assertTrue($this->defineRoutesCalled);
    }

    public function testSetUpApplicationRoutesCallsDefineWebRoutes(): void
    {
        $this->defineRoutesCalled = false;
        $this->defineWebRoutesCalled = false;

        $this->setUpApplicationRoutes($this->app);

        $this->assertTrue($this->defineWebRoutesCalled);
    }

    public function testRouterIsPassedToDefineRoutes(): void
    {
        $router = $this->app->get(Router::class);

        $this->assertInstanceOf(Router::class, $router);
    }
}
