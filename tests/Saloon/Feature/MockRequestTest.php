<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Feature;

use Closure;
use Exception;
use GuzzleHttp\Promise\PromiseInterface;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Saloon\Cache\Contracts\Cacheable;
use Hypervel\Saloon\Cache\Traits\HasCaching;
use Hypervel\Saloon\Data\RecordedResponse;
use Hypervel\Saloon\Exceptions\FixtureException;
use Hypervel\Saloon\Exceptions\NoMockResponseFoundException;
use Hypervel\Saloon\Exceptions\Request\RequestException;
use Hypervel\Saloon\Facades\Saloon;
use Hypervel\Saloon\Http\Faking\Fixture;
use Hypervel\Saloon\Http\Faking\MockClient;
use Hypervel\Saloon\Http\Faking\MockResponse;
use Hypervel\Saloon\Http\PendingRequest;
use Hypervel\Saloon\Http\Response;
use Hypervel\Saloon\SaloonServiceProvider;
use Hypervel\Support\Facades\Http;
use Hypervel\Testbench\TestCase;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\Saloon\Fixtures\Connectors\DifferentServiceConnector;
use Hypervel\Tests\Saloon\Fixtures\Connectors\QueryParameterConnector;
use Hypervel\Tests\Saloon\Fixtures\Connectors\TestConnector;
use Hypervel\Tests\Saloon\Fixtures\Mocking\BeforeSaveUserFixture;
use Hypervel\Tests\Saloon\Fixtures\Mocking\CallableMockResponse;
use Hypervel\Tests\Saloon\Fixtures\Mocking\MissingNameFixture;
use Hypervel\Tests\Saloon\Fixtures\Mocking\RegexUserFixture;
use Hypervel\Tests\Saloon\Fixtures\Mocking\SafeUserFixture;
use Hypervel\Tests\Saloon\Fixtures\Mocking\SuperheroFixture;
use Hypervel\Tests\Saloon\Fixtures\Mocking\UserFixture;
use Hypervel\Tests\Saloon\Fixtures\Requests\AlwaysThrowRequest;
use Hypervel\Tests\Saloon\Fixtures\Requests\DifferentServiceUserRequest;
use Hypervel\Tests\Saloon\Fixtures\Requests\ErrorRequest;
use Hypervel\Tests\Saloon\Fixtures\Requests\FileDownloadRequest;
use Hypervel\Tests\Saloon\Fixtures\Requests\PagedSuperheroRequest;
use Hypervel\Tests\Saloon\Fixtures\Requests\QueryParameterConnectorRequest;
use Hypervel\Tests\Saloon\Fixtures\Requests\UserRequest;

// The first use of a missing fixture sends a real request, which Http::fake() answers in place of upstream's test API;
// the response still comes from the transport, so it is not mocked. Connectors take no mock client, so the global
// fake stands in for upstream's connector client, and connector middleware is registered from boot().
class MockRequestTest extends TestCase
{
    /**
     * Binary bytes standing in for upstream's downloaded PDF.
     */
    protected const string FILE_CONTENTS = "%PDF-1.4\n\xB1\x31\xFF\x00binary";

    protected Filesystem $files;

    protected string $fixturePath;

