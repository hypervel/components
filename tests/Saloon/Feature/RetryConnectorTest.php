<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Feature;

use Exception;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Saloon\Exceptions\Request\FatalRequestException;
use Hypervel\Saloon\Exceptions\Request\RequestException;
use Hypervel\Saloon\Exceptions\Request\Statuses\InternalServerErrorException;
use Hypervel\Saloon\Http\Auth\TokenAuthenticator;
use Hypervel\Saloon\Http\Faking\MockClient;
use Hypervel\Saloon\Http\Faking\MockResponse;
use Hypervel\Saloon\Http\PendingRequest;
use Hypervel\Saloon\SaloonServiceProvider;
use Hypervel\Support\Sleep;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Saloon\Fixtures\Connectors\RetryConnector;
use Hypervel\Tests\Saloon\Fixtures\Requests\RetryUserRequest;
use Hypervel\Tests\Saloon\Fixtures\Requests\UserRequest;

// Upstream's connector retry properties ($tries, $retryInterval, $throwOnMaxTries) and handleRetry() map to the
// connector's default retry policy, which applies to requests without their own policy. Connectors are read-only,
// so the middleware in these cases is registered on the request.
class RetryConnectorTest extends TestCase
{
    /**
     * Get the package providers.
     */
    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [SaloonServiceProvider::class];
    }

    public function testAFailedRequestCanBeRetried(): void
    {
        $mockClient = new MockClient([
            MockResponse::make(['name' => 'Sam'], 500),
            MockResponse::make(['name' => 'Gareth'], 500),
            MockResponse::make(['name' => 'Teodor'], 200),
        ]);

        $response = (new RetryConnector(3))->send(new UserRequest, $mockClient);

        $this->assertSame(200, $response->status());
        $this->assertSame(['name' => 'Teodor'], $response->json());

        $mockClient->assertSentCount(3);
    }

    public function testIfTheAttemptsAreExhaustedItWillThrowAnExceptionFromTheLastRequest(): void
    {
        $mockClient = new MockClient([
            MockResponse::make(['name' => 'Sam'], 500),
            MockResponse::make(['name' => 'Gareth'], 500),
            MockResponse::make(['name' => 'Teodor'], 500),
        ]);

        try {
            (new RetryConnector(3))->send(new UserRequest, $mockClient);

            $this->fail('The request did not throw.');
        } catch (InternalServerErrorException $exception) {
            $this->assertSame(['name' => 'Teodor'], $exception->response()->json());
        }

        $mockClient->assertSentCount(3);
    }

    public function testIfTheAttemptsAreExhaustedItWillReturnTheLastResponseIfThrowingIsDisabled(): void
    {
        $mockClient = new MockClient([
            MockResponse::make(['name' => 'Sam'], 500),
            MockResponse::make(['name' => 'Gareth'], 500),
            MockResponse::make(['name' => 'Teodor'], 500),
        ]);

        $response = (new RetryConnector(3, throw: false))->send(new UserRequest, $mockClient);

        $this->assertSame(['name' => 'Teodor'], $response->json());

        $mockClient->assertSentCount(3);
    }

    public function testIfAFatalRequestExceptionHappensEvenWithThrowDisabledItWillThrowTheFatalRequestException(): void
    {
        $mockClient = new MockClient([
            MockResponse::make(['name' => 'Sam'], 500),
            MockResponse::make(['name' => 'Gareth'], 500),
            MockResponse::make(['name' => 'Teodor'], 500)->throw(
                fn (PendingRequest $pendingRequest): FatalRequestException => new FatalRequestException(new Exception, $pendingRequest),
            ),
        ]);

        $this->expectException(FatalRequestException::class);

        (new RetryConnector(3, throw: false))->send(new UserRequest, $mockClient);
    }

    public function testAFailedRequestCanHaveAnIntervalBetweenEachAttempt(): void
    {
        Sleep::fake();

        $mockClient = new MockClient([
            MockResponse::make(['name' => 'Sam'], 500),
            MockResponse::make(['name' => 'Gareth'], 500),
            MockResponse::make(['name' => 'Teodor'], 200),
        ]);

        (new RetryConnector(3, 1000))->send(new UserRequest, $mockClient);

        // Upstream waits in real time; the two intervals follow the first and second attempts.
        Sleep::assertSequence([
            Sleep::for(1000)->milliseconds(),
            Sleep::for(1000)->milliseconds(),
        ]);
    }

    public function testAnExceptionOtherThanARequestExceptionWillNotBeRetried(): void
    {
        $mockClient = new MockClient([
            MockResponse::make(['name' => 'Sam'], 500),
            MockResponse::make(['name' => 'Gareth'], 500),
            MockResponse::make(['name' => 'Teodor'], 200),
        ]);
        $request = new UserRequest;
        $request->middleware()->onResponse(fn (): never => throw new Exception('Yee-naw!'));

        try {
            (new RetryConnector(3))->send($request, $mockClient);

            $this->fail('The request did not throw.');
        } catch (Exception $exception) {
            $this->assertSame('Yee-naw!', $exception->getMessage());
        }

        $mockClient->assertSentCount(1);
    }

    public function testYouCanCustomiseIfTheMethodShouldRetry(): void
    {
        $mockClient = new MockClient([
            MockResponse::make(['name' => 'Sam'], 500),
            MockResponse::make(['name' => 'Gareth'], 500),
            MockResponse::make(['name' => 'Teodor'], 200),
        ]);
        $connector = new RetryConnector(3, when: function (RequestException $exception): bool {
            return $exception->response()->json() !== ['name' => 'Gareth'];
        });

        $this->expectException(InternalServerErrorException::class);
        $this->expectExceptionMessageIs("HTTP request returned status code 500:\n{\"name\":\"Gareth\"}\n");

        $connector->send(new UserRequest, $mockClient);
    }

    public function testIfTheHandleRetryReturnsFalseItWillThrowAnException(): void
    {
        $mockClient = new MockClient([
            MockResponse::make(['name' => 'Sam'], 500),
            MockResponse::make(['name' => 'Gareth'], 500),
            MockResponse::make(['name' => 'Teodor'], 200),
        ]);

        $this->expectException(InternalServerErrorException::class);
        $this->expectExceptionMessageIs("HTTP request returned status code 500:\n{\"name\":\"Sam\"}\n");

        (new RetryConnector(3, when: fn (): bool => false))->send(new UserRequest, $mockClient);
    }

    public function testIfTheHandleRetryReturnsFalseAndThrowOptionIsDisabledItWillReturnAResponse(): void
    {
        $mockClient = new MockClient([
            MockResponse::make(['name' => 'Sam'], 500),
            MockResponse::make(['name' => 'Gareth'], 500),
            MockResponse::make(['name' => 'Teodor'], 200),
        ]);

        $response = (new RetryConnector(5, when: fn (): bool => false, throw: false))->send(new UserRequest, $mockClient);

        $this->assertSame(500, $response->status());
        $this->assertSame(['name' => 'Sam'], $response->json());
    }

    public function testIfTheHandleRetryReturnsFalseAndThrowOptionIsDisabledButAFatalRequestExceptionHappensItWillStillThrow(): void
    {
        $mockClient = new MockClient([
            MockResponse::make(['name' => 'Sam'], 500)->throw(
                fn (PendingRequest $pendingRequest): FatalRequestException => new FatalRequestException(new Exception, $pendingRequest),
            ),
            MockResponse::make(['name' => 'Gareth'], 500),
            MockResponse::make(['name' => 'Teodor'], 200),
        ]);

        $this->expectException(FatalRequestException::class);

        (new RetryConnector(5, when: fn (): bool => false, throw: false))->send(new UserRequest, $mockClient);
    }

    public function testYouCanModifyTheRequestInsideTheRetryHandler(): void
    {
        $mockClient = new MockClient([
            MockResponse::make(['name' => 'Sam'], 500),
            MockResponse::make(['name' => 'Gareth'], 500),
            MockResponse::make(['name' => 'Teodor'], 200),
        ]);
        $index = 0;
        $connector = new RetryConnector(5, when: function (
            RequestException $exception,
            PendingRequest $pendingRequest,
        ) use (&$index): bool {
            ++$index;

            $pendingRequest->request()->replaceHeaders(['X-Test-Index' => (string) $index]);

            return true;
        });

        $response = $connector->send(new UserRequest, $mockClient);

        $this->assertSame(200, $response->status());
        $this->assertSame(['name' => 'Teodor'], $response->json());
        $this->assertSame('2', $response->pendingRequest()->headers()['X-Test-Index']);
    }

    public function testYouCanAuthenticateTheRequestInsideTheRetryHandler(): void
    {
        $mockClient = new MockClient([
            MockResponse::make(['name' => 'Sam'], 401),
            MockResponse::make(['name' => 'Gareth'], 200),
        ]);
        $connector = new RetryConnector(2, when: function (
            RequestException $exception,
            PendingRequest $pendingRequest,
        ): bool {
            $pendingRequest->request()->authenticate(new TokenAuthenticator('newToken'));

            return true;
        });

        $response = $connector->send(new UserRequest, $mockClient);

        $this->assertSame(200, $response->status());
        $this->assertSame(['name' => 'Gareth'], $response->json());
        $this->assertSame('Bearer newToken', $response->pendingRequest()->headers()['Authorization']);
    }

    public function testTheResponsePipelineIsOnlyExecutedOnceWhenRetrying(): void
    {
        $mockClient = new MockClient([
            MockResponse::make(['name' => 'Sam'], 500),
            MockResponse::make(['name' => 'Gareth'], 500),
        ]);
        $counter = 0;
        $request = new UserRequest;
        $request->middleware()->onResponse(function () use (&$counter): void {
            ++$counter;
        });

        $response = (new RetryConnector(2, throw: false))->send($request, $mockClient);

        $this->assertSame(500, $response->status());
        $this->assertSame(['name' => 'Gareth'], $response->json());

        // Counter should be 2 as we have sent two requests.
        $this->assertSame(2, $counter);
    }

    public function testTheRequestsDefaultRetryPolicyReplacesTheConnectors(): void
    {
        $mockClient = new MockClient([
            MockResponse::make(['name' => 'Sam'], 500),
            MockResponse::make(['name' => 'Gareth'], 500),
        ]);
        $connector = new RetryConnector(3, when: fn (): bool => false);

        $response = $connector->send(new RetryUserRequest(2, throw: false), $mockClient);

        // The connector's condition would stop the retry; the request's policy replaces it entirely.
        $this->assertSame(['name' => 'Gareth'], $response->json());
        $mockClient->assertSentCount(2);
    }

    public function testAnExplicitSingleAttemptDisablesTheConnectorsRetries(): void
    {
        $mockClient = new MockClient([
            MockResponse::make(['name' => 'Sam'], 500),
            MockResponse::make(['name' => 'Gareth'], 200),
        ]);

        $response = (new RetryConnector(3))->send((new UserRequest)->retry(1), $mockClient);

        $this->assertSame(500, $response->status());
        $mockClient->assertSentCount(1);
    }
}
