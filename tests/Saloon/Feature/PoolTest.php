<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Feature;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Http\Client\ConnectionException;
use Hypervel\Saloon\Exceptions\PoolException;
use Hypervel\Saloon\Exceptions\Request\FatalRequestException;
use Hypervel\Saloon\Exceptions\Request\RequestException;
use Hypervel\Saloon\Facades\Saloon;
use Hypervel\Saloon\Http\Faking\MockResponse;
use Hypervel\Saloon\Http\PendingRequest;
use Hypervel\Saloon\Http\Response;
use Hypervel\Saloon\SaloonServiceProvider;
use Hypervel\Support\Facades\Http;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Saloon\Fixtures\Connectors\TestConnector;
use Hypervel\Tests\Saloon\Fixtures\Exceptions\TestResponseException;
use Hypervel\Tests\Saloon\Fixtures\Requests\CustomFailHandlerRequest;
use Hypervel\Tests\Saloon\Fixtures\Requests\ErrorRequest;
use Hypervel\Tests\Saloon\Fixtures\Requests\UserRequest;
use Hypervel\Tests\Saloon\Fixtures\Requests\UserRequestWithCustomResponse;
use Hypervel\Tests\Saloon\Fixtures\Responses\UserData;
use Hypervel\Tests\Saloon\Fixtures\Responses\UserResponse;
use RuntimeException;

