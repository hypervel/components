<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Hypervel\Feature;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Saloon\Exceptions\NoMockResponseFoundException;
use Hypervel\Saloon\Facades\Saloon;
use Hypervel\Saloon\Http\Faking\MockClient;
use Hypervel\Saloon\Http\Faking\MockResponse;
use Hypervel\Saloon\Http\PendingRequest;
use Hypervel\Saloon\SaloonServiceProvider;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Saloon\Fixtures\Connectors\DifferentServiceConnector;
use Hypervel\Tests\Saloon\Fixtures\Connectors\QueryParameterConnector;
use Hypervel\Tests\Saloon\Fixtures\Connectors\TestConnector;
use Hypervel\Tests\Saloon\Fixtures\Requests\DifferentServiceUserRequest;
use Hypervel\Tests\Saloon\Fixtures\Requests\ErrorRequest;
use Hypervel\Tests\Saloon\Fixtures\Requests\QueryParameterConnectorRequest;
use Hypervel\Tests\Saloon\Fixtures\Requests\UserRequest;

// The plugin's fixtures are older copies of the core fixtures, so these tests use the core ones.
// REMOVED: Feature/ResponseRecorderTest - the deprecated record(), stopRecording(), isRecording(), recordResponse(),
// getRecordedResponses() and getLastRecordedResponse() methods are not ported. recorded() lists the responses recorded
// by the active mock client, and the SentSaloonRequest event observes every sent response.
class MockRequestTest extends TestCase
{
    /**
     * Get the package providers.
     */
    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [SaloonServiceProvider::class];
    }

    public function testARequestCanBeMockedWithASequence(): void
    {
        Saloon::fake([
            new MockResponse(['name' => 'Sam'], 200),
            new MockResponse(['name' => 'Alex'], 200),
            new MockResponse(['error' => 'Server Unavailable'], 500),
        ]);

        $connector = new TestConnector;

        $responseA = $connector->send(new UserRequest);

        $this->assertTrue($responseA->isMocked());
        $this->assertSame(['name' => 'Sam'], $responseA->json());
        $this->assertSame(200, $responseA->status());

        $responseB = $connector->send(new UserRequest);

        $this->assertTrue($responseB->isMocked());
        $this->assertSame(['name' => 'Alex'], $responseB->json());
        $this->assertSame(200, $responseB->status());

        $responseC = $connector->send(new UserRequest);

        $this->assertTrue($responseC->isMocked());
        $this->assertSame(['error' => 'Server Unavailable'], $responseC->json());
        $this->assertSame(500, $responseC->status());

        $this->expectException(NoMockResponseFoundException::class);

        $connector->send(new UserRequest);
    }

    public function testARequestCanBeMockedWithASequenceUsingAClosure(): void
    {
        Saloon::fake([
            function (PendingRequest $request): MockResponse {
                return new MockResponse(['request' => (string) $request->uri()]);
            },
        ]);

        $responseA = TestConnector::make()->send(new UserRequest);

        $this->assertTrue($responseA->isMocked());
        $this->assertSame(['request' => 'https://tests.saloon.dev/api/user'], $responseA->json());
        $this->assertSame(200, $responseA->status());
    }

    public function testARequestCanBeMockedWithAConnectorDefined(): void
    {
        $responseA = new MockResponse(['name' => 'Sammyjo20']);
        $responseB = new MockResponse(['name' => 'Alex']);

        $connectorA = new TestConnector;
        $connectorB = new QueryParameterConnector;

        $connectorARequest = new UserRequest;
        $connectorBRequest = new QueryParameterConnectorRequest;

        Saloon::fake([
            TestConnector::class => $responseA,
            QueryParameterConnector::class => $responseB,
        ]);

        $responseA = $connectorA->send($connectorARequest);

        $this->assertTrue($responseA->isMocked());
        $this->assertSame(['name' => 'Sammyjo20'], $responseA->json());
        $this->assertSame(200, $responseA->status());

        $responseB = $connectorB->send($connectorBRequest);

        $this->assertTrue($responseB->isMocked());
        $this->assertSame(['name' => 'Alex'], $responseB->json());
        $this->assertSame(200, $responseB->status());
    }

    public function testARequestCanBeMockedWithAConnectorDefinedUsingAClosure(): void
    {
        Saloon::fake([
            TestConnector::class => function (PendingRequest $request): MockResponse {
                return new MockResponse(['request' => (string) $request->uri()]);
            },
        ]);

        $responseA = TestConnector::make()->send(new UserRequest);

        $this->assertTrue($responseA->isMocked());
        $this->assertSame(['request' => 'https://tests.saloon.dev/api/user'], $responseA->json());
        $this->assertSame(200, $responseA->status());
    }

    public function testARequestCanBeMockedWithARequestDefined(): void
    {
        $responseA = new MockResponse(['name' => 'Sammyjo20']);
        $responseB = new MockResponse(['name' => 'Alex']);

        $connectorA = new TestConnector;
        $connectorB = new QueryParameterConnector;

        $requestA = new UserRequest;
        $requestB = new QueryParameterConnectorRequest;

        Saloon::fake([
            UserRequest::class => $responseA,
            QueryParameterConnectorRequest::class => $responseB,
        ]);

        $responseA = $connectorA->send($requestA);

        $this->assertTrue($responseA->isMocked());
        $this->assertSame(['name' => 'Sammyjo20'], $responseA->json());
        $this->assertSame(200, $responseA->status());

        $responseB = $connectorB->send($requestB);

        $this->assertTrue($responseB->isMocked());
        $this->assertSame(['name' => 'Alex'], $responseB->json());
        $this->assertSame(200, $responseB->status());
    }

    public function testARequestCanBeMockedWithARequestDefinedUsingAClosure(): void
    {
        Saloon::fake([
            UserRequest::class => function (PendingRequest $request): MockResponse {
                return new MockResponse(['request' => (string) $request->uri()]);
            },
        ]);

        $responseA = TestConnector::make()->send(new UserRequest);

        $this->assertTrue($responseA->isMocked());
        $this->assertSame(['request' => 'https://tests.saloon.dev/api/user'], $responseA->json());
        $this->assertSame(200, $responseA->status());
    }

    public function testARequestCanBeMockedWithAUrlDefined(): void
    {
        $responseA = new MockResponse(['name' => 'Sammyjo20']);
        $responseB = new MockResponse(['name' => 'Alex']);
        $responseC = new MockResponse(['error' => 'Server Broken'], 500);

        $connectorA = new TestConnector;
        $connectorB = new DifferentServiceConnector;

        $requestA = new UserRequest;
        $requestB = new ErrorRequest;
        $requestC = new DifferentServiceUserRequest;

        Saloon::fake([
            'tests.saloon.dev/api/user' => $responseA, // Test Exact Route
            'tests.saloon.dev/*' => $responseB, // Test Wildcard Routes
            'google.com/*' => $responseC, // Test Different Route,
        ]);

        $responseA = $connectorA->send($requestA);

        $this->assertTrue($responseA->isMocked());
        $this->assertSame(['name' => 'Sammyjo20'], $responseA->json());
        $this->assertSame(200, $responseA->status());

        $responseB = $connectorA->send($requestB);

        $this->assertTrue($responseB->isMocked());
        $this->assertSame(['name' => 'Alex'], $responseB->json());
        $this->assertSame(200, $responseB->status());

        $responseC = $connectorB->send($requestC);

        $this->assertTrue($responseC->isMocked());
        $this->assertSame(['error' => 'Server Broken'], $responseC->json());
        $this->assertSame(500, $responseC->status());
    }

    public function testYouCanCreateWildcardUrlMocks(): void
    {
        $responseA = new MockResponse(['name' => 'Sammyjo20']);
        $responseB = new MockResponse(['name' => 'Alex']);
        $responseC = new MockResponse(['error' => 'Server Broken'], 500);

        $connectorA = new TestConnector;
        $connectorB = new DifferentServiceConnector;

        $requestA = new UserRequest;
        $requestB = new ErrorRequest;
        $requestC = new DifferentServiceUserRequest;

        Saloon::fake([
            'tests.saloon.dev/api/user' => $responseA, // Test Exact Route
            'tests.saloon.dev/*' => $responseB, // Test Wildcard Routes
            '*' => $responseC,
        ]);

        $responseA = $connectorA->send($requestA);

        $this->assertTrue($responseA->isMocked());
        $this->assertSame(['name' => 'Sammyjo20'], $responseA->json());
        $this->assertSame(200, $responseA->status());

        $responseB = $connectorA->send($requestB);

        $this->assertTrue($responseB->isMocked());
        $this->assertSame(['name' => 'Alex'], $responseB->json());
        $this->assertSame(200, $responseB->status());

        $responseC = $connectorB->send($requestC);

        $this->assertTrue($responseC->isMocked());
        $this->assertSame(['error' => 'Server Broken'], $responseC->json());
        $this->assertSame(500, $responseC->status());
    }

    public function testARequestCanBeMockedWithAUrlDefinedUsingAClosure(): void
    {
        Saloon::fake([
            'tests.saloon.dev/*' => function (PendingRequest $request): MockResponse {
                return new MockResponse(['request' => (string) $request->uri()]);
            },
        ]);

        $responseA = TestConnector::make()->send(new UserRequest);

        $this->assertTrue($responseA->isMocked());
        $this->assertSame(['request' => 'https://tests.saloon.dev/api/user'], $responseA->json());
        $this->assertSame(200, $responseA->status());
    }

    public function testTheSameGlobalMockClientIsReusedOnFurtherCalls(): void
    {
        $mockClientA = Saloon::fake([
            new MockResponse(['name' => 'Sam'], 200),
        ]);

        $mockClientB = Saloon::fake([
            new MockResponse(['name' => 'Alex'], 200),
        ]);

        $this->assertEquals(new MockClient([
            new MockResponse(['name' => 'Sam'], 200),
            new MockResponse(['name' => 'Alex'], 200),
        ]), MockClient::getGlobal());

        $this->assertSame($mockClientA, $mockClientB);
        $this->assertSame($mockClientA, Saloon::mockClient());
        $this->assertSame($mockClientB, Saloon::mockClient());
    }

    public function testFakingAgainKeepsTheGlobalMockClientsRecordsAndSettings(): void
    {
        $mockClient = Saloon::fake([
            new MockResponse(['name' => 'Sam']),
        ]);

        $responseA = TestConnector::make()->send(new UserRequest);
        $mockClient->withoutCache();

        Saloon::fake([
            new MockResponse(['name' => 'Alex']),
        ]);

        $responseB = TestConnector::make()->send(new UserRequest);

        $this->assertSame(['name' => 'Alex'], $responseB->json());
        $this->assertSame($mockClient, Saloon::mockClient());
        $this->assertTrue($mockClient->shouldBypassResponseCache());
        $this->assertSame([$responseA, $responseB], $mockClient->recorded()->all());
    }

    public function testAnExplicitMockClientReplacesTheGlobalMockClient(): void
    {
        $originalClient = Saloon::fake([
            new MockResponse(['name' => 'Sam']),
        ]);
        $replacementClient = new MockClient([
            new MockResponse(['name' => 'Alex']),
        ]);

        $this->assertSame($replacementClient, Saloon::fake($replacementClient));
        $this->assertSame($replacementClient, Saloon::mockClient());

        $response = TestConnector::make()->send(new UserRequest);

        $this->assertSame(['name' => 'Alex'], $response->json());
        $this->assertCount(1, $replacementClient->recorded());
        $this->assertCount(0, $originalClient->recorded());
    }
}
