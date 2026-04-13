<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Routing;

use Hypervel\Support\Facades\Route;

/**
 * @internal
 * @coversNothing
 */
class FallbackRouteTest extends RoutingTestCase
{
    public function testBasicFallback()
    {
        Route::fallback(function () {
            return response('fallback', 404);
        });

        Route::get('one', function () {
            return 'one';
        });

        $this->assertStringContainsString('one', $this->get('/one')->getContent());
        $this->assertStringContainsString('fallback', $this->get('/non-existing')->getContent());
        $this->assertEquals(404, $this->get('/non-existing')->getStatusCode());
    }

    public function testFallbackWithPrefix()
    {
        Route::group(['prefix' => 'prefix'], function () {
            Route::fallback(function () {
                return response('fallback', 404);
            });

            Route::get('one', function () {
                return 'one';
            });
        });

        $this->assertStringContainsString('one', $this->get('/prefix/one')->getContent());
        $this->assertStringContainsString('fallback', $this->get('/prefix/non-existing')->getContent());
        $this->assertStringContainsString('fallback', $this->get('/prefix/non-existing/with/multiple/segments')->getContent());
        $this->get('/non-existing')->assertNotFound();
    }

    public function testFallbackWithWildcards()
    {
        Route::fallback(function () {
            return response('fallback', 404);
        });

        Route::get('one', function () {
            return 'one';
        });

        Route::get('{any}', function () {
            return 'wildcard';
        })->where('any', '.*');

        $this->assertStringContainsString('one', $this->get('/one')->getContent());

        tap($this->get('/non-existing'), function ($response) {
            $this->assertStringContainsString('wildcard', $response->getContent());
            $this->assertEquals(200, $response->getStatusCode());

            $this->assertSame('non-existing', $response->baseRequest->route('any'));
        });
    }

    public function testNoRoutes()
    {
        Route::fallback(function () {
            return response('fallback', 404);
        });

        $this->assertStringContainsString('fallback', $this->get('/non-existing')->getContent());
        $this->assertEquals(404, $this->get('/non-existing')->getStatusCode());
    }

    public function testRespondWithNamedFallbackRoute()
    {
        Route::fallback(function () {
            return response('fallback', 404);
        })->name('testFallbackRoute');

        Route::get('one', function () {
            return Route::respondWithRoute('testFallbackRoute');
        });

        $this->assertStringContainsString('fallback', $this->get('/non-existing')->getContent());
        $this->assertStringContainsString('fallback', $this->get('/one')->getContent());
    }

    public function testNoFallbacks()
    {
        Route::get('one', function () {
            return 'one';
        });

        $this->assertStringContainsString('one', $this->get('/one')->getContent());
        $this->assertEquals(200, $this->get('/one')->getStatusCode());
    }
}
