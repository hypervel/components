<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Unit;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Saloon\Facades\Saloon;
use Hypervel\Saloon\Http\Faking\MockClient;
use Hypervel\Saloon\Http\Faking\MockResponse;
use Hypervel\Saloon\SaloonServiceProvider;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Saloon\Fixtures\Connectors\TestConnector;
use Hypervel\Tests\Saloon\Fixtures\Requests\UserRequest;

// Connectors are read-only and take no mock client, so the global fake stands in for upstream's connector client.
class MocksRequestsTest extends TestCase
{
    /**
     * Get the package providers.
     */
    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [SaloonServiceProvider::class];
    }

    public function testYouCanProvideAGlobalMockClientAndAllRequestsWillBeMocked(): void
    {
        Saloon::fake([
            MockResponse::make(['name' => 'Sam']),
            MockResponse::make(['name' => 'Mantas']),
        ]);

        $connector = new TestConnector;

        $responseA = $connector->send(new UserRequest);
        $responseB = $connector->send(new UserRequest);

        $this->assertTrue($responseA->isMocked());
        $this->assertTrue($responseB->isMocked());
    }

    public function testYouCanProvideAMockClientOnARequestAndAllRequestsWillBeMocked(): void
    {
        $mockClient = new MockClient([
            MockResponse::make(['name' => 'Sam']),
        ]);

        $request = new UserRequest;
        $request->withMockClient($mockClient);

        $response = (new TestConnector)->send($request);

        $this->assertTrue($response->isMocked());
    }

    public function testRequestMockClientsAreAlwaysPrioritized(): void
    {
        Saloon::fake([
            MockResponse::make(['name' => 'Sam']),
        ]);

        $mockClientB = new MockClient([
            MockResponse::make(['name' => 'Mantas']),
        ]);

        $request = new UserRequest;
        $request->withMockClient($mockClientB);

        $response = (new TestConnector)->send($request);

        $this->assertTrue($response->isMocked());
        $this->assertSame(['name' => 'Mantas'], $response->json());
    }

    public function testAMockClientPassedToSendTakesPrecedenceOverTheRequestClient(): void
    {
        $requestClient = new MockClient([MockResponse::make(['client' => 'request'])]);
        $explicitClient = new MockClient([MockResponse::make(['client' => 'explicit'])]);
        $request = (new UserRequest)->withMockClient($requestClient);

        $this->assertSame(['client' => 'explicit'], (new TestConnector)->send($request, $explicitClient)->json());
        $this->assertSame(['client' => 'request'], (new TestConnector)->send($request)->json());
    }
}
