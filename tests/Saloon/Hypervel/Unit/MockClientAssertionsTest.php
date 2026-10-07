<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Hypervel\Unit;

use Exception;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Saloon\Facades\Saloon;
use Hypervel\Saloon\Http\Faking\MockResponse;
use Hypervel\Saloon\Http\PendingRequest;
use Hypervel\Saloon\Http\Request;
use Hypervel\Saloon\Http\Response;
use Hypervel\Saloon\SaloonServiceProvider;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Saloon\Fixtures\Connectors\TestConnector;
use Hypervel\Tests\Saloon\Fixtures\Requests\ErrorRequest;
use Hypervel\Tests\Saloon\Fixtures\Requests\UserRequest;

// The closure assertions run against every recorded response, so the closure cases compare identities instead of
// asserting inside the callback.
class MockClientAssertionsTest extends TestCase
{
    /**
     * Get the package providers.
     */
    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [SaloonServiceProvider::class];
    }

    public function testThatAssertSentWorksWithARequest(): void
    {
        Saloon::fake([
            UserRequest::class => new MockResponse(['name' => 'Sam'], 200),
        ]);

        TestConnector::make()->send(new UserRequest);

        Saloon::assertSent(UserRequest::class);
    }

    public function testThatAssertSentWorksWithAClosure(): void
    {
        Saloon::fake([
            UserRequest::class => new MockResponse(['name' => 'Sam'], 200),
            ErrorRequest::class => new MockResponse(['error' => 'Server Error'], 500),
        ]);

        $originalRequest = new UserRequest;
        $originalResponse = TestConnector::make()->send($originalRequest);

        Saloon::assertSent(function (Request $request, Response $response) use ($originalRequest, $originalResponse): bool {
            return $request === $originalRequest && $response === $originalResponse;
        });

        $newRequest = new ErrorRequest;
        $newResponse = TestConnector::make()->send($newRequest);

        Saloon::assertSent(function (Request $request, Response $response) use ($newRequest, $newResponse): bool {
            return $request === $newRequest && $response === $newResponse;
        });
    }

    public function testThatAssertSentWorksWithAUrl(): void
    {
        Saloon::fake([
            UserRequest::class => new MockResponse(['name' => 'Sam'], 200),
        ]);

        TestConnector::make()->send(new UserRequest);

        Saloon::assertSent('saloon.dev/*');
        Saloon::assertSent('/user');
        Saloon::assertSent('api/user');
    }

    public function testThatAssertNotSentWorksWithARequest(): void
    {
        Saloon::fake([
            UserRequest::class => new MockResponse(['name' => 'Sam'], 200),
            ErrorRequest::class => new MockResponse(['error' => 'Server Error'], 500),
        ]);

        TestConnector::make()->send(new ErrorRequest);

        Saloon::assertNotSent(UserRequest::class);
    }

    public function testThatAssertNotSentWorksWithAClosure(): void
    {
        Saloon::fake([
            UserRequest::class => new MockResponse(['name' => 'Sam'], 200),
            ErrorRequest::class => new MockResponse(['error' => 'Server Error'], 500),
        ]);

        TestConnector::make()->send(new ErrorRequest);

        Saloon::assertNotSent(function (Request $request): bool {
            return $request instanceof UserRequest;
        });
    }

    public function testThatAssertNotSentWorksWithAUrl(): void
    {
        Saloon::fake([
            UserRequest::class => new MockResponse(['name' => 'Sam'], 200),
        ]);

        TestConnector::make()->send(new UserRequest);

        Saloon::assertNotSent('google.com/*');
        Saloon::assertNotSent('/error');
    }

    // REMOVED: that assertSentJson works properly - the deprecated assertSentJson() is not ported.

    public function testAssertNothingSentWorksProperly(): void
    {
        Saloon::fake([
            UserRequest::class => new MockResponse(['name' => 'Sam'], 200),
        ]);

        Saloon::assertNothingSent();
    }

    public function testAssertSentCountWorksProperly(): void
    {
        Saloon::fake([
            new MockResponse(['name' => 'Sam'], 200),
            new MockResponse(['name' => 'Taylor'], 200),
            new MockResponse(['name' => 'Marcel'], 200),
        ]);

        TestConnector::make()->send(new UserRequest);
        TestConnector::make()->send(new UserRequest);
        TestConnector::make()->send(new UserRequest);

        Saloon::assertSentCount(3);
    }

    public function testYouCanMockExceptions(): void
    {
        Saloon::fake([
            MockResponse::make(['name' => 'Sam']),
            MockResponse::make(['name' => 'Patrick'])->throw(fn (PendingRequest $pendingRequest): Exception => new Exception('Unable to connect!')),
        ]);

        $okResponse = TestConnector::make()->send(new UserRequest);

        $this->assertSame(['name' => 'Sam'], $okResponse->json());

        $this->expectException(Exception::class);
        $this->expectExceptionMessageIs('Unable to connect!');

        TestConnector::make()->send(new UserRequest);
    }

    public function testYouCanMockNormalExceptions(): void
    {
        Saloon::fake([
            MockResponse::make(['name' => 'Michael'])->throw(new Exception('Custom Exception!')),
        ]);

        $this->expectException(Exception::class);
        $this->expectExceptionMessageIs('Custom Exception!');

        TestConnector::make()->send(new UserRequest);
    }
}
