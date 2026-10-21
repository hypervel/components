<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Routing;

use Hypervel\Support\Facades\Route;
use Hypervel\Support\Facades\View;
use Hypervel\Testing\TestResponse;

class RouteViewTest extends RoutingTestCase
{
    public function testRouteView(): void
    {
        Route::view('route', 'view', ['foo' => 'bar']);

        View::addLocation(dirname(__DIR__, 2) . '/Routing/Fixtures');

        $this->assertStringContainsString('Test bar', $this->get('/route')->getContent());
        $this->assertSame(200, $this->get('/route')->status());
    }

    public function testRouteViewWithParams(): void
    {
        Route::view('route/{param}/{param2?}', 'view', ['foo' => 'bar']);

        View::addLocation(dirname(__DIR__, 2) . '/Routing/Fixtures');

        $this->assertStringContainsString('Test bar', $this->get('/route/value1/value2')->getContent());
        $this->assertStringContainsString('Test bar', $this->get('/route/value1')->getContent());

        tap($this->get('/route/value1/value2'), function (TestResponse $response): void {
            $this->assertSame('value1', $response->viewData('param'));
            $this->assertSame('value1', $response->baseRequest->route('param'));
            $this->assertSame('value2', $response->baseRequest->route('param2'));
        });

        tap($this->get('/route/value1/value2'), function (TestResponse $response): void {
            $this->assertSame('value2', $response->viewData('param2'));
            $this->assertSame('value1', $response->baseRequest->route('param'));
            $this->assertSame('value2', $response->baseRequest->route('param2'));
        });
    }

    public function testRouteViewWithStatus(): void
    {
        Route::view('route', 'view', ['foo' => 'bar'], 418);

        View::addLocation(dirname(__DIR__, 2) . '/Routing/Fixtures');

        $this->assertSame(418, $this->get('/route')->status());
    }

    public function testRouteViewWithHeaders(): void
    {
        Route::view('route', 'view', ['foo' => 'bar'], 418, ['Framework' => 'Hypervel']);

        View::addLocation(dirname(__DIR__, 2) . '/Routing/Fixtures');

        $this->assertSame('Hypervel', $this->get('/route')->headers->get('Framework'));
    }

    public function testRouteViewOverloadingStatusWithHeaders(): void
    {
        Route::view('route', 'view', ['foo' => 'bar'], ['Framework' => 'Hypervel']);

        View::addLocation(dirname(__DIR__, 2) . '/Routing/Fixtures');

        $this->assertSame('Hypervel', $this->get('/route')->headers->get('Framework'));
    }
}
