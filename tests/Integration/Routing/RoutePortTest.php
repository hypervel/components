<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Routing;

use Hypervel\Support\Facades\Route;

/**
 * @internal
 * @coversNothing
 */
class RoutePortTest extends RoutingTestCase
{
    public function testPortScopedRouteMatchesCorrectPort()
    {
        Route::port(8080)->get('/foo', fn () => 'port 8080');

        $response = $this->call('GET', 'http://localhost:8080/foo');

        $response->assertOk();
        $this->assertSame('port 8080', $response->content());
    }

    public function testPortScopedRouteRejectsWrongPort()
    {
        Route::port(8080)->get('/foo', fn () => 'port 8080');

        $response = $this->call('GET', 'http://localhost:9501/foo');

        $response->assertNotFound();
    }

    public function testUnscopedRouteMatchesAnyPort()
    {
        Route::get('/foo', fn () => 'any port');

        $this->call('GET', 'http://localhost:8080/foo')->assertOk();
        $this->call('GET', 'http://localhost:9501/foo')->assertOk();
    }

    public function testPortGroupScopesAllChildRoutes()
    {
        Route::port(8080)->group(function () {
            Route::get('/foo', fn () => 'foo');
            Route::get('/bar', fn () => 'bar');
        });

        $this->call('GET', 'http://localhost:8080/foo')->assertOk();
        $this->call('GET', 'http://localhost:8080/bar')->assertOk();

        $this->call('GET', 'http://localhost:9501/foo')->assertNotFound();
        $this->call('GET', 'http://localhost:9501/bar')->assertNotFound();
    }
}
