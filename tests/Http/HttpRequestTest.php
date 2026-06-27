<?php

declare(strict_types=1);

namespace Hypervel\Tests\Http;

use Hypervel\Http\Request;
use Hypervel\Routing\Route;
use Hypervel\Tests\TestCase;
use RuntimeException;

class HttpRequestTest extends TestCase
{
    public function testWantsMarkdown()
    {
        $request = Request::create('/', 'GET', [], [], [], ['HTTP_ACCEPT' => 'text/markdown']);
        $this->assertTrue($request->wantsMarkdown());

        $request = Request::create('/', 'GET', [], [], [], ['HTTP_ACCEPT' => 'text/markdown; charset=utf-8']);
        $this->assertTrue($request->wantsMarkdown());

        $request = Request::create('/', 'GET', [], [], [], ['HTTP_ACCEPT' => 'application/json']);
        $this->assertFalse($request->wantsMarkdown());

        $request = Request::create('/', 'GET', [], [], [], ['HTTP_ACCEPT' => 'text/html']);
        $this->assertFalse($request->wantsMarkdown());
    }

    public function testAcceptsMarkdown()
    {
        $request = Request::create('/', 'GET', [], [], [], ['HTTP_ACCEPT' => 'text/markdown']);
        $this->assertTrue($request->acceptsMarkdown());

        $request = Request::create('/', 'GET', [], [], [], ['HTTP_ACCEPT' => 'text/html, text/markdown']);
        $this->assertFalse($request->wantsMarkdown());
        $this->assertTrue($request->acceptsMarkdown());

        $request = Request::create('/', 'GET', [], [], [], ['HTTP_ACCEPT' => 'application/json']);
        $this->assertFalse($request->acceptsMarkdown());
    }

    public function testFingerprintReturnsXxh128HashForRouteAndIp(): void
    {
        $request = Request::create('/users', 'GET', [], [], [], ['REMOTE_ADDR' => '127.0.0.1']);
        $route = new Route(['GET', 'HEAD'], '/users', ['uses' => fn () => null]);

        $request->setRouteResolver(fn () => $route);

        $this->assertSame(
            hash('xxh128', implode('|', array_merge(
                $route->methods(),
                [$route->getDomain(), $route->uri(), $request->ip()]
            ))),
            $request->fingerprint()
        );
    }

    public function testFingerprintThrowsWhenRouteIsUnavailable(): void
    {
        $request = Request::create('/users', 'GET');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to generate fingerprint. Route unavailable.');

        $request->fingerprint();
    }
}
