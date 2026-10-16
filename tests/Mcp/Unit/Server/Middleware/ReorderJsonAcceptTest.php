<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Unit\Server\Middleware;

use Hypervel\Http\Request;
use Hypervel\Http\Response;
use Hypervel\Mcp\Server\Middleware\ReorderJsonAccept;
use Hypervel\Tests\TestCase;

class ReorderJsonAcceptTest extends TestCase
{
    public function testLeavesSingleAcceptHeaderUnchanged(): void
    {
        $request = new Request;
        $request->headers->set('Accept', 'application/json');

        $middleware = new ReorderJsonAccept;

        $middleware->handle($request, fn (Request $request): Response => new Response('test'));

        $this->assertSame('application/json', $request->header('Accept'));
    }

    public function testLeavesNonCommaSeparatedAcceptHeaderUnchanged(): void
    {
        $request = new Request;
        $request->headers->set('Accept', 'text/html');

        $middleware = new ReorderJsonAccept;

        $middleware->handle($request, fn (Request $request): Response => new Response('test'));

        $this->assertSame('text/html', $request->header('Accept'));
    }

    public function testReordersMultipleAcceptHeadersToPrioritizeJson(): void
    {
        $request = new Request;
        $request->headers->set('Accept', 'text/html, application/json, text/plain');

        $middleware = new ReorderJsonAccept;

        $middleware->handle($request, fn (Request $request): Response => new Response('test'));

        $this->assertSame('application/json, text/html, text/plain', $request->header('Accept'));
    }

    public function testHandlesJsonAlreadyFirstInList(): void
    {
        $request = new Request;
        $request->headers->set('Accept', 'application/json, text/html, text/plain');

        $middleware = new ReorderJsonAccept;

        $middleware->handle($request, fn (Request $request): Response => new Response('test'));

        $this->assertSame('application/json, text/html, text/plain', $request->header('Accept'));
    }

    public function testHandlesMultipleJsonTypesCorrectly(): void
    {
        $request = new Request;
        $request->headers->set('Accept', 'text/html, application/json, application/vnd.api+json, text/plain');

        $middleware = new ReorderJsonAccept;

        $middleware->handle($request, fn (Request $request): Response => new Response('test'));

        $accept = $request->header('Accept');
        $parts = array_map(trim(...), explode(',', $accept));

        $this->assertSame(['application/json', 'text/html', 'application/vnd.api+json', 'text/plain'], $parts);
        $this->assertCount(4, $parts);
    }

    public function testHandlesAcceptHeaderWithQualityValues(): void
    {
        $request = new Request;
        $request->headers->set('Accept', 'text/html;q=0.9, application/json;q=0.8, text/plain;q=0.7');

        $middleware = new ReorderJsonAccept;

        $middleware->handle($request, fn (Request $request): Response => new Response('test'));

        $accept = $request->header('Accept');
        $parts = array_map(trim(...), explode(',', $accept));

        $this->assertSame('application/json;q=0.8', $parts[0]);
    }

    public function testHandlesWhitespaceInAcceptHeader(): void
    {
        $request = new Request;
        $request->headers->set('Accept', '  text/html  ,  application/json  ,  text/plain  ');

        $middleware = new ReorderJsonAccept;

        $middleware->handle($request, fn (Request $request): Response => new Response('test'));

        $this->assertSame('application/json, text/html, text/plain', $request->header('Accept'));
    }

    public function testHandlesNoJsonInAcceptHeader(): void
    {
        $request = new Request;
        $request->headers->set('Accept', 'text/html, text/plain, image/png');

        $middleware = new ReorderJsonAccept;

        $middleware->handle($request, fn (Request $request): Response => new Response('test'));

        $this->assertSame('text/html, text/plain, image/png', $request->header('Accept'));
    }

    public function testHandlesEmptyAcceptHeader(): void
    {
        $request = new Request;
        $request->headers->set('Accept', '');

        $middleware = new ReorderJsonAccept;

        $middleware->handle($request, fn (Request $request): Response => new Response('test'));

        $this->assertSame('', $request->header('Accept'));
    }

    public function testHandlesMissingAcceptHeader(): void
    {
        $request = new Request;

        $middleware = new ReorderJsonAccept;

        $response = $middleware->handle($request, fn (Request $request): Response => new Response('test'));

        $this->assertSame('test', $response->getContent());
    }

    public function testPassesRequestThroughMiddlewareCorrectly(): void
    {
        $request = new Request;
        $request->headers->set('Accept', 'text/html, application/json');

        $middleware = new ReorderJsonAccept;

        $response = $middleware->handle($request, function (Request $request): Response {
            $this->assertSame('application/json, text/html', $request->header('Accept'));

            return new Response('middleware worked');
        });

        $this->assertSame('middleware worked', $response->getContent());
    }
}
