<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Unit;

use Exception;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Saloon\Exceptions\NoMockResponseFoundException;
use Hypervel\Saloon\Http\Faking\MockClient;
use Hypervel\Saloon\Http\Faking\MockResponse;
use Hypervel\Saloon\Http\PendingRequest;
use Hypervel\Saloon\Http\Request;
use Hypervel\Saloon\Http\Response;
use Hypervel\Saloon\SaloonServiceProvider;
use Hypervel\Support\Facades\Http;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Saloon\Fixtures\Connectors\DifferentServiceConnector;
use Hypervel\Tests\Saloon\Fixtures\Connectors\QueryParameterConnector;
use Hypervel\Tests\Saloon\Fixtures\Connectors\TestConnector;
use Hypervel\Tests\Saloon\Fixtures\Exceptions\TestResponseException;
use Hypervel\Tests\Saloon\Fixtures\Requests\DifferentServiceUserRequest;
use Hypervel\Tests\Saloon\Fixtures\Requests\ErrorRequest;
use Hypervel\Tests\Saloon\Fixtures\Requests\QueryParameterConnectorRequest;
use Hypervel\Tests\Saloon\Fixtures\Requests\QueryParameterRequest;
use Hypervel\Tests\Saloon\Fixtures\Requests\UserRequest;

