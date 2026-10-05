<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Unit\RequestProperties;

use Hypervel\Contracts\Cache\Factory as CacheFactory;
use Hypervel\RateLimiter\RateLimiter;
use Hypervel\Saloon\Http\Connector;
use Hypervel\Saloon\Http\PendingRequest;
use Hypervel\Saloon\Http\Request;
use Hypervel\Tests\Saloon\Fixtures\Connectors\QueryParameterConnector;
use Hypervel\Tests\Saloon\Fixtures\Requests\QueryParameterConnectorBlankRequest;
use Hypervel\Tests\Saloon\Fixtures\Requests\QueryParameterConnectorRequest;
use Hypervel\Tests\Saloon\Fixtures\Requests\QueryParameterRequest;
use Hypervel\Tests\TestCase;
use Mockery as m;

class QueryTest extends TestCase
{
    public function testDefaultQueryParametersAreMergedInFromARequest(): void
    {
        $request = new QueryParameterRequest;

        $this->assertSame(['per_page' => 100], $request->queryParameters());
    }

    public function testQueryParametersCanBeManagedOnARequest(): void
    {
        $request = new QueryParameterRequest;

        $this->assertSame($request, $request->withQueryParameters(['page' => 1]));
        $this->assertSame($request, $request->withQueryParameters(['search' => 'Sam', 'category' => 'Cowboy', 'per_page' => 200]));
        $this->assertSame($request, $request->withoutQueryParameters('category'));

        $this->assertSame([
            'per_page' => 200,
            'page' => 1,
            'search' => 'Sam',
        ], $request->queryParameters());
        $this->assertSame(1, $request->queryParameters()['page']);

        // Upstream's set() replaces every parameter; there is no replace-all method, so remove the parameters first.
        $request->withoutQueryParameters(array_keys($request->queryParameters()))->withQueryParameters(['debug' => true]);

        $this->assertSame(['debug' => true], $request->queryParameters());
    }

    public function testQueryParametersCanBeManagedOnAConnector(): void
    {
        // Connectors are read-only and may be shared between coroutines, so their default query parameters are
        // managed on the pending request that merges them.
        $connector = new QueryParameterConnector;
        $pendingRequest = $this->pendingRequest($connector, new QueryParameterConnectorBlankRequest);

        $pendingRequest->withQueryParameters(['page' => 1]);
        $pendingRequest->withQueryParameters(['search' => 'Sam', 'category' => 'Cowboy', 'sort' => 'last_name']);
        $pendingRequest->withoutQueryParameters('category');

        $this->assertSame([
            'sort' => 'last_name',
            'page' => 1,
            'search' => 'Sam',
        ], $pendingRequest->queryParameters());
        $this->assertSame(1, $pendingRequest->queryParameters()['page']);

        $pendingRequest
            ->withoutQueryParameters(array_keys($pendingRequest->queryParameters()))
            ->withQueryParameters(['debug' => true]);

        $this->assertSame(['debug' => true], $pendingRequest->queryParameters());
        $this->assertSame(['sort' => 'first_name'], $connector->queryParameters());
    }

    public function testRemovingAQueryParameterFromARequestDoesNotRemoveConnectorDefaults(): void
    {
        $request = (new QueryParameterConnectorRequest)->withoutQueryParameters('sort');
        $pendingRequest = $this->pendingRequest(new QueryParameterConnector, $request);

        $this->assertSame(['sort' => 'first_name', 'include' => 'user'], $pendingRequest->queryParameters());

        $pendingRequest->withoutQueryParameters(['sort']);

        $this->assertSame(['include' => 'user'], $pendingRequest->queryParameters());
    }

    public function testRemovingAQueryParameterInvalidatesTheFinalizedUri(): void
    {
        $pendingRequest = $this->pendingRequest(new QueryParameterConnector, new QueryParameterConnectorRequest)
            ->finalizeUri();

        $this->assertSame('sort=first_name&include=user', $pendingRequest->uri()->getQuery());

        $pendingRequest->withoutQueryParameters('sort');

        $this->assertSame('include=user', $pendingRequest->uri()->getQuery());

        $pendingRequest->finalizeUri()->withQueryParameters(['sort' => 'nickname']);

        $this->assertSame('include=user&sort=nickname', $pendingRequest->uri()->getQuery());
    }

    public function testRemovingAQueryParameterKeepsValuesWrittenInTheEndpointOrQueryString(): void
    {
        $pendingRequest = $this->pendingRequest(
            new QueryParameterConnector,
            new QueryParameterRequest('/user?sort=endpoint'),
        );

        $this->assertSame('sort=first_name&per_page=100', $pendingRequest->uri()->getQuery());

        $pendingRequest->withoutQueryParameters('sort');

        $this->assertSame('sort=endpoint&per_page=100', $pendingRequest->uri()->getQuery());

        $pendingRequest->withQueryString('sort=raw')->withoutQueryParameters('per_page');

        $this->assertSame('sort=raw', $pendingRequest->uri()->getQuery());
    }

    /**
     * Create a pending request with isolated framework dependencies.
     */
    protected function pendingRequest(Connector $connector, Request $request): PendingRequest
    {
        return new PendingRequest(
            $connector,
            $request,
            m::mock(CacheFactory::class),
            m::mock(RateLimiter::class),
        );
    }
}
