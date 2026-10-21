<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Unit;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Saloon\SaloonServiceProvider;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Saloon\Fixtures\Connectors\QueryParameterConnector;
use Hypervel\Tests\Saloon\Fixtures\Connectors\TestConnector;
use Hypervel\Tests\Saloon\Fixtures\Requests\HasJsonBodyRequest;
use Hypervel\Tests\Saloon\Fixtures\Requests\QueryParameterRequest;
use Hypervel\Tests\Saloon\Fixtures\Requests\UserRequest;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;

class PsrTest extends TestCase
{
    /**
     * Get the package providers.
     */
    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [SaloonServiceProvider::class];
    }

    public function testAPsr7RequestCanBeCreatedFromThePendingRequest(): void
    {
        $connector = new TestConnector;
        $request = new UserRequest;

        $pendingRequest = $connector->createPendingRequest($request);
        $request = $pendingRequest->createPsrRequest();

        $this->assertInstanceOf(RequestInterface::class, $request);
        $this->assertInstanceOf(UriInterface::class, $request->getUri());
        $this->assertSame('https://tests.saloon.dev/api/user', (string) $request->getUri());
        $this->assertSame('GET', $request->getMethod());
        $this->assertSame([
            'Host' => ['tests.saloon.dev'],
            'Accept' => ['application/json'],
        ], $request->getHeaders());

        $this->assertSame('1.1', $request->getProtocolVersion());
    }

    public function testIfRequestBodyIsPresentThenItWillBeOnThePsr7Request(): void
    {
        $connector = new TestConnector;
        $request = new HasJsonBodyRequest;

        $pendingRequest = $connector->createPendingRequest($request);
        $request = $pendingRequest->createPsrRequest();

        $body = $request->getBody();

        $this->assertInstanceOf(StreamInterface::class, $body);
        $this->assertSame('{"name":"Sam","catchphrase":"Yeehaw!"}', $body->getContents());
    }

    public function testYouCanGenerateAUriFromThePendingRequest(): void
    {
        $connector = new QueryParameterConnector;
        $request = new QueryParameterRequest('/user?include=hats#fragment-123');

        $pendingRequest = $connector->createPendingRequest($request);
        $uri = $pendingRequest->uri();

        $this->assertInstanceOf(UriInterface::class, $uri);

        $this->assertSame('https://tests.saloon.dev/api/user?include=hats&sort=first_name&per_page=100#fragment-123', (string) $uri);
        $this->assertSame('https', $uri->getScheme());
        $this->assertSame('tests.saloon.dev', $uri->getHost());
        $this->assertSame('/api/user', $uri->getPath());
        $this->assertSame('include=hats&sort=first_name&per_page=100', $uri->getQuery());
        $this->assertSame('fragment-123', $uri->getFragment());
    }

    public function testWhenUsingTheUrlForQueryParametersYouCanUseDotsAndValueLessParameters(): void
    {
        $connector = new TestConnector;
        $request = new QueryParameterRequest('/user?account.id=1&checked&name=sam');

        $pendingRequest = $connector->createPendingRequest($request);
        $uri = $pendingRequest->uri();

        // Query pairs in the endpoint are kept as written, so the value-less parameter is not rewritten as
        // upstream's "checked=".
        $this->assertSame('account.id=1&checked&name=sam&per_page=100', $uri->getQuery());
    }
}
