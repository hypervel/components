<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentry\Http;

use Hypervel\Context\RequestContext;
use Hypervel\Http\Request;
use Hypervel\Routing\Router;
use Hypervel\Sentry\Http\HypervelRequestFetcher;
use Hypervel\Tests\Sentry\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use ReflectionProperty;

class HypervelRequestFetcherTest extends TestCase
{
    /**
     * Define the test routes.
     */
    protected function defineRoutes(Router $router): void
    {
        $router->get('/', function (): string {
            return 'Hello!';
        });
    }

    public function testPsr7InstanceCanBeResolved(): void
    {
        // Make a request so RequestContext is populated
        $this->get('/');

        $fetcher = new HypervelRequestFetcher;
        $request = $fetcher->fetchRequest();

        $this->assertInstanceOf(ServerRequestInterface::class, $request);
    }

    public function testEmptyParsedBodyIsDroppedForRequestsThatAreNotForms(): void
    {
        $request = Request::create('http://localhost/', 'POST', server: ['CONTENT_TYPE' => 'text/plain']);

        $this->assertNull($this->fetchRequest($request)->getParsedBody());
    }

    public function testEmptyParsedBodyIsKeptForForms(): void
    {
        $request = Request::create('http://localhost/', 'POST', server: ['CONTENT_TYPE' => 'Multipart/Form-Data; boundary=foo']);

        $this->assertSame([], $this->fetchRequest($request)->getParsedBody());
    }

    public function testParsedBodyIsKept(): void
    {
        $request = Request::create('http://localhost/', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: '{"foo":"bar"}');

        $this->assertSame(['foo' => 'bar'], $this->fetchRequest($request)->getParsedBody());
    }

    public function testParsedBodyIsDroppedForRequestsThatAreNotFormsWithDataCollection(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.data_collection' => [],
        ]);

        // The PSR-7 bridge uses the decoded payload as the parsed body of JSON requests
        $request = Request::create('http://localhost/', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: '{"token":"secret"}');

        $this->assertNull($this->fetchRequest($request)->getParsedBody());
    }

    public function testParsedBodyIsKeptForFormsWithDataCollection(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.data_collection' => [],
        ]);

        $request = Request::create('http://localhost/', 'POST', ['foo' => 'bar'], server: ['CONTENT_TYPE' => 'application/x-www-form-urlencoded']);

        $this->assertSame(['foo' => 'bar'], $this->fetchRequest($request)->getParsedBody());
    }

    public function testRawQueryStringIsUsedWithDataCollection(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.data_collection' => [],
        ]);

        $request = Request::create('http://localhost/?q=a+b&page=5');

        $this->assertSame('q=a+b&page=5', $this->fetchRequest($request)->getUri()->getQuery());
    }

    public function testQueryStringIsNotChangedWithoutDataCollection(): void
    {
        $request = Request::create('http://localhost/?q=a+b&page=5');

        $this->assertSame('q=a+b&page=5', $this->fetchRequest($request)->getUri()->getQuery());
    }

    public function testCookiesWithNumericNamesAreFetched(): void
    {
        $request = Request::create('http://localhost/', cookies: ['123' => 'value']);

        $this->assertSame([123 => 'value'], $this->fetchRequest($request)->getCookieParams());
    }

    public function testFlushStateClearsPsrHttpFactoryCache(): void
    {
        $this->get('/');

        $fetcher = new HypervelRequestFetcher;
        $fetcher->fetchRequest();

        $property = new ReflectionProperty(HypervelRequestFetcher::class, 'psrHttpFactory');

        $this->assertNotNull($property->getValue());

        HypervelRequestFetcher::flushState();

        $this->assertNull($property->getValue());
    }

    /**
     * Fetch the given request as the current request.
     */
    private function fetchRequest(Request $request): ServerRequestInterface
    {
        RequestContext::set($request);

        $fetchedRequest = (new HypervelRequestFetcher)->fetchRequest();

        $this->assertNotNull($fetchedRequest);

        return $fetchedRequest;
    }
}
