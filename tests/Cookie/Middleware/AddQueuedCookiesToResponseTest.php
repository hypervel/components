<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cookie\Middleware;

use Hypervel\Contracts\Cookie\QueueingFactory as CookieJar;
use Hypervel\Cookie\Cookie;
use Hypervel\Cookie\Middleware\AddQueuedCookiesToResponse;
use Hypervel\Http\Request;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Symfony\Component\HttpFoundation\Response;

/**
 * @internal
 * @coversNothing
 */
class AddQueuedCookiesToResponseTest extends TestCase
{
    public function testHandle()
    {
        $queuedCookie = new Cookie('foo', 'bar');

        $cookieManager = m::mock(CookieJar::class);
        $cookieManager->shouldReceive('getQueuedCookies')->once()->andReturn([$queuedCookie]);

        $request = Request::create('/test');
        $response = new Response('OK');

        $middleware = new AddQueuedCookiesToResponse($cookieManager);

        $result = $middleware->handle($request, fn () => $response);

        $this->assertSame($response, $result);
        $cookies = $result->headers->getCookies();
        $this->assertCount(1, $cookies);
        $this->assertSame('foo', $cookies[0]->getName());
        $this->assertSame('bar', $cookies[0]->getValue());
    }
}
