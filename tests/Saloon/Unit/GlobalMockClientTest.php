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

// The global mock client lives on the Saloon manager rather than in a static property, so it is cleared with the test
// application instead of by an afterEach destroyGlobal() call.
class GlobalMockClientTest extends TestCase
{
    /**
     * Get the package providers.
     */
    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [SaloonServiceProvider::class];
    }

    public function testCanCreateAGlobalMockClient(): void
    {
        $mockClient = MockClient::global([
            MockResponse::make(['name' => 'Sam']),
        ]);

        $this->assertInstanceOf(MockClient::class, $mockClient);
        $this->assertSame($mockClient, MockClient::getGlobal());
        $this->assertSame($mockClient, Saloon::mockClient());

        $connector = new TestConnector;
        $response = $connector->send(new UserRequest);

        $this->assertTrue($response->isMocked());
        $this->assertSame(['name' => 'Sam'], $response->json());

        $mockClient->assertSent(UserRequest::class);
    }

    public function testTheMockClientCanBeDestroyed(): void
    {
        $mockClient = MockClient::global();

        $this->assertSame($mockClient, MockClient::getGlobal());

        MockClient::destroyGlobal();

        $this->assertNull(MockClient::getGlobal());
    }

    public function testALocalMockClientIsGivenPriorityOverTheGlobalMockClient(): void
    {
        MockClient::global([
            MockResponse::make(['name' => 'Sam']),
        ]);

        $localMockClient = new MockClient([
            MockResponse::make(['name' => 'Taylor']),
        ]);

        $request = (new UserRequest)->withMockClient($localMockClient);

        $response = (new TestConnector)->send($request);

        $this->assertTrue($response->isMocked());
        $this->assertSame(['name' => 'Taylor'], $response->json());

        $localMockClient->assertSentCount(1);
        MockClient::global()->assertNothingSent();
    }
}
