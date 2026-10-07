<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Unit;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Saloon\Http\Connector;
use Hypervel\Saloon\Http\Faking\MockClient;
use Hypervel\Saloon\Http\Faking\MockResponse;
use Hypervel\Saloon\Http\Request;
use Hypervel\Saloon\Http\Response;
use Hypervel\Saloon\SaloonServiceProvider;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Saloon\Fixtures\Connectors\QueryParameterConnector;
use Hypervel\Tests\Saloon\Fixtures\Connectors\TestConnector;
use Hypervel\Tests\Saloon\Fixtures\Requests\OverwrittenQueryParameterConnectorRequest;
use Hypervel\Tests\Saloon\Fixtures\Requests\QueryParameterConnectorBlankRequest;
use Hypervel\Tests\Saloon\Fixtures\Requests\QueryParameterConnectorRequest;
use Hypervel\Tests\Saloon\Fixtures\Requests\QueryParameterRequest;
use Hypervel\Tests\Saloon\Fixtures\Requests\UserRequest;

class QueryParameterTest extends TestCase
{
    /**
     * Get the package providers.
     */
    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [SaloonServiceProvider::class];
    }

    public function testARequestWithQueryParamsAddedSendsTheQueryParams(): void
    {
        $request = new QueryParameterRequest;

        $request->withQueryParameters(['sort' => '-created_at']);

        $response = $this->send(new TestConnector, $request);
        $query = $response->pendingRequest()->queryParameters();

        $this->assertSame(100, $query['per_page']); // Test Default
        $this->assertSame('-created_at', $query['sort']); // Test Adding Data After
        $this->assertSame('per_page=100&sort=-created_at', $response->toPsrRequest()->getUri()->getQuery());
    }

    public function testIfSetQueryIsUsedAllOtherDefaultQueryParamsWontBeIncluded(): void
    {
        $request = new QueryParameterConnectorRequest;

        // Upstream's set() replaces every parameter; removing the defaults before adding is the equivalent.
        $request->withoutQueryParameters(array_keys($request->queryParameters()))->withQueryParameters([
            'sort' => 'nickname',
        ]);

        $query = $this->send(new TestConnector, $request)->pendingRequest()->queryParameters();

        $this->assertSame(['sort' => 'nickname'], $query);
    }

    public function testAConnectorCanHaveQueryThatIsSet(): void
    {
        $request = new QueryParameterConnectorRequest;

        $query = $this->send(new QueryParameterConnector, $request)->pendingRequest()->queryParameters();

        $this->assertSame('first_name', $query['sort']); // Added by connector
        $this->assertSame('user', $query['include']); // Added by request
    }

    public function testARequestQueryParameterCanOverwriteAConnectorsParameter(): void
    {
        $request = new OverwrittenQueryParameterConnectorRequest;

        // Upstream sends this through a connector without query parameters, so nothing is overwritten.
        $query = $this->send(new QueryParameterConnector, $request)->pendingRequest()->queryParameters();

        $this->assertSame(['sort' => 'date_of_birth'], $query);
    }

    public function testManuallyOverwritingQueryParameterInRuntimeCanOverwriteConnectorParameter(): void
    {
        $request = new QueryParameterConnectorBlankRequest;

        $request->withQueryParameters(['sort' => 'custom_field']);

        // Upstream sends this through a connector without query parameters, so nothing is overwritten.
        $query = $this->send(new QueryParameterConnector, $request)->pendingRequest()->queryParameters();

        $this->assertSame(['sort' => 'custom_field'], $query);
    }

    public function testManuallyOverwritingQueryParameterInRuntimeCanOverwriteRequestParameter(): void
    {
        $request = new QueryParameterRequest;

        $request->withQueryParameters(['per_page' => 500]);

        $query = $this->send(new TestConnector, $request)->pendingRequest()->queryParameters();

        $this->assertSame(['per_page' => 500], $query);
    }

    public function testWhenNotSendingQueryParametersTheQueryOptionIsNotSet(): void
    {
        $request = new UserRequest;

        $response = $this->send(new TestConnector, $request);

        $this->assertSame([], $response->pendingRequest()->queryParameters());
        $this->assertSame('', $response->toPsrRequest()->getUri()->getQuery());
    }

    /**
     * Send a request with a mocked response.
     */
    protected function send(Connector $connector, Request $request): Response
    {
        return $connector->send($request, new MockClient([MockResponse::make()]));
    }
}
