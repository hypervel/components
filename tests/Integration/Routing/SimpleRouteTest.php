<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Routing;

use Hypervel\Support\Facades\Route;
use Hypervel\Tests\Routing\Fixtures\RouteNameEnum;

/**
 * @internal
 * @coversNothing
 */
class SimpleRouteTest extends RoutingTestCase
{
    public function testSimpleRouteThroughTheFramework()
    {
        Route::get('/', function () {
            return 'Hello World';
        });

        $response = $this->get('/');

        $this->assertSame('Hello World', $response->content());

        $response = $this->get('/?foo=bar');

        $this->assertSame('Hello World', $response->content());

        $this->assertSame('bar', $response->baseRequest->query('foo'));
    }

    public function testSimpleRouteWitStringBackedEnumRouteNameThroughTheFramework()
    {
        Route::get('/', function () {
            return 'Hello World';
        })->name(RouteNameEnum::UserIndex);

        $response = $this->get(\route(RouteNameEnum::UserIndex, ['foo' => 'bar']));

        $this->assertSame('Hello World', $response->content());

        $this->assertSame('bar', $response->baseRequest->query('foo'));
    }
}
