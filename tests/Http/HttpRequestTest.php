<?php

declare(strict_types=1);

namespace Hypervel\Tests\Http;

use Hypervel\Http\Request;
use Hypervel\Routing\Route;
use Hypervel\Tests\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

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

    public function testJsonRequestFillsRequestBodyParams(): void
    {
        $body = [
            'foo' => 'bar',
            'baz' => ['qux'],
        ];

        $server = [
            'CONTENT_TYPE' => 'application/json',
        ];

        $base = SymfonyRequest::create('/', 'GET', [], [], [], $server, json_encode($body));

        $request = Request::createFromBase($base);

        $this->assertEquals($request->request->all(), $body);
    }

    public function testGeneratingJsonRequestFromParentRequestUsesCorrectType(): void
    {
        $base = SymfonyRequest::create('/', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: '{"hello":"world"}');

        $request = Request::createFromBase($base);

        $this->assertInstanceOf(InputBag::class, $request->getPayload());
        $this->assertSame('world', $request->getPayload()->get('hello'));
    }

    public function testCreatingJsonRequestFromBaseDoesNotTriggerRequestPropertyDeprecation(): void
    {
        $request = Request::createFromBase(
            SymfonyRequest::create('/', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: '{"hello":"world"}')
        );

        $this->assertTrue($request->isJson());
        $this->assertSame('world', $request->input('hello'));
    }

    public function testJsonRequestsCanMergeDataIntoJsonRequest(): void
    {
        $base = SymfonyRequest::create('/', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: '{"first":"Taylor","last":"Otwell"}');
        $request = Request::createFromBase($base);

        $request->merge([
            'name' => $request->input('first') . ' ' . $request->input('last'),
        ]);

        $this->assertSame('Taylor Otwell', $request->input('name'));
    }

    // REMOVED: Request::get() mixes unrelated input sources; callers select
    // request input, query parameters, or route parameters explicitly.
    public function testInputPrefersRequestBodyOverQueryParameters(): void
    {
        $request = Request::create('/?name=query', 'POST', ['name' => 'body']);

        $this->assertSame('body', $request->input('name'));
        $this->assertSame('query', $request->query('name'));
    }
}
