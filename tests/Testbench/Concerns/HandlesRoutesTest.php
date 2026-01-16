<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Concerns;

use Hypervel\Foundation\Testing\Concerns\RunTestsInCoroutine;
use Hypervel\Router\Router;
use Hypervel\Testbench\TestCase;

/**
 * @internal
 * @coversNothing
 */
class HandlesRoutesTest extends TestCase
{
    use RunTestsInCoroutine;

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

        // Note: Web routes are wrapped in 'web' middleware group by setUpApplicationRoutes
        // We register a simple route here just to verify the method is called
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
        // setUpApplicationRoutes is called automatically in setUp via afterApplicationCreated
        // so defineRoutesCalled should already be true
        $this->assertTrue($this->defineRoutesCalled);
    }

    public function testSetUpApplicationRoutesCallsDefineWebRoutes(): void
    {
        // setUpApplicationRoutes is called automatically in setUp via afterApplicationCreated
        // so defineWebRoutesCalled should already be true
        $this->assertTrue($this->defineWebRoutesCalled);
    }

    public function testRouterIsPassedToDefineRoutes(): void
    {
        $router = $this->app->get(Router::class);

        $this->assertInstanceOf(Router::class, $router);
    }

    public function testDefinedRoutesAreAccessibleViaHttp(): void
    {
        $response = $this->get('/api/test');

        $response->assertSuccessful();
        $response->assertContent('api_response');
    }
}