// Pools send through coroutines, so send() returns the successful responses instead of a promise to wait on.
// Connectors take no mock client, so the global fake stands in for upstream's connector client.
// laravel-plugin's Feature/PoolTest repeats these cases, so this file and Integration/Saloon/Feature/PoolTest cover it.
// REMOVED: Feature/AsyncRequestTest - sendAsync() is not ported. Its live success and error cases map to
// Integration/Saloon/Feature/PoolTest, and its response middleware, custom response and send-time middleware cases to
// testPooledRequestsRunTheirMiddlewareWhenSentAndReturnCustomResponses.
class PoolTest extends TestCase
{
    /**
     * Get the package providers.
     */
    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [SaloonServiceProvider::class];
    }

    // Upstream's live "you can create a pool on a connector" case is in Integration/Saloon/Feature/PoolTest.

    // Upstream connects to a host that does not resolve; a refused loopback port fails the same way without DNS.
    public function testIfAPoolHasARequestThatCannotConnectItWillBeCaughtInTheHandleExceptionCallback(): void
    {
        $connector = new TestConnector('http://127.0.0.1:1');
        $exceptions = [];

        $pool = $connector->pool([
            new UserRequest,
            new UserRequest,
            new UserRequest,
            new UserRequest,
            new UserRequest,
        ]);

        $pool->setConcurrency(5);

        $pool->withExceptionHandler(function (FatalRequestException $exception, int $key) use (&$exceptions): void {
            $exceptions[$key] = $exception;
        });

        $this->assertSame([], $pool->send());
        $this->assertCount(5, $exceptions);

        foreach ($exceptions as $exception) {
            $this->assertInstanceOf(ConnectionException::class, $exception->getPrevious());
            $this->assertInstanceOf(PendingRequest::class, $exception->pendingRequest());
        }
    }

    public function testYouCanUsePoolWithAMockClientAddedAndItWontSendRealRequests(): void
    {
        Http::fake();

        $mockResponses = [
            MockResponse::make(['name' => 'Sam']),
            MockResponse::make(['name' => 'Charlotte']),
            MockResponse::make(['name' => 'Mantas']),
            MockResponse::make(['name' => 'Emily']),
            MockResponse::make(['name' => 'Error'], 500),
        ];

        Saloon::fake($mockResponses);

        $connector = new TestConnector;
        $handled = [];
        $exceptions = [];

        $pool = $connector->pool([
            new UserRequest,
            new UserRequest,
            new UserRequest,
            new UserRequest,
            new ErrorRequest,
        ]);

        $pool->setConcurrency(6);

        $pool->withResponseHandler(function (Response $response, int $key) use (&$handled): void {
            $handled[$key] = $response;
        });

        $pool->withExceptionHandler(function (RequestException $exception, int $key) use (&$exceptions): void {
            $exceptions[$key] = $exception;
        });

        $responses = $pool->send();

        $this->assertSame([0, 1, 2, 3], array_keys($handled));

        foreach ($handled as $key => $response) {
            $this->assertSame($mockResponses[$key]->body()->all(), $response->json());
        }

        $this->assertSame([4], array_keys($exceptions));
        $this->assertSame(500, $exceptions[4]->response()->status());
        $this->assertSame(['name' => 'Error'], $exceptions[4]->response()->json());
        $this->assertEquals($exceptions[4]->response()->toException(), $exceptions[4]);
        $this->assertSame($handled, $responses);
        Http::assertNothingSent();
    }

    public function testPooledRequestsRunTheirMiddlewareWhenSentAndReturnCustomResponses(): void
    {
        Saloon::fake([
            MockResponse::make(['name' => 'Sam']),
            MockResponse::make(['foo' => 'bar']),
        ]);

        $request = new UserRequest;
        $events = [];
        $request->middleware()
            ->onRequest(function () use (&$events): void {
                $events[] = 'request';
            })
            ->onResponse(function () use (&$events): void {
                $events[] = 'response';
            });

        $pool = (new TestConnector)->pool([$request, new UserRequestWithCustomResponse]);

        $this->assertSame([], $events);

        $responses = $pool->send();

        $this->assertSame(['request', 'response'], $events);
        $this->assertSame(['name' => 'Sam'], $responses[0]->json());
        $this->assertSame(200, $responses[0]->status());
        $this->assertInstanceOf(UserResponse::class, $responses[1]);
        $this->assertInstanceOf(UserData::class, $responses[1]->customCastMethod());
        $this->assertSame('bar', $responses[1]->foo());
    }

    public function testHandledRequestFailuresAreOmittedAfterEveryChildSettles(): void
    {
        Saloon::fake([
            ErrorRequest::class => MockResponse::make()->throw(
                fn (PendingRequest $pendingRequest): TestResponseException => new TestResponseException('Unable to connect!', $pendingRequest),
            ),
            UserRequest::class => MockResponse::make(['ok' => true]),
        ]);
        $handled = [];

        $responses = (new TestConnector)->pool([
            'failed' => new ErrorRequest,
            'successful' => new UserRequest,
        ])->withExceptionHandler(function (TestResponseException $exception, string $key) use (&$handled): void {
            $handled[$key] = $exception;
        })->send();

        $this->assertSame(['failed'], array_keys($handled));
        $this->assertSame('Unable to connect!', $handled['failed']->getMessage());
        $this->assertInstanceOf(ErrorRequest::class, $handled['failed']->getPendingRequest()->request());
        $this->assertSame(['successful'], array_keys($responses));
    }

    public function testPoolExceptionPreservesRequestCallbackAndPartialResults(): void
    {
        $sendFailure = new RuntimeException('send failed');
        $callbackFailure = new RuntimeException('callback failed');
        Saloon::fake([
            ErrorRequest::class => MockResponse::make()->throw($sendFailure),
            UserRequest::class => MockResponse::make(['ok' => true]),
        ]);

        try {
            (new TestConnector)->pool([
                'failed' => new ErrorRequest,
                'successful' => new UserRequest,
            ])->withResponseHandler(function () use ($callbackFailure): never {
                throw $callbackFailure;
            })->send();
            $this->fail('The pool exception was not thrown.');
        } catch (PoolException $exception) {
            $this->assertSame(['failed' => $sendFailure], $exception->failures());
            $this->assertSame(['successful' => $callbackFailure], $exception->callbackFailures());
            $this->assertSame(['successful'], array_keys($exception->responses()));
            $this->assertNull($exception->orchestrationFailure());
            $this->assertSame($sendFailure, $exception->getPrevious());
        }
    }

    public function testThrowableResponsesWithoutAnExceptionHandlerArePoolFailures(): void
    {
        $mockClient = Saloon::fake([
            ErrorRequest::class => MockResponse::make(['message' => 'Server Error'], 500),
            CustomFailHandlerRequest::class => MockResponse::make('Yee-naw: Something went wrong'),
            UserRequest::class => MockResponse::make(['ok' => true]),
        ]);

        try {
            (new TestConnector)->pool([
                'server-error' => new ErrorRequest,
                'custom-failure' => new CustomFailHandlerRequest,
                'successful' => new UserRequest,
            ])->send();
            $this->fail('The pool exception was not thrown.');
        } catch (PoolException $exception) {
            $failures = $exception->failures();
            $recorded = $mockClient->recorded()->keyBy(fn (Response $response): string => $response->request()::class);

            $this->assertSame(['server-error', 'custom-failure'], array_keys($failures));
            $this->assertInstanceOf(RequestException::class, $failures['server-error']);
            $this->assertSame($recorded[ErrorRequest::class], $failures['server-error']->response());
            $this->assertSame(500, $failures['server-error']->status());
            $this->assertInstanceOf(RequestException::class, $failures['custom-failure']);
            $this->assertSame($recorded[CustomFailHandlerRequest::class], $failures['custom-failure']->response());
            $this->assertSame(200, $failures['custom-failure']->status());
            $this->assertSame([], $exception->callbackFailures());
            $this->assertSame(['successful'], array_keys($exception->responses()));
            $this->assertSame($failures['server-error'], $exception->getPrevious());
        }
    }

    public function testACallbackFailureIsThePoolExceptionCauseWhenNoRequestFails(): void
    {
        $callbackFailure = new RuntimeException('callback failed');
        Saloon::fake([MockResponse::make(['ok' => true])]);

        try {
            (new TestConnector)->pool(['successful' => new UserRequest])
                ->withResponseHandler(function () use ($callbackFailure): never {
                    throw $callbackFailure;
                })
                ->send();
            $this->fail('The pool exception was not thrown.');
        } catch (PoolException $exception) {
            $this->assertSame([], $exception->failures());
            $this->assertSame(['successful' => $callbackFailure], $exception->callbackFailures());
            $this->assertSame(['successful'], array_keys($exception->responses()));
            $this->assertSame($callbackFailure, $exception->getPrevious());
        }
    }
}
