<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Unit;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Saloon\Http\Faking\MockClient;
use Hypervel\Saloon\Http\Faking\MockResponse;
use Hypervel\Saloon\Http\Request;
use Hypervel\Saloon\Http\Response;
use Hypervel\Saloon\SaloonServiceProvider;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Saloon\Fixtures\Connectors\TestConnector;
use Hypervel\Tests\Saloon\Fixtures\Requests\ConfigRequest;
use Hypervel\Tests\Saloon\Fixtures\Requests\ErrorRequest;
use Hypervel\Tests\Saloon\Fixtures\Requests\UserRequest;
use PHPUnit\Framework\ExpectationFailedException;

class MockClientAssertionsTest extends TestCase
{
    /**
     * Get the package providers.
     */
    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [SaloonServiceProvider::class];
    }

    public function testAssertSentWorksWithARequest(): void
    {
        $mockClient = new MockClient([
            UserRequest::class => MockResponse::make(['name' => 'Sam']),
        ]);

        (new TestConnector)->send(new UserRequest, $mockClient);

        $mockClient->assertSent(UserRequest::class);
    }

    public function testAssertSentWorksWithAClosure(): void
    {
        $mockClient = new MockClient([
            UserRequest::class => MockResponse::make(['name' => 'Sam']),
            ErrorRequest::class => MockResponse::make(['error' => 'Server Error'], 500),
        ]);

        $originalRequest = new UserRequest;
        $originalResponse = (new TestConnector)->send($originalRequest, $mockClient);

        $mockClient->assertSent(function (Request $request, Response $response) use ($originalRequest, $originalResponse): bool {
            return $request === $originalRequest && $response === $originalResponse;
        });

        $newRequest = new ErrorRequest;
        $newResponse = (new TestConnector)->send($newRequest, $mockClient);

        $mockClient->assertSent(function (Request $request, Response $response) use ($newRequest, $newResponse): bool {
            return $request === $newRequest && $response === $newResponse;
        });
    }

    public function testAssertSentWorksWithAUrl(): void
    {
        $mockClient = new MockClient([
            UserRequest::class => MockResponse::make(['name' => 'Sam']),
        ]);

        (new TestConnector)->send(new UserRequest, $mockClient);

        $mockClient->assertSent('saloon.dev/*');
        $mockClient->assertSent('/user');
        $mockClient->assertSent('api/user');
    }

    public function testAssertNotSentWorksWithARequest(): void
    {
        $mockClient = new MockClient([
            UserRequest::class => MockResponse::make(['name' => 'Sam']),
            ErrorRequest::class => MockResponse::make(['error' => 'Server Error'], 500),
        ]);

        (new TestConnector)->send(new ErrorRequest, $mockClient);

        $mockClient->assertNotSent(UserRequest::class);
    }

    public function testAssertNotSentWorksWithAClosure(): void
    {
        $mockClient = new MockClient([
            UserRequest::class => MockResponse::make(['name' => 'Sam']),
            ErrorRequest::class => MockResponse::make(['error' => 'Server Error'], 500),
        ]);

        (new TestConnector)->send(new ErrorRequest, $mockClient);

        $mockClient->assertNotSent(function (Request $request): bool {
            return $request instanceof UserRequest;
        });
    }

    public function testAssertNotSentWorksWithAUrl(): void
    {
        $mockClient = new MockClient([
            UserRequest::class => MockResponse::make(['name' => 'Sam']),
        ]);

        (new TestConnector)->send(new UserRequest, $mockClient);

        $mockClient->assertNotSent('google.com/*');
        $mockClient->assertNotSent('/error');
    }

    // REMOVED: assertSentJson tests - the deprecated assertSentJson() is not ported; use assertSent() with a closure.

    public function testAssertNothingSentWorksProperly(): void
    {
        $mockClient = new MockClient([
            UserRequest::class => MockResponse::make(['name' => 'Sam']),
        ]);

        $mockClient->assertNothingSent();
    }

    public function testAssertSentCountWorksProperly(): void
    {
        $mockClient = new MockClient([
            MockResponse::make(['name' => 'Sam']),
            MockResponse::make(['name' => 'Taylor']),
            MockResponse::make(['name' => 'Marcel']),
        ]);

        $connector = new TestConnector;

        $connector->send(new UserRequest, $mockClient);
        $connector->send(new UserRequest, $mockClient);
        $connector->send(new UserRequest, $mockClient);

        $mockClient->assertSentCount(3);
    }

    public function testCanAssertCountOfRequests(): void
    {
        $mockClient = new MockClient([
            MockResponse::make(['name' => 'Sam']),
            MockResponse::make(['name' => 'Taylor']),
            MockResponse::make(['name' => 'Marcel']),
            MockResponse::make(['message' => 'Error'], 500),
        ]);

        $connector = new TestConnector;

        $connector->send(new UserRequest, $mockClient);
        $connector->send(new UserRequest, $mockClient);
        $connector->send(new UserRequest, $mockClient);
        $connector->send(new ErrorRequest, $mockClient);

        $mockClient->assertSentCount(3, UserRequest::class);
        $mockClient->assertSentCount(1, ErrorRequest::class);
    }

    public function testAssertSentWithAClosureWorksWithMoreThanOneRequestInTheHistory(): void
    {
        $mockClient = new MockClient([
            MockResponse::make(['name' => 'Sam']),
            MockResponse::make(['name' => 'Taylor'], 201),
            MockResponse::make(['name' => 'Marcel'], 204),
        ]);

        $connector = new TestConnector;

        $connector->send(new UserRequest, $mockClient);
        $connector->send(new UserRequest, $mockClient);
        $connector->send(new UserRequest, $mockClient);

        $mockClient->assertSent(function (Request $request, Response $response): bool {
            return $response->json() === ['name' => 'Sam'] && $response->status() === 200;
        });

        $mockClient->assertSent(function (Request $request, Response $response): bool {
            return $response->json() === ['name' => 'Taylor'] && $response->status() === 201;
        });

        $mockClient->assertSent(function (Request $request, Response $response): bool {
            return $response->json() === ['name' => 'Marcel'] && $response->status() === 204;
        });
    }

    public function testAssertSentWithATypedClosureSkipsNonMatchingRequestsAndStillFindsAMatch(): void
    {
        $mockClient = new MockClient([
            MockResponse::make(['name' => 'Sam']),
            MockResponse::make(['message' => 'Error'], 500),
        ]);

        $connector = new TestConnector;

        // Recorded responses are checked in send order, so the non-matching request goes first.
        $connector->send(new ErrorRequest, $mockClient);
        $connector->send(new UserRequest, $mockClient);
        $calls = 0;

        $mockClient->assertSent(function (UserRequest $request) use (&$calls): bool {
            ++$calls;

            return $request->userId === null;
        });

        $this->assertSame(1, $calls);
    }

    public function testAssertSentWithAUnionTypedClosureSkipsNonMatchingRequestsAndStillFindsAMatch(): void
    {
        $mockClient = new MockClient([
            MockResponse::make(['config' => true]),
            MockResponse::make(['name' => 'Sam']),
            MockResponse::make(['message' => 'Error'], 500),
        ]);

        $connector = new TestConnector;

        // The non-matching request goes first so both assertions must skip it.
        $connector->send(new ConfigRequest, $mockClient);
        $connector->send(new UserRequest, $mockClient);
        $connector->send(new ErrorRequest, $mockClient);
        $calls = 0;

        $mockClient->assertSent(function (UserRequest|ErrorRequest $request) use (&$calls): bool {
            ++$calls;

            return $request instanceof UserRequest && $request->userId === null;
        });

        $this->assertSame(1, $calls);

        $mockClient->assertSent(function (UserRequest|ErrorRequest $request) use (&$calls): bool {
            ++$calls;

            return $request instanceof ErrorRequest;
        });

        $this->assertSame(3, $calls);
    }

    public function testAssertSentWithATypedClosureFailsAssertionWhenNoMatchingRequestTypeWasSent(): void
    {
        $mockClient = new MockClient([
            ErrorRequest::class => MockResponse::make(['message' => 'Error'], 500),
        ]);

        (new TestConnector)->send(new ErrorRequest, $mockClient);

        $this->expectException(ExpectationFailedException::class);

        $mockClient->assertSent(function (UserRequest $request): bool {
            return true;
        });
    }

    public function testTypedClosuresAcceptMixedObjectAndIntersectionTypes(): void
    {
        $mockClient = new MockClient([
            MockResponse::make(['name' => 'Sam']),
            MockResponse::make(['name' => 'Taylor']),
        ]);

        $connector = new TestConnector;

        $connector->send(new UserRequest, $mockClient);
        $connector->send(new MarkedUserRequest, $mockClient);
        $intersectionCalls = 0;

        $mockClient->assertSent(fn (mixed $request): bool => $request instanceof MarkedUserRequest);
        $mockClient->assertSent(fn (object $request): bool => $request instanceof MarkedUserRequest);
        $mockClient->assertSent(function (UserRequest&MarkedRequest $request) use (&$intersectionCalls): bool {
            ++$intersectionCalls;

            return true;
        });
        $mockClient->assertNotSent(fn (string $request): bool => true);

        $this->assertSame(1, $intersectionCalls);
    }

    public function testItCanAssertRequestsAreSentInASpecificOrder(): void
    {
        $mockClient = new MockClient([
            MockResponse::make(['name' => 'Sam']),
            MockResponse::make(['name' => 'Taylor'], 201),
            MockResponse::make(['name' => 'Marcel'], 204),
        ]);

        $connector = new TestConnector;

        $connector->send(new UserRequest, $mockClient);
        $connector->send(new UserRequest, $mockClient);
        $connector->send(new UserRequest, $mockClient);

        $mockClient->assertSentInOrder([
            UserRequest::class,
            function (UserRequest $request, Response $response): bool {
                return $response->json() === ['name' => 'Taylor'];
            },
            '/user',
        ]);
    }

    public function testItCanAssertRequestsAreSentInASpecificOrderFailure(): void
    {
        $mockClient = new MockClient([
            MockResponse::make(['name' => 'Sam']),
            MockResponse::make(['name' => 'Taylor'], 201),
            MockResponse::make(['name' => 'Marcel'], 204),
        ]);

        $connector = new TestConnector;

        $connector->send(new UserRequest(userId: 2), $mockClient);
        $connector->send(new UserRequest(userId: 1), $mockClient);
        $connector->send(new UserRequest, $mockClient);

        $this->expectException(ExpectationFailedException::class);

        $mockClient->assertSentInOrder([
            UserRequest::class,
            function (UserRequest $request): bool {
                return $request->userId === 2;
            },
            '/user',
        ]);
    }
}

interface MarkedRequest
{
}

class MarkedUserRequest extends UserRequest implements MarkedRequest
{
}
