<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Unit;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Saloon\Http\Faking\MockClient;
use Hypervel\Saloon\Http\Faking\MockResponse;
use Hypervel\Saloon\Http\Response;
use Hypervel\Saloon\SaloonServiceProvider;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Saloon\Fixtures\Connectors\RequestSelectionConnector;
use Hypervel\Tests\Saloon\Fixtures\Connectors\TestConnector;
use Hypervel\Tests\Saloon\Fixtures\Requests\HasConnectorUserRequest;
use Hypervel\Tests\Saloon\Fixtures\Requests\UserRequest;

class ConnectorTest extends TestCase
{
    /**
     * Get the package providers.
     */
    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [SaloonServiceProvider::class];
    }

    public function testAConnectorClassCanBeInstantiatedUsingTheMakeMethod(): void
    {
        $connectorA = TestConnector::make();

        $this->assertInstanceOf(TestConnector::class, $connectorA);

        $connectorB = RequestSelectionConnector::make('yee-haw-1-2-3');

        $this->assertInstanceOf(RequestSelectionConnector::class, $connectorB);
        $this->assertSame('yee-haw-1-2-3', $connectorB->apiKey);
    }

    public function testTheSameConnectorInstanceIsKeptIfYouInstantiateItOnTheRequestWithHasConnector(): void
    {
        $request = new HasConnectorUserRequest;
        $connector = $request->connector();

        $this->assertInstanceOf(TestConnector::class, $connector);
        $this->assertSame($connector, $request->connector());

        $replacement = new TestConnector('https://other.example.com');

        $this->assertSame($request, $request->setConnector($replacement));
        $this->assertSame($replacement, $request->connector());
    }

    public function testYouCanSendARequestThroughTheConnector(): void
    {
        $mockClient = new MockClient([
            new MockResponse(['name' => 'Sammyjo20', 'actual_name' => 'Sam Carré', 'twitter' => '@carre_sam']),
        ]);

        $connector = new TestConnector;
        $response = $connector->send(new UserRequest, $mockClient);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(['name' => 'Sammyjo20', 'actual_name' => 'Sam Carré', 'twitter' => '@carre_sam'], $response->json());
    }

    // REMOVED: "you can send an asynchronous request through the connector". Coroutine pools replace promises and sendAsync().

    public function testConnectorsAreMacroable(): void
    {
        TestConnector::macro('apiUrl', function (): string {
            return $this->resolveBaseUrl();
        });

        $this->assertSame(TestConnector::API_URL, (new TestConnector)->apiUrl());
    }
}
