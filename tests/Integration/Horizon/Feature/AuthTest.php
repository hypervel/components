<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Horizon\Feature;

use Hypervel\Horizon\Exceptions\ForbiddenException;
use Hypervel\Horizon\Horizon;
use Hypervel\Horizon\Http\Middleware\Authenticate;
use Hypervel\Http\Request;
use Hypervel\Http\Response;
use Hypervel\Tests\Integration\Horizon\IntegrationTestCase;

/**
 * @internal
 * @coversNothing
 */
class AuthTest extends IntegrationTestCase
{
    public function testAuthenticationCallbackWorks()
    {
        Horizon::auth(function (Request $request) {
            return $request->attributes->get('user') === 'foo';
        });

        $fooRequest = Request::create('/');
        $fooRequest->attributes->set('user', 'foo');

        $barRequest = Request::create('/');
        $barRequest->attributes->set('user', 'bar');

        $this->assertTrue(Horizon::check($fooRequest));
        $this->assertFalse(Horizon::check($barRequest));
    }

    public function testAuthenticationMiddlewareCanPass()
    {
        Horizon::auth(function () {
            return true;
        });

        $middleware = new Authenticate();
        $request = Request::create('/');
        $response = new Response();

        $responseFromMiddleware = $middleware->handle(
            $request,
            fn () => $response
        );

        $this->assertEquals($response, $responseFromMiddleware);
    }

    public function testAuthenticationMiddlewareThrowsOnFailure()
    {
        $this->expectException(ForbiddenException::class);

        Horizon::auth(function () {
            return false;
        });

        $middleware = new Authenticate();
        $request = Request::create('/');
        $response = new Response();

        $middleware->handle(
            $request,
            fn () => $response
        );
    }
}