// match() replaces upstream's guessNextResponse() and getNextFromSequence(), and recorded() with a filter replaces
// getRecordedResponses(), findResponseByRequest() and findResponseByRequestUrl().
class MockClientTest extends TestCase
{
    /**
     * Get the package providers.
     */
    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [SaloonServiceProvider::class];
    }

    public function testYouCanCreateSequenceMocks(): void
    {
        $responseA = MockResponse::make(['name' => 'Sammyjo20']);
        $responseB = MockResponse::make(['name' => 'Alex']);

        $mockClient = new MockClient([$responseA, $responseB]);
        $pendingRequest = (new TestConnector)->createPendingRequest(new UserRequest);

        $this->assertSame($responseA, $mockClient->match($pendingRequest));
        $this->assertSame($responseB, $mockClient->match($pendingRequest));
        $this->assertTrue($mockClient->isEmpty());
    }

    public function testYouCanCreateConnectorMocks(): void
    {
        $responseA = MockResponse::make(['name' => 'Sammyjo20']);
        $responseB = MockResponse::make(['name' => 'Alex']);

        $connectorA = new TestConnector;
        $connectorB = new QueryParameterConnector;

        $connectorARequest = new UserRequest;
        $connectorBRequest = new QueryParameterConnectorRequest;

        $mockClient = new MockClient([
            TestConnector::class => $responseA,
            QueryParameterConnector::class => $responseB,
        ]);

        $this->assertSame($responseA, $mockClient->match($connectorA->createPendingRequest($connectorARequest)));
        $this->assertSame($responseB, $mockClient->match($connectorB->createPendingRequest($connectorBRequest)));
        $this->assertFalse($mockClient->isEmpty());
    }

    public function testYouCanCreateRequestMocks(): void
    {
        $responseA = MockResponse::make(['name' => 'Sammyjo20']);
        $responseB = MockResponse::make(['name' => 'Alex']);

        $connectorA = new TestConnector;
        $connectorB = new QueryParameterConnector;

        $requestA = new UserRequest;
        $requestB = new QueryParameterConnectorRequest;

        $mockClient = new MockClient([
            UserRequest::class => $responseA,
            QueryParameterConnectorRequest::class => $responseB,
        ]);

        $this->assertSame($responseA, $mockClient->match($connectorA->createPendingRequest($requestA)));
        $this->assertSame($responseB, $mockClient->match($connectorB->createPendingRequest($requestB)));
        $this->assertFalse($mockClient->isEmpty());
    }

    public function testYouCanCreateUrlMocks(): void
    {
        $responseA = MockResponse::make(['name' => 'Sammyjo20']);
        $responseB = MockResponse::make(['name' => 'Alex']);
        $responseC = MockResponse::make(['name' => 'Sam Carré']);

        $connectorA = new TestConnector;
        $connectorB = new DifferentServiceConnector;

        $requestA = new UserRequest;
        $requestB = new ErrorRequest;
        $requestC = new DifferentServiceUserRequest;

        $mockClient = new MockClient([
            'tests.saloon.dev/api/user' => $responseA, // Test Exact Route
            'tests.saloon.dev/*' => $responseB, // Test Wildcard Routes
            'google.com/*' => $responseC, // Test Different Route,
        ]);

        $this->assertSame($responseA, $mockClient->match($connectorA->createPendingRequest($requestA)));
        $this->assertSame($responseB, $mockClient->match($connectorA->createPendingRequest($requestB)));
        $this->assertSame($responseC, $mockClient->match($connectorB->createPendingRequest($requestC)));
    }

    public function testYouCanCreateWildcardUrlMocks(): void
    {
        $responseA = MockResponse::make(['name' => 'Sammyjo20']);
        $responseB = MockResponse::make(['name' => 'Alex']);
        $responseC = MockResponse::make(['name' => 'Sam Carré']);

        $connectorA = new TestConnector;
        $connectorB = new DifferentServiceConnector;

        $requestA = new UserRequest;
        $requestB = new ErrorRequest;
        $requestC = new DifferentServiceUserRequest;

        $mockClient = new MockClient([
            'tests.saloon.dev/api/user' => $responseA, // Test Exact Route
            'tests.saloon.dev/*' => $responseB, // Test Wildcard Routes
            '*' => $responseC,
        ]);

        $this->assertSame($responseA, $mockClient->match($connectorA->createPendingRequest($requestA)));
        $this->assertSame($responseB, $mockClient->match($connectorA->createPendingRequest($requestB)));
        $this->assertSame($responseC, $mockClient->match($connectorB->createPendingRequest($requestC)));
    }

    public function testSaloonThrowsAnExceptionIfItCantWorkOutTheUrlResponse(): void
    {
        $responseA = MockResponse::make(['name' => 'Sammyjo20']);
        $responseB = MockResponse::make(['name' => 'Alex']);

        $connectorA = new TestConnector;
        $connectorB = new DifferentServiceConnector;

        $requestA = new UserRequest;
        $requestB = new ErrorRequest;
        $requestC = new DifferentServiceUserRequest;

        $mockClient = new MockClient([
            'tests.saloon.dev/api/user' => $responseA, // Test Exact Route
            'tests.saloon.dev/*' => $responseB, // Test Wildcard Routes
        ]);

        $this->assertSame($responseA, $mockClient->match($connectorA->createPendingRequest($requestA)));
        $this->assertSame($responseB, $mockClient->match($connectorA->createPendingRequest($requestB)));

        $this->expectException(NoMockResponseFoundException::class);
        $this->expectExceptionMessageIs('Saloon was unable to guess a mock response for your request [https://google.com/user], consider using a wildcard url mock or a connector mock.');

        $mockClient->match($connectorB->createPendingRequest($requestC));
    }

    public function testUrlPatternsMatchWithOrWithoutTheQueryString(): void
    {
        $mockClient = new MockClient([
            'tests.saloon.dev/api/user?per_page=50' => MockResponse::make(['matched' => 'query']),
            'tests.saloon.dev/api/user' => MockResponse::make(['matched' => 'path']),
        ]);
        $connector = new TestConnector;

        $withQuery = $connector->send((new QueryParameterRequest)->withQueryParameters(['per_page' => 50]), $mockClient);
        $otherQuery = $connector->send(new QueryParameterRequest, $mockClient);

        $this->assertSame(['matched' => 'query'], $withQuery->json());
        $this->assertSame(['matched' => 'path'], $otherQuery->json());
        $mockClient->assertSent('/user?per_page=50');
        $mockClient->assertSent('/user?per_page=100');
        $mockClient->assertNotSent('/user?per_page=25');
    }

    public function testRequestConnectorUrlAndSequenceResponsesUseTheirPrecedence(): void
    {
        $mockClient = new MockClient([
            MockResponse::make(['matched' => 'sequence']),
            TestConnector::class => MockResponse::make(['matched' => 'connector']),
            '*/error' => MockResponse::make(['matched' => 'url']),
            UserRequest::class => MockResponse::make(['matched' => 'request']),
        ]);

        $request = (new TestConnector)->send(new UserRequest, $mockClient);
        $connector = (new TestConnector)->send(new ErrorRequest, $mockClient);
        $url = (new DifferentServiceConnector)->send(new ErrorRequest, $mockClient);
        $sequence = (new DifferentServiceConnector)->send(new DifferentServiceUserRequest, $mockClient);

        $this->assertSame(['matched' => 'request'], $request->json());
        $this->assertSame(['matched' => 'connector'], $connector->json());
        $this->assertSame(['matched' => 'url'], $url->json());
        $this->assertSame(['matched' => 'sequence'], $sequence->json());
        $this->assertFalse($mockClient->isEmpty());
    }

    public function testUnmatchedRequestsAreStrictUnlessTheirUrlIsAllowed(): void
    {
        Http::fake(['*' => Http::response('network')]);
        $mockClient = (new MockClient)->allowStrayRequests(['tests.saloon.dev/api/user']);

        $allowed = (new TestConnector)->send(new UserRequest, $mockClient);

        $this->assertSame('network', $allowed->body());
        $this->assertFalse($allowed->isMocked());
        $mockClient->assertSentCount(1);

        $this->expectException(NoMockResponseFoundException::class);

        (new TestConnector)->send(new ErrorRequest, $mockClient);
    }

    public function testYouCanGetAnArrayOfTheRecordedRequests(): void
    {
        $mockClient = new MockClient([
            MockResponse::make(['name' => 'Sam']),
            MockResponse::make(['name' => 'Taylor']),
            MockResponse::make(['name' => 'Marcel']),
        ]);

        $connector = new TestConnector;

        $responseA = $connector->send(new UserRequest, $mockClient);
        $responseB = $connector->send(new UserRequest, $mockClient);
        $responseC = $connector->send(new UserRequest, $mockClient);

        $this->assertSame([$responseA, $responseB, $responseC], $mockClient->recorded()->all());
        $this->assertSame(
            [$responseB, $responseC],
            $mockClient->recorded(fn (Request $request, Response $response): bool => $response->json('name') !== 'Sam')->all(),
        );
    }

    public function testYouCanGetTheLastRecordedRequest(): void
    {
        $mockClient = new MockClient([
            MockResponse::make(['name' => 'Sam']),
            MockResponse::make(['name' => 'Taylor']),
            MockResponse::make(['name' => 'Marcel']),
        ]);

        $connector = new TestConnector;

        $connector->send(new UserRequest, $mockClient);
        $connector->send(new UserRequest, $mockClient);
        $requestC = new UserRequest;
        $responseC = $connector->send($requestC, $mockClient);

        $this->assertSame($responseC, $mockClient->lastResponse());
        $this->assertSame($requestC, $mockClient->lastRequest());
        $this->assertSame($responseC->pendingRequest(), $mockClient->lastPendingRequest());
    }

    public function testIfThereAreNoRecordedResponsesTheLastResponseWillReturnNull(): void
    {
        $mockClient = new MockClient([
            MockResponse::make(['name' => 'Sam']),
        ]);

        $this->assertNull($mockClient->lastResponse());
    }

    public function testIfThereAreNoRecordedResponsesTheLastRequestWillReturnNull(): void
    {
        $mockClient = new MockClient([
            MockResponse::make(['name' => 'Sam']),
        ]);

        $this->assertNull($mockClient->lastRequest());
    }

    public function testIfTheResponseIsNotTheLastResponseItWillUseTheLoopToFindIt(): void
    {
        $mockClient = new MockClient([
            MockResponse::make(['name' => 'Sam']),
            MockResponse::make(['error' => 'Server Error'], 500),
        ]);

        $responseA = (new TestConnector)->send(new ErrorRequest, $mockClient);
        $responseB = (new TestConnector)->send(new UserRequest, $mockClient);

        $this->assertSame($responseB, $mockClient->lastResponse());

        // Uses last response

        $this->assertSame($responseB, $mockClient->recorded(fn (Request $request): bool => $request instanceof UserRequest)->first());

        // Does not use the last response

        $this->assertSame($responseA, $mockClient->recorded(fn (Request $request): bool => $request instanceof ErrorRequest)->first());
    }

    public function testItWillFindTheResponseByUrlIfItIsNotTheLastResponse(): void
    {
        $mockClient = new MockClient([
            '/user' => MockResponse::make(['name' => 'Sam']),
            '/error' => MockResponse::make(['error' => 'Server Error'], 500),
        ]);

        $responseA = (new TestConnector)->send(new ErrorRequest, $mockClient);
        $responseB = (new TestConnector)->send(new UserRequest, $mockClient);

        $this->assertSame($responseB, $mockClient->lastResponse());
        $this->assertSame(['name' => 'Sam'], $responseB->json());
        $this->assertSame(['error' => 'Server Error'], $responseA->json());

        // Uses last response

        $mockClient->assertSent('/user');

        // Does not use the last response

        $mockClient->assertSent('/error');
    }

    public function testYouCanMockExceptionsWithAClosure(): void
    {
        $mockClient = new MockClient([
            MockResponse::make(['name' => 'Sam']),
            MockResponse::make(['name' => 'Patrick'])->throw(fn (PendingRequest $pendingRequest): TestResponseException => new TestResponseException('Unable to connect!', $pendingRequest)),
        ]);

        $okResponse = (new TestConnector)->send(new UserRequest, $mockClient);

        $this->assertSame(['name' => 'Sam'], $okResponse->json());

        $this->expectException(TestResponseException::class);
        $this->expectExceptionMessageIs('Unable to connect!');

        (new TestConnector)->send(new UserRequest, $mockClient);
    }

    public function testYouCanMockNormalExceptions(): void
    {
        $mockClient = new MockClient([
            MockResponse::make(['name' => 'Michael'])->throw(new Exception('Custom Exception!')),
        ]);

        $this->expectException(Exception::class);
        $this->expectExceptionMessageIs('Custom Exception!');

        (new TestConnector)->send(new UserRequest, $mockClient);
    }

    public function testMockClientCanOptOutOfResponseCacheIntegration(): void
    {
        $client = new MockClient([]);

        $this->assertFalse($client->shouldBypassResponseCache());

        $client->withoutCache();

        $this->assertTrue($client->shouldBypassResponseCache());
    }
}