    /**
     * Get the package providers.
     */
    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [SaloonServiceProvider::class];
    }

    /**
     * Set up the test environment.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->files = new Filesystem;
        $this->fixturePath = ParallelTesting::tempDir('SaloonMockRequestTest');
        $this->files->deleteDirectory($this->fixturePath);
        $this->files->ensureDirectoryExists($this->fixturePath);

        Saloon::fixturePath($this->fixturePath);

        Http::fake([
            'tests.saloon.dev/api/user' => Http::response(
                ['name' => 'Sammyjo20', 'actual_name' => 'Sam', 'twitter' => '@carre_sam'],
                200,
                ['Server' => 'cloudflare', 'Cache-Control' => 'no-cache, private'],
            ),
            'tests.saloon.dev/api/error' => Http::response(['message' => 'Fake Error'], 500),
            'tests.saloon.dev/api/download-test-pdf' => Http::response(self::FILE_CONTENTS, 200, ['Content-Type' => 'application/pdf']),
            'tests.saloon.dev/api/superheroes/per-page' => Http::response([
                'data' => [
                    ['superhero' => 'Batman', 'publisher' => 'DC Comics'],
                    ['superhero' => 'Superman', 'publisher' => 'DC Comics'],
                ],
            ]),
        ]);
    }

    /**
     * Clean up the test environment.
     */
    protected function tearDown(): void
    {
        $this->files->deleteDirectory($this->fixturePath);

        parent::tearDown();
    }

    public function testARequestCanBeMockedWithASequence(): void
    {
        Saloon::fake([
            MockResponse::make(['name' => 'Sam'], 200, ['X-Foo' => 'Bar']),
            MockResponse::make(['name' => 'Alex']),
            MockResponse::make(['error' => 'Server Unavailable'], 500),
        ]);

        $connector = new TestConnector;

        $responseA = $connector->send(new UserRequest);

        $this->assertInstanceOf(Response::class, $responseA);
        $this->assertTrue($responseA->isMocked());
        $this->assertTrue($responseA->isFaked());
        $this->assertFalse($responseA->isCached());
        $this->assertSame(['name' => 'Sam'], $responseA->json());
        $this->assertSame(200, $responseA->status());
        $this->assertInstanceOf(MockResponse::class, $responseA->fakeResponse());
        $this->assertSame(['X-Foo' => ['Bar']], $responseA->headers());

        $responseB = $connector->send(new UserRequest);

        $this->assertInstanceOf(Response::class, $responseB);
        $this->assertTrue($responseB->isMocked());
        $this->assertTrue($responseB->isFaked());
        $this->assertFalse($responseB->isCached());
        $this->assertSame(['name' => 'Alex'], $responseB->json());
        $this->assertSame(200, $responseB->status());
        $this->assertInstanceOf(MockResponse::class, $responseB->fakeResponse());

        $responseC = $connector->send(new UserRequest);

        $this->assertInstanceOf(Response::class, $responseC);
        $this->assertTrue($responseC->isMocked());
        $this->assertTrue($responseC->isFaked());
        $this->assertFalse($responseC->isCached());
        $this->assertSame(['error' => 'Server Unavailable'], $responseC->json());
        $this->assertSame(500, $responseC->status());
        $this->assertInstanceOf(MockResponse::class, $responseC->fakeResponse());

        $this->expectException(NoMockResponseFoundException::class);
        $this->expectExceptionMessageIs('Saloon was unable to guess a mock response for your request [https://tests.saloon.dev/api/user], consider using a wildcard url mock or a connector mock.');

        $connector->send(new UserRequest);
    }

    public function testARequestCanBeMockedWithAConnectorDefined(): void
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

        $responseA = $connectorA->send($connectorARequest, $mockClient);

        $this->assertTrue($responseA->isMocked());
        $this->assertSame(['name' => 'Sammyjo20'], $responseA->json());
        $this->assertSame(200, $responseA->status());

        $responseB = $connectorB->send($connectorBRequest, $mockClient);

        $this->assertTrue($responseB->isMocked());
        $this->assertSame(['name' => 'Alex'], $responseB->json());
        $this->assertSame(200, $responseB->status());
    }

    public function testARequestCanBeMockedWithARequestDefined(): void
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

        $responseA = $connectorA->send($requestA, $mockClient);

        $this->assertTrue($responseA->isMocked());
        $this->assertSame(['name' => 'Sammyjo20'], $responseA->json());
        $this->assertSame(200, $responseA->status());

        $responseB = $connectorB->send($requestB, $mockClient);

        $this->assertTrue($responseB->isMocked());
        $this->assertSame(['name' => 'Alex'], $responseB->json());
        $this->assertSame(200, $responseB->status());
    }

    public function testARequestCanBeMockedWithAUrlDefined(): void
    {
        $responseA = MockResponse::make(['name' => 'Sammyjo20']);
        $responseB = MockResponse::make(['name' => 'Alex']);
        $responseC = MockResponse::make(['error' => 'Server Broken'], 500);

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

        $responseA = $connectorA->send($requestA, $mockClient);

        $this->assertTrue($responseA->isMocked());
        $this->assertSame(['name' => 'Sammyjo20'], $responseA->json());
        $this->assertSame(200, $responseA->status());

        $responseB = $connectorA->send($requestB, $mockClient);

        $this->assertTrue($responseB->isMocked());
        $this->assertSame(['name' => 'Alex'], $responseB->json());
        $this->assertSame(200, $responseB->status());

        $responseC = $connectorB->send($requestC, $mockClient);

        $this->assertTrue($responseC->isMocked());
        $this->assertSame(['error' => 'Server Broken'], $responseC->json());
        $this->assertSame(500, $responseC->status());
    }

    public function testYouCanCreateWildcardUrlMocks(): void
    {
        $responseA = MockResponse::make(['name' => 'Sammyjo20']);
        $responseB = MockResponse::make(['name' => 'Alex']);
        $responseC = MockResponse::make(['error' => 'Server Broken'], 500);

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

        $responseA = $connectorA->send($requestA, $mockClient);

        $this->assertTrue($responseA->isMocked());
        $this->assertSame(['name' => 'Sammyjo20'], $responseA->json());
        $this->assertSame(200, $responseA->status());

        $responseB = $connectorA->send($requestB, $mockClient);

        $this->assertTrue($responseB->isMocked());
        $this->assertSame(['name' => 'Alex'], $responseB->json());
        $this->assertSame(200, $responseB->status());

        $responseC = $connectorB->send($requestC, $mockClient);

        $this->assertTrue($responseC->isMocked());
        $this->assertSame(['error' => 'Server Broken'], $responseC->json());
        $this->assertSame(500, $responseC->status());
    }

    public function testYouCanUseAClosureForTheMockResponse(): void
    {
        $sequenceMock = new MockClient([
            function (PendingRequest $pendingRequest): MockResponse {
                return new MockResponse(['request' => (string) $pendingRequest->uri()]);
            },
        ]);

        $sequenceResponse = (new TestConnector)->send(new UserRequest, $sequenceMock);

        $this->assertTrue($sequenceResponse->isMocked());
        $this->assertSame(['request' => 'https://tests.saloon.dev/api/user'], $sequenceResponse->json());

        // Connector mock

        $connectorMock = new MockClient([
            TestConnector::class => function (PendingRequest $pendingRequest): MockResponse {
                return new MockResponse(['request' => (string) $pendingRequest->uri()]);
            },
        ]);

        $connectorResponse = (new TestConnector)->send(new UserRequest, $connectorMock);

        $this->assertTrue($connectorResponse->isMocked());
        $this->assertSame(['request' => 'https://tests.saloon.dev/api/user'], $connectorResponse->json());

        // Request mock

        $requestMock = new MockClient([
            UserRequest::class => function (PendingRequest $pendingRequest): MockResponse {
                return new MockResponse(['request' => (string) $pendingRequest->uri()]);
            },
        ]);

        $requestResponse = (new TestConnector)->send(new UserRequest, $requestMock);

        $this->assertTrue($requestResponse->isMocked());
        $this->assertSame(['request' => 'https://tests.saloon.dev/api/user'], $requestResponse->json());

        // URL mock

        $urlMock = new MockClient([
            'tests.saloon.dev/*' => function (PendingRequest $pendingRequest): MockResponse {
                return new MockResponse(['request' => (string) $pendingRequest->uri()]);
            },
        ]);

        $urlResponse = (new TestConnector)->send(new UserRequest, $urlMock);

        $this->assertTrue($urlResponse->isMocked());
        $this->assertSame(['request' => 'https://tests.saloon.dev/api/user'], $urlResponse->json());
    }

    public function testYouCanUseACallableClassAsTheMockResponse(): void
    {
        $mockClient = new MockClient([
            UserRequest::class => new CallableMockResponse,
        ]);

        $sequenceResponse = (new TestConnector)->send(new UserRequest, $mockClient);

        $this->assertTrue($sequenceResponse->isMocked());
        $this->assertSame(['request_class' => UserRequest::class], $sequenceResponse->json());
    }

    public function testAFixtureCanBeUsedWithAMockSequence(): void
    {
        $mockClient = new MockClient([
            MockResponse::fixture('user'),
            MockResponse::fixture('user'),
        ]);

        $responseA = (new TestConnector)->send(new UserRequest, $mockClient);

        $this->assertFalse($responseA->isMocked());
        $this->assertSame(200, $responseA->status());
        $this->assertSame($this->user(), $responseA->json());

        $responseB = (new TestConnector)->send(new UserRequest, $mockClient);

        $this->assertTrue($responseB->isMocked());
        $this->assertSame(200, $responseB->status());
        $this->assertSame($this->user(), $responseB->json());
    }

    public function testAFixtureCanBeUsedWithAConnectorMock(): void
    {
        $mockClient = new MockClient([
            TestConnector::class => MockResponse::fixture('connector'),
        ]);

        $responseA = (new TestConnector)->send(new UserRequest, $mockClient);

        $this->assertFalse($responseA->isMocked());
        $this->assertSame(200, $responseA->status());
        $this->assertSame($this->user(), $responseA->json());

        $responseB = (new TestConnector)->send(new UserRequest, $mockClient);

        $this->assertTrue($responseB->isMocked());
        $this->assertSame(200, $responseB->status());
        $this->assertSame($this->user(), $responseB->json());

        // Even though it's a different request, it should use the same fixture

        $responseC = (new TestConnector)->send(new ErrorRequest, $mockClient);

        $this->assertTrue($responseC->isMocked());
        $this->assertSame(200, $responseC->status());
        $this->assertSame($this->user(), $responseC->json());
    }

    public function testAFixtureCanBeUsedWithARequestMock(): void
    {
        $mockClient = new MockClient([
            UserRequest::class => MockResponse::fixture('user'),
        ]);

        $this->assertFalse($this->files->exists($this->fixturePath . '/user.json'));

        $responseA = (new TestConnector)->send(new UserRequest, $mockClient);

        $this->assertFalse($responseA->isMocked());
        $this->assertSame(200, $responseA->status());
        $this->assertSame($this->user(), $responseA->json());

        $this->assertTrue($this->files->exists($this->fixturePath . '/user.json'));

        $responseB = (new TestConnector)->send(new UserRequest, $mockClient);

        $this->assertTrue($responseB->isMocked());
        $this->assertSame(200, $responseB->status());
        $this->assertSame($this->user(), $responseB->json());

        Http::assertSentCount(1);
        $mockClient->assertSentCount(2);
    }

    public function testAFixtureCanBeUsedWithAUrlMock(): void
    {
        $mockClient = new MockClient([
            'tests.saloon.dev/api/user' => MockResponse::fixture('user'), // Test Exact Route
            'tests.saloon.dev/*' => MockResponse::fixture('other'), // Test Wildcard Routes
        ]);

        $this->assertFalse($this->files->exists($this->fixturePath . '/user.json'));
        $this->assertFalse($this->files->exists($this->fixturePath . '/other.json'));

        $responseA = (new TestConnector)->send(new UserRequest, $mockClient);

        $this->assertTrue($this->files->exists($this->fixturePath . '/user.json'));
        $this->assertFalse($this->files->exists($this->fixturePath . '/other.json'));

        $this->assertFalse($responseA->isMocked());
        $this->assertSame(200, $responseA->status());
        $this->assertSame($this->user(), $responseA->json());

        $responseB = (new TestConnector)->send(new ErrorRequest, $mockClient);

        $this->assertTrue($this->files->exists($this->fixturePath . '/user.json'));
        $this->assertTrue($this->files->exists($this->fixturePath . '/other.json'));

        $this->assertFalse($responseB->isMocked());
        $this->assertSame(500, $responseB->status());
        $this->assertSame(['message' => 'Fake Error'], $responseB->json());

        // This should use the first mock

        $responseC = (new TestConnector)->send(new UserRequest, $mockClient);

        $this->assertTrue($responseC->isMocked());
        $this->assertSame(200, $responseC->status());
        $this->assertSame($this->user(), $responseC->json());

        // Another error request should use the "other" mock

        $responseD = (new TestConnector)->send(new ErrorRequest, $mockClient);

        $this->assertTrue($responseD->isMocked());
        $this->assertSame(500, $responseD->status());
        $this->assertSame(['message' => 'Fake Error'], $responseD->json());
    }

    public function testAFixtureCanBeUsedWithAWildcardUrlMock(): void
    {
        $mockClient = new MockClient([
            '*' => MockResponse::fixture('user'), // Test Exact Route
        ]);

        $responseA = (new TestConnector)->send(new UserRequest, $mockClient);

        $this->assertFalse($responseA->isMocked());
        $this->assertSame(200, $responseA->status());
        $this->assertSame($this->user(), $responseA->json());

        $responseB = (new TestConnector)->send(new ErrorRequest, $mockClient);

        $this->assertTrue($responseB->isMocked());
        $this->assertSame(200, $responseB->status());
        $this->assertSame($this->user(), $responseB->json());
    }

    public function testAFixtureCanBeUsedWithinAClosureMock(): void
    {
        $mockClient = new MockClient([
            '*' => function (PendingRequest $pendingRequest): Fixture {
                if ($pendingRequest->request() instanceof UserRequest) {
                    return MockResponse::fixture('user');
                }

                return MockResponse::fixture('other');
            },
        ]);

        $this->assertFalse($this->files->exists($this->fixturePath . '/user.json'));
        $this->assertFalse($this->files->exists($this->fixturePath . '/other.json'));

        $responseA = (new TestConnector)->send(new UserRequest, $mockClient);

        $this->assertFalse($responseA->isMocked());
        $this->assertSame(200, $responseA->status());
        $this->assertSame($this->user(), $responseA->json());

        $responseB = (new TestConnector)->send(new UserRequest, $mockClient);

        $this->assertTrue($responseB->isMocked());
        $this->assertSame(200, $responseB->status());
        $this->assertSame($this->user(), $responseB->json());

        // Now we'll test a different route

        $responseC = (new TestConnector)->send(new ErrorRequest, $mockClient);

        $this->assertFalse($responseC->isMocked());
        $this->assertSame(500, $responseC->status());
        $this->assertSame(['message' => 'Fake Error'], $responseC->json());

        // Another error request should use the "other" mock

        $responseD = (new TestConnector)->send(new ErrorRequest, $mockClient);

        $this->assertTrue($responseD->isMocked());
        $this->assertSame(500, $responseD->status());
        $this->assertSame(['message' => 'Fake Error'], $responseD->json());
    }

    public function testWhenUsingTheAlwaysThrowRequestTraitTheResponseRecorderWillStillRecordTheResponse(): void
    {
        $mockClient = new MockClient([
            AlwaysThrowRequest::class => MockResponse::fixture('error'),
        ]);

        $exception = null;

        try {
            (new TestConnector)->send(new AlwaysThrowRequest, $mockClient);
        } catch (Exception $exception) {
        }

        $this->assertInstanceOf(RequestException::class, $exception);

        $fixture = MockResponse::fixture('error')->getMockResponse();

        $this->assertInstanceOf(MockResponse::class, $fixture);
    }

    public function testAFixtureCanRecordTheFileDataFromARequestThatReturnsAFileDownload(): void
    {
        $mockClient = new MockClient([
            FileDownloadRequest::class => MockResponse::fixture('file'),
        ]);

        $requestA = new FileDownloadRequest;
        $responseA = (new TestConnector)->send($requestA, $mockClient);

        $this->assertSame(self::FILE_CONTENTS, $responseA->body());

        $requestB = new FileDownloadRequest;
        $responseB = (new TestConnector)->send($requestB, $mockClient);

        $this->assertTrue($responseB->isMocked());
        $this->assertSame(self::FILE_CONTENTS, $responseB->body());
    }

    public function testYouCanCreateACustomFixtureClass(): void
    {
        $mockClient = new MockClient([
            new UserFixture,
            new UserFixture,
        ]);

        $responseA = (new TestConnector)->send(new UserRequest, $mockClient);

        $this->assertFalse($responseA->isMocked());
        $this->assertSame(200, $responseA->status());
        $this->assertSame($this->user(), $responseA->json());

        $responseB = (new TestConnector)->send(new UserRequest, $mockClient);

        $this->assertTrue($responseB->isMocked());
        $this->assertSame(200, $responseB->status());
        $this->assertSame($this->user(), $responseB->json());
    }

    public function testItWillThrowAnExceptionIfTheCustomFixtureClassIsMissingAName(): void
    {
        $mockClient = new MockClient([
            new MissingNameFixture,
        ]);

        $this->expectException(FixtureException::class);
        $this->expectExceptionMessageIs('The fixture must have a name.');

        (new TestConnector)->send(new UserRequest, $mockClient);
    }

    public function testYouCanHideSensitiveJsonBodyParametersAndHeadersBeforeTheFixtureIsStored(): void
    {
        $mockClient = new MockClient([
            new SafeUserFixture,
            new SafeUserFixture,
        ]);

        $responseA = (new TestConnector)->send(new UserRequest, $mockClient);
        $responseB = (new TestConnector)->send(new UserRequest, $mockClient);

        $this->assertSame($this->user(), $responseA->json());

        $this->assertSame('cloudflare', $responseA->header('Server'));
        $this->assertSame('no-cache, private', $responseA->header('Cache-Control'));

        $this->assertFalse($responseA->isFaked());
        $this->assertTrue($responseB->isFaked());

        $this->assertSame([
            'name' => 'Sxxx',
            'actual_name' => 'REDACTED',
            'twitter' => '@saloonphp',
        ], $responseB->json());

        $this->assertSame('secret', $responseB->header('Server'));
        $this->assertSame('no-cache, private, yeehaw', $responseB->header('Cache-Control'));

        $fixtureData = json_decode($this->files->get($this->fixturePath . '/user.json'), true, 512, JSON_THROW_ON_ERROR);

        // Recorded fixtures list every header value.
        $this->assertSame(['secret'], $fixtureData['headers']['Server']);
        $this->assertSame(['no-cache, private, yeehaw'], $fixtureData['headers']['Cache-Control']);
        $this->assertSame(json_encode([
            'name' => 'Sxxx',
            'actual_name' => 'REDACTED',
            'twitter' => '@saloonphp',
        ], JSON_THROW_ON_ERROR), $fixtureData['data']);
    }

    public function testTheFixtureSwapToolWorksOnMultipleAttemptsAndRecursively(): void
    {
        $mockClient = new MockClient([
            new SuperheroFixture,
            new SuperheroFixture,
        ]);

        $responseA = (new TestConnector)->send(new PagedSuperheroRequest, $mockClient);
        $responseB = (new TestConnector)->send(new PagedSuperheroRequest, $mockClient);

        foreach ($responseA->json()['data'] as $superhero) {
            $this->assertSame('DC Comics', $superhero['publisher']);
        }

        foreach ($responseB->json()['data'] as $superhero) {
            $this->assertSame('REDACTED', $superhero['publisher']);
        }
    }

    public function testYouCanDefineACustomRedactionMethodForNonJsonBodyFixtures(): void
    {
        $mockClient = new MockClient([
            new BeforeSaveUserFixture,
            new BeforeSaveUserFixture,
        ]);

        $responseA = (new TestConnector)->send(new UserRequest, $mockClient);
        $responseB = (new TestConnector)->send(new UserRequest, $mockClient);

        $this->assertSame(200, $responseA->status());
        $this->assertSame(222, $responseB->status());
    }

    public function testYouCanDefineRegexPatternsThatShouldBeUsedToReplaceTheBodyInFixtures(): void
    {
        $mockClient = new MockClient([
            new RegexUserFixture,
            new RegexUserFixture,
        ]);

        $responseA = (new TestConnector)->send(new UserRequest, $mockClient);
        $responseB = (new TestConnector)->send(new UserRequest, $mockClient);

        $this->assertSame($this->user(), $responseA->json());

        $this->assertFalse($responseA->isFaked());
        $this->assertTrue($responseB->isFaked());

        $this->assertSame([
            'name' => 'Sxxxmyjo20',
            'actual_name' => 'Sxxx',
            'twitter' => '**REDACTED-TWITTER**',
        ], $responseB->json());

        $fixtureData = json_decode($this->files->get($this->fixturePath . '/user.json'), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(json_encode([
            'name' => 'Sxxxmyjo20',
            'actual_name' => 'Sxxx',
            'twitter' => '**REDACTED-TWITTER**',
        ], JSON_THROW_ON_ERROR), $fixtureData['data']);
    }

    public function testRequestAndResponseMiddlewareIsInvokedWhenUsingFakeResponses(): void
    {
        Saloon::fake([
            MockResponse::make(['name' => 'Sam'], 200, ['X-Foo' => 'Bar']),
            MockResponse::make(['name' => 'Alex']),
            MockResponse::make(['error' => 'Server Unavailable'], 500),
        ]);

        $middlewareA = false;
        $middlewareB = false;
        $middlewareC = false;
        $middlewareD = false;

        $connector = new MiddlewareTestConnector(
            function () use (&$middlewareA): void {
                $middlewareA = true;
            },
            function () use (&$middlewareB): void {
                $middlewareB = true;
            },
        );

        $request = new UserRequest;

        $request->middleware()->onRequest(function () use (&$middlewareC): void {
            $middlewareC = true;
        });

        $request->middleware()->onResponse(function () use (&$middlewareD): void {
            $middlewareD = true;
        });

        $connector->send($request);

        $this->assertTrue($middlewareA);
        $this->assertTrue($middlewareB);
        $this->assertTrue($middlewareC);
        $this->assertTrue($middlewareD);
    }

    public function testFixturesAreStillRecordedOnTheFirstRequest(): void
    {
        $mockClient = new MockClient([
            MockResponse::fixture('user'), // Test Exact Route
        ]);

        (new TestConnector)->send(new UserRequest, $mockClient);

        $mockClient->assertSent(UserRequest::class);
    }

    public function testFixturesCanBeCreatedInSubDirectories(): void
    {
        $mockClient = new MockClient([
            MockResponse::fixture('my-integration/user'), // Test Exact Route
        ]);

        (new TestConnector)->send(new UserRequest, $mockClient);

        $mockClient->assertSent(UserRequest::class);
        $this->assertTrue($this->files->exists($this->fixturePath . '/my-integration/user.json'));
    }

    public function testWithoutCacheRecordsAMissingFixtureFromTheTransport(): void
    {
        $version = 0;
        Http::fake(['tests.saloon.dev/api/cached-user' => function () use (&$version): PromiseInterface {
            return Http::response(['version' => ++$version]);
        }]);

        $this->assertSame(['version' => 1], (new TestConnector)->send(new WithoutCacheUserRequest)->json());

        $mockClient = (new MockClient([
            WithoutCacheUserRequest::class => MockResponse::fixture('cached-user'),
        ]))->withoutCache();

        $recorded = (new TestConnector)->send(new WithoutCacheUserRequest, $mockClient);

        $this->assertFalse($recorded->isCached());
        $this->assertSame(['version' => 2], $recorded->json());
        $this->assertSame(
            '{"version":2}',
            RecordedResponse::fromFile($this->files->get($this->fixturePath . '/cached-user.json'))->data,
        );

        $cached = (new TestConnector)->send(new WithoutCacheUserRequest);

        $this->assertTrue($cached->isCached());
        $this->assertSame(['version' => 1], $cached->json());
    }

    /**
     * Get the user returned by the test API.
     *
     * @return array<string, string>
     */
    protected function user(): array
    {
        return [
            'name' => 'Sammyjo20',
            'actual_name' => 'Sam',
            'twitter' => '@carre_sam',
        ];
    }
}

class MiddlewareTestConnector extends TestConnector
{
    /**
     * Create a connector that registers the given middleware.
     */
    public function __construct(
        protected Closure $onRequest,
        protected Closure $onResponse,
    ) {
        parent::__construct();
    }

    /**
     * Register the connector middleware.
     */
    public function boot(PendingRequest $pendingRequest): void
    {
        $pendingRequest->middleware()->onRequest($this->onRequest);
        $pendingRequest->middleware()->onResponse($this->onResponse);
    }
}

class WithoutCacheUserRequest extends UserRequest implements Cacheable
{
    use HasCaching;

    /**
     * Define the endpoint for the request.
     */
    public function resolveEndpoint(): string
    {
        return '/cached-user';
    }

    /**
     * Get the cache lifetime.
     */
    public function cacheFor(): int
    {
        return 60;
    }
}
