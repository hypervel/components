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
use Hypervel\Tests\Saloon\Fixtures\Connectors\TestConnector;
use Hypervel\Tests\Saloon\Fixtures\Requests\RetryUserRequest;
use Hypervel\Tests\Saloon\Fixtures\Requests\UserRequest;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;

// Upstream's request retry properties ($tries, $retryInterval, $throwOnMaxTries) and handleRetry() map to the
// request's default retry policy: attempts, the delay between attempts in milliseconds, throw and when. The when
// callback receives the failed attempt's pending request; changes to its request() apply to the next attempt.
class RetryRequestTest extends TestCase
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

        $response = (new TestConnector)->send(new RetryUserRequest(3), $mockClient);

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
            (new TestConnector)->send(new RetryUserRequest(3), $mockClient);

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

        $response = (new TestConnector)->send(new RetryUserRequest(3, throw: false), $mockClient);

        $this->assertSame(['name' => 'Teodor'], $response->json());

        $mockClient->assertSentCount(3);
    }

    public function testIfAFatalRequestExceptionHappensEvenWithThrowDisabledItWillThrowTheFatalRequestException(): void
    {
        $thrown = null;
        $mockClient = new MockClient([
            MockResponse::make(['name' => 'Sam'], 500),
            MockResponse::make(['name' => 'Gareth'], 500),
            MockResponse::make(['name' => 'Teodor'], 500)->throw(
                function (PendingRequest $pendingRequest) use (&$thrown): FatalRequestException {
                    return $thrown = new FatalRequestException(new Exception, $pendingRequest);
                },
            ),
        ]);

        try {
            (new TestConnector)->send(new RetryUserRequest(3, throw: false), $mockClient);

            $this->fail('The request did not throw.');
        } catch (FatalRequestException $exception) {
            $this->assertSame($thrown, $exception);
        }
    }

    public function testAFatalRequestExceptionFromAFakeResponseRunsTheFatalMiddlewareAndIsRetried(): void
    {
        $thrown = null;
        $fatalExceptions = [];
        $mockClient = new MockClient([
            MockResponse::make()->throw(
                function (PendingRequest $pendingRequest) use (&$thrown): FatalRequestException {
                    return $thrown = new FatalRequestException(new Exception('Connection refused.'), $pendingRequest);
                },
            ),
            MockResponse::make(['name' => 'Sam']),
        ]);
        $request = new RetryUserRequest(2);
        $request->middleware()->onFatalException(function (FatalRequestException $exception) use (&$fatalExceptions): void {
            $fatalExceptions[] = $exception;
        });

        $response = (new TestConnector)->send($request, $mockClient);

        $this->assertSame(['name' => 'Sam'], $response->json());
        $this->assertSame([$thrown], $fatalExceptions);
    }

    // Upstream waits in real time and separately asserts its custom sleep handler; Sleep::fake() covers both.
    public function testAFailedRequestCanHaveAnIntervalBetweenEachAttempt(): void
    {
        Sleep::fake();

        $mockClient = new MockClient([
            MockResponse::make(['name' => 'Sam'], 500),
            MockResponse::make(['name' => 'Gareth'], 500),
            MockResponse::make(['name' => 'Teodor'], 200),
        ]);

        (new TestConnector)->send(new RetryUserRequest(3, 200), $mockClient);

        Sleep::assertSequence([
            Sleep::for(200)->milliseconds(),
            Sleep::for(200)->milliseconds(),
        ]);
        $mockClient->assertSentCount(3);
    }

    // REMOVED: SendAndRetryTest. Connector::sendAndRetry() is deprecated upstream and its cases repeat this file's.
    // Its exponential backoff case is kept here using a delay closure instead of $useExponentialBackoff.
    public function testAFailedRequestCanHaveAnIntervalWithExponentialBackoffBetweenEachAttempt(): void
    {
        Sleep::fake();

        $mockClient = new MockClient([
            MockResponse::make(['name' => 'Sam'], 500),
            MockResponse::make(['name' => 'Gareth'], 500),
            MockResponse::make(['name' => 'Michael'], 500),
            MockResponse::make(['name' => 'Teodor'], 200),
        ]);

        (new TestConnector)->send(
            new RetryUserRequest(4, static fn (int $attempt): int => 1000 * 2 ** ($attempt - 1)),
            $mockClient,
        );

        Sleep::assertSequence([
            Sleep::for(1000)->milliseconds(),
            Sleep::for(2000)->milliseconds(),
            Sleep::for(4000)->milliseconds(),
        ]);
    }

    public function testAnExceptionOtherThanARequestExceptionWillNotBeRetried(): void
    {
        $mockClient = new MockClient([
            MockResponse::make(['name' => 'Sam'], 500),
            MockResponse::make(['name' => 'Gareth'], 500),
            MockResponse::make(['name' => 'Teodor'], 200),
        ]);
        $request = new RetryUserRequest(3);
        $request->middleware()->onResponse(fn (): never => throw new Exception('Yee-naw!'));

        try {
            (new TestConnector)->send($request, $mockClient);

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

        $this->expectException(InternalServerErrorException::class);
        $this->expectExceptionMessage("HTTP request returned status code 500:\n{\"name\":\"Gareth\"}\n");

        (new TestConnector)->send(new RetryUserRequest(3, when: function (RequestException $exception): bool {
            return $exception->response()->json() !== ['name' => 'Gareth'];
        }), $mockClient);
    }

    public function testIfTheHandleRetryReturnsFalseItWillThrowAnException(): void
    {
        $mockClient = new MockClient([
            MockResponse::make(['name' => 'Sam'], 500),
            MockResponse::make(['name' => 'Gareth'], 500),
            MockResponse::make(['name' => 'Teodor'], 200),
        ]);

        $this->expectException(InternalServerErrorException::class);
        $this->expectExceptionMessage("HTTP request returned status code 500:\n{\"name\":\"Sam\"}\n");

        (new TestConnector)->send(new RetryUserRequest(3, when: fn (): bool => false), $mockClient);
    }

    public function testIfTheHandleRetryReturnsFalseAndThrowOptionIsDisabledItWillReturnAResponse(): void
    {
        $mockClient = new MockClient([
            MockResponse::make(['name' => 'Sam'], 500),
            MockResponse::make(['name' => 'Gareth'], 500),
            MockResponse::make(['name' => 'Teodor'], 200),
        ]);

        $response = (new TestConnector)->send(
            new RetryUserRequest(3, when: fn (): bool => false, throw: false),
            $mockClient,
        );

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

        (new TestConnector)->send(new RetryUserRequest(3, when: fn (): bool => false, throw: false), $mockClient);
    }

    public function testYouCanModifyTheRequestInsideTheRetryHandler(): void
    {
        $mockClient = new MockClient([
            MockResponse::make(['name' => 'Sam'], 500),
            MockResponse::make(['name' => 'Gareth'], 500),
            MockResponse::make(['name' => 'Teodor'], 200),
        ]);
        $index = 0;

        $response = (new TestConnector)->send(new RetryUserRequest(5, when: function (
            RequestException $exception,
            PendingRequest $pendingRequest,
        ) use (&$index): bool {
            ++$index;

            $pendingRequest->request()->replaceHeaders(['X-Test-Index' => (string) $index]);

            return true;
        }), $mockClient);

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

        $response = (new TestConnector)->send(new RetryUserRequest(2, when: function (
            RequestException $exception,
            PendingRequest $pendingRequest,
        ): bool {
            $pendingRequest->request()->authenticate(new TokenAuthenticator('newToken'));

            return true;
        }), $mockClient);

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
        $request = new RetryUserRequest(2, throw: false);
        $request->middleware()->onResponse(function () use (&$counter): void {
            ++$counter;
        });

        $response = (new TestConnector)->send($request, $mockClient);

        $this->assertSame(500, $response->status());
        $this->assertSame(['name' => 'Gareth'], $response->json());

        // Counter should be 2 as we have sent two requests.
        $this->assertSame(2, $counter);
    }

    public function testAnExplicitRetryReplacesTheRequestsDefaultRetryPolicy(): void
    {
        $mockClient = new MockClient([
            MockResponse::make(['name' => 'Sam'], 500),
            MockResponse::make(['name' => 'Gareth'], 200),
        ]);

        $response = (new TestConnector)->send((new RetryUserRequest(2))->retry(1), $mockClient);

        $this->assertSame(500, $response->status());
        $mockClient->assertSentCount(1);
    }

    // Upstream raises negative attempts and intervals to their minimum. Hypervel rejects them; a zero interval is
    // valid and is the default.
    #[DataProvider('invalidRetryPolicies')]
    public function testInvalidAttemptsOrIntervalsAreRejected(int $times, int $sleepMilliseconds, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        (new UserRequest)->retry($times, $sleepMilliseconds);
    }

    /**
     * Get the invalid retry attempts and intervals.
     *
     * @return array<string, array{int, int, string}>
     */
    public static function invalidRetryPolicies(): array
    {
        return [
            'zero attempts' => [0, 0, 'Retry attempts must contain at least one attempt.'],
            'negative attempts' => [-1, 0, 'Retry attempts must contain at least one attempt.'],
            'negative interval' => [2, -1, 'The retry delay must be a non-negative integer.'],
        ];
    }
}
