<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Routing;

use Hypervel\Support\Facades\Route;
use Hypervel\Support\Facades\View;

class RouteViewTest extends RoutingTestCase
{
    public function testRouteView()
    {
        Route::view('route', 'view', ['foo' => 'bar']);

        View::addLocation(__DIR__ . '/Fixtures');

        $this->assertStringContainsString('Test bar', $this->get('/route')->getContent());
        $this->assertSame(200, $this->get('/route')->status());
    }

    public function testRouteViewWithParams()
    {
        Route::view('route/{param}/{param2?}', 'view', ['foo' => 'bar']);

        View::addLocation(__DIR__ . '/Fixtures');

        $this->assertStringContainsString('Test bar', $this->get('/route/value1/value2')->getContent());
        $this->assertStringContainsString('Test bar', $this->get('/route/value1')->getContent());

        tap($this->get('/route/value1/value2'), function ($response) {
            $this->assertEquals('value1', $response->viewData('param'));
            $this->assertEquals('value1', $response->baseRequest->route('param'));
            $this->assertEquals('value2', $response->baseRequest->route('param2'));
        });

        tap($this->get('/route/value1/value2'), function ($response) {
            $this->assertEquals('value2', $response->viewData('param2'));
            $this->assertEquals('value1', $response->baseRequest->route('param'));
            $this->assertEquals('value2', $response->baseRequest->route('param2'));
        });
    }

    public function testRouteViewWithStatus()
    {
        Route::view('route', 'view', ['foo' => 'bar'], 418);

        View::addLocation(__DIR__ . '/Fixtures');

        $this->assertSame(418, $this->get('/route')->status());
    }

    public function testRouteViewWithHeaders()
    {
        Route::view('route', 'view', ['foo' => 'bar'], 418, ['Framework' => 'Hypervel']);

        View::addLocation(__DIR__ . '/Fixtures');

        $this->assertSame('Hypervel', $this->get('/route')->headers->get('Framework'));
    }

    public function testRouteViewOverloadingStatusWithHeaders()
    {
        Route::view('route', 'view', ['foo' => 'bar'], ['Framework' => 'Hypervel']);

        View::addLocation(__DIR__ . '/Fixtures');

        $this->assertSame('Hypervel', $this->get('/route')->headers->get('Framework'));
    }
}
