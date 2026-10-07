<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Feature;

use GuzzleHttp\Exception\RequestException as GuzzleRequestException;
use GuzzleHttp\Exception\ResponseException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Http\Client\ConnectionException;
use Hypervel\Http\Client\Request as HttpRequest;
use Hypervel\Http\Client\RequestException as HttpRequestException;
use Hypervel\Saloon\Exceptions\Request\FatalRequestException;
use Hypervel\Saloon\Exceptions\Request\RequestException;
use Hypervel\Saloon\Exceptions\Request\ServerException;
use Hypervel\Saloon\Exceptions\Request\Statuses\InternalServerErrorException;
use Hypervel\Saloon\Http\Faking\MockClient;
use Hypervel\Saloon\Http\Faking\MockResponse;
use Hypervel\Saloon\Http\PendingRequest;
use Hypervel\Saloon\Http\Response;
use Hypervel\Saloon\SaloonServiceProvider;
use Hypervel\Support\Facades\Http;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Saloon\Fixtures\Connectors\BadResponseConnector;
use Hypervel\Tests\Saloon\Fixtures\Connectors\CustomExceptionConnector;
use Hypervel\Tests\Saloon\Fixtures\Connectors\CustomFailHandlerConnector;
use Hypervel\Tests\Saloon\Fixtures\Connectors\TestConnector;
use Hypervel\Tests\Saloon\Fixtures\Exceptions\ConnectorRequestException;
use Hypervel\Tests\Saloon\Fixtures\Exceptions\CustomRequestException;
use Hypervel\Tests\Saloon\Fixtures\Requests\BadResponseRequest;
use Hypervel\Tests\Saloon\Fixtures\Requests\CustomExceptionUserRequest;
use Hypervel\Tests\Saloon\Fixtures\Requests\CustomFailHandlerRequest;
use Hypervel\Tests\Saloon\Fixtures\Requests\NotFoundFailedRequest;
use Hypervel\Tests\Saloon\Fixtures\Requests\UserRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use Throwable;

// Saloon request exceptions extend the HTTP client's request exception, so their messages use its format and they
// have no sender exception as their previous exception. Custom exception classes set their message by overriding
// prepareMessage().
class RequestExceptionTest extends TestCase
{
    /**
     * Get the package providers.
     */
    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [SaloonServiceProvider::class];
    }

    // The real-transport case runs against the engine test server in tests/Integration/Saloon/Feature.

    public function testYouCanUseTheToExceptionMethodToGetTheDefaultRequestExceptionException(): void
    {
        $mockClient = new MockClient([
            MockResponse::make(['message' => 'Server Error'], 500),
        ]);

        $response = TestConnector::make()->send(new UserRequest, $mockClient);

        $this->assertInstanceOf(Response::class, $response);

        $exception = $response->toException();

        $this->assertInstanceOf(InternalServerErrorException::class, $exception);
        $this->assertInstanceOf(ServerException::class, $exception);
        $this->assertSame(static::message($response), $exception->getMessage());
        $this->assertNull($exception->getPrevious());

        $this->expectExceptionObject($exception);

        $response->throw();
    }

    // REMOVED: the two sendAsync() promise cases. Coroutine pools replace promises.

    public function testYouCanCustomiseTheExceptionHandlerOnAConnector(): void
    {
        $mockClient = new MockClient([
            MockResponse::make(['message' => 'Server Error'], 500),
        ]);

        $response = CustomExceptionConnector::make()->send(new UserRequest, $mockClient);
        $exception = $response->toException();

        $this->assertInstanceOf(ConnectorRequestException::class, $exception);
        $this->assertSame('Oh yee-naw.', $exception->getMessage());
    }

    public function testYouCanCustomiseTheExceptionHandlerOnARequest(): void
    {
        $mockClient = new MockClient([
            MockResponse::make(['message' => 'Server Error'], 500),
        ]);

        $response = TestConnector::make()->send(new CustomExceptionUserRequest, $mockClient);
        $exception = $response->toException();

        $this->assertInstanceOf(CustomRequestException::class, $exception);
        $this->assertSame('Oh yee-naw.', $exception->getMessage());
    }

    public function testTheRequestExceptionHandlerWillAlwaysTakePriority(): void
    {
        $mockClient = new MockClient([
            MockResponse::make(['message' => 'Server Error'], 500),
        ]);

        $response = CustomExceptionConnector::make()->send(new CustomExceptionUserRequest, $mockClient);
        $exception = $response->toException();

        $this->assertInstanceOf(CustomRequestException::class, $exception);
        $this->assertSame('Oh yee-naw.', $exception->getMessage());
    }

    public function testYouCanCustomiseIfSaloonShouldThrowAnExceptionOnAConnector(): void
    {
        $mockClient = new MockClient([
            MockResponse::make(['message' => 'Success']),
            MockResponse::make(['message' => 'Error: Invalid Cowboy Hat']),
        ]);

        $responseA = BadResponseConnector::make()->send(new UserRequest, $mockClient);

        $this->assertFalse($responseA->shouldThrowRequestException());
        $this->assertNull($responseA->toException());

        $responseB = BadResponseConnector::make()->send(new UserRequest, $mockClient);
        $this->assertTrue($responseB->shouldThrowRequestException());
        $exceptionB = $responseB->toException();

        $this->assertInstanceOf(RequestException::class, $exceptionB);
        $this->assertInstanceOf(PendingRequest::class, $exceptionB->pendingRequest());
        $this->assertInstanceOf(Response::class, $exceptionB->response());
        $this->assertSame(static::message($exceptionB->response()), $exceptionB->getMessage());
        $this->assertNull($exceptionB->getPrevious());
    }

    public function testYouCanCustomiseIfSaloonShouldThrowAnExceptionOnARequest(): void
    {
        $mockClient = new MockClient([
            MockResponse::make(['message' => 'Success']),
            MockResponse::make(['message' => 'Yee-naw: Horse Not Found']),
        ]);

        $responseA = TestConnector::make()->send(new BadResponseRequest, $mockClient);

        $this->assertFalse($responseA->shouldThrowRequestException());
        $this->assertNull($responseA->toException());

        $responseB = TestConnector::make()->send(new BadResponseRequest, $mockClient);
        $this->assertTrue($responseB->shouldThrowRequestException());
        $exceptionB = $responseB->toException();

        $this->assertInstanceOf(RequestException::class, $exceptionB);
        $this->assertInstanceOf(PendingRequest::class, $exceptionB->pendingRequest());
        $this->assertInstanceOf(Response::class, $exceptionB->response());
        $this->assertSame(static::message($exceptionB->response()), $exceptionB->getMessage());
        $this->assertNull($exceptionB->getPrevious());
    }

    public function testWhenBothTheConnectorAndRequestHaveCustomLogicToDetermineDifferentFailuresTheyWorkTogether(): void
    {
        $mockClient = new MockClient([
            MockResponse::make(['message' => 'Success']),
            MockResponse::make(['message' => 'Error: Invalid Cowboy Hat']),
            MockResponse::make(['message' => 'Yee-naw: Horse Not Found']),
        ]);

        $responseA = BadResponseConnector::make()->send(new BadResponseRequest, $mockClient);

        $this->assertFalse($responseA->shouldThrowRequestException());
        $this->assertNull($responseA->toException());

        $responseB = BadResponseConnector::make()->send(new BadResponseRequest, $mockClient);
        $this->assertTrue($responseB->shouldThrowRequestException());
        $exceptionB = $responseB->toException();

        $this->assertInstanceOf(RequestException::class, $exceptionB);
        $this->assertInstanceOf(PendingRequest::class, $exceptionB->pendingRequest());
        $this->assertInstanceOf(Response::class, $exceptionB->response());
        $this->assertSame(static::message($exceptionB->response()), $exceptionB->getMessage());
        $this->assertNull($exceptionB->getPrevious());

        $responseC = BadResponseConnector::make()->send(new BadResponseRequest, $mockClient);
        $this->assertTrue($responseC->shouldThrowRequestException());
        $exceptionC = $responseC->toException();

        $this->assertInstanceOf(RequestException::class, $exceptionC);
        $this->assertInstanceOf(Response::class, $exceptionC->response());
        $this->assertSame(static::message($exceptionC->response()), $exceptionC->getMessage());
        $this->assertNull($exceptionC->getPrevious());
    }

    public function testYouCanCustomiseIfSaloonDeterminesIfARequestHasFailedOnAConnector(): void
    {
        $mockClient = new MockClient([
            MockResponse::make(['message' => 'Success']),
            MockResponse::make(['message' => 'Error: Invalid Cowboy Hat']),
        ]);

        $responseA = CustomFailHandlerConnector::make()->send(new UserRequest, $mockClient);

        $this->assertFalse($responseA->failed());

        $responseB = CustomFailHandlerConnector::make()->send(new UserRequest, $mockClient);

        $this->assertTrue($responseB->failed());
    }

    public function testYouCanCustomiseIfSaloonDeterminesIfARequestHasFailedOnARequest(): void
    {
        $mockClient = new MockClient([
            MockResponse::make(['message' => 'Success']),
            MockResponse::make(['message' => 'Yee-naw: Horse Not Found']),
        ]);

        $responseA = TestConnector::make()->send(new CustomFailHandlerRequest, $mockClient);

        $this->assertFalse($responseA->failed());

        $responseB = TestConnector::make()->send(new CustomFailHandlerRequest, $mockClient);

        $this->assertTrue($responseB->failed());
    }

    // Upstream calls its live not-found endpoint; a mocked 404 response proves the same behavior.
    public function testARequestCanMarkARequestAsNotFailed(): void
    {
        $response = TestConnector::make()->send(new NotFoundFailedRequest, new MockClient([
            MockResponse::make(['message' => 'Not Found'], 404),
        ]));

        $this->assertFalse($response->failed());
    }

    // REMOVED: the sendAsync() case. Coroutine pools replace promises; see the pool case below.

    public function testARequestCanMarkARequestAsNotFailedWithPools(): void
    {
        $responseCount = 0;
        $exceptionCount = 0;

        $request = (new NotFoundFailedRequest)->withMockClient(new MockClient([
            MockResponse::make(['message' => 'Not Found'], 404),
        ]));

        $pool = TestConnector::make()->pool([$request]);

        $pool->withResponseHandler(function (Response $response) use (&$responseCount): void {
            $this->assertSame(404, $response->status());

            ++$responseCount;
        })->withExceptionHandler(function (Throwable $exception) use (&$exceptionCount): void {
            ++$exceptionCount;
        });

        $pool->send();

        $this->assertSame(1, $responseCount);
        $this->assertSame(0, $exceptionCount);
    }

    // Upstream connects to hostnames that do not resolve; a refused loopback port fails the same way without DNS.
    public function testTheSenderWillThrowAFatalRequestExceptionIfItCannotConnectToASite(): void
    {
        $connector = new TestConnector('http://127.0.0.1:1');
        $request = new UserRequest;

        $this->expectException(FatalRequestException::class);

        $connector->send($request);
    }

    // REMOVED: the sendAsync() case. Coroutine pools replace promises.

    #[DataProvider('incompleteTransfers')]
    public function testATransferThatFailsWhileReceivingTheResponseIsFatal(int $status, string $transportException): void
    {
        Http::fake(fn (HttpRequest $request): PromiseInterface => Create::rejectionFor(static::incompleteTransfer($request, $status)));

        $request = new UserRequest;
        $fatalExceptions = [];
        $request->middleware()->onFatalException(function (FatalRequestException $exception) use (&$fatalExceptions): void {
            $fatalExceptions[] = $exception;
        });

        try {
            TestConnector::make()->send($request);

            $this->fail('The incomplete transfer did not throw.');
        } catch (FatalRequestException $exception) {
            $this->assertInstanceOf(ConnectionException::class, $exception->getPrevious());
            $this->assertInstanceOf($transportException, $exception->getPrevious()->getPrevious());
            $this->assertSame([$exception], $fatalExceptions);
        }
    }

    /**
     * Get the statuses of incomplete transfers and the exceptions the HTTP client reports for them.
     *
     * @return array<string, array{int, class-string<Throwable>}>
     */
    public static function incompleteTransfers(): array
    {
        return [
            'successful status' => [200, GuzzleRequestException::class],
            'error status' => [502, HttpRequestException::class],
        ];
    }

    public function testAnIncompleteTransferIsRetried(): void
    {
        $attempts = 0;
        Http::fake(function (HttpRequest $request) use (&$attempts): PromiseInterface {
            return ++$attempts === 1
                ? Create::rejectionFor(static::incompleteTransfer($request, 502))
                : Http::response(['name' => 'Sam']);
        });

        $response = TestConnector::make()->send((new UserRequest)->retry(2));

        $this->assertSame(2, $attempts);
        $this->assertSame(['name' => 'Sam'], $response->json());
    }

    /**
     * Get the message the HTTP client's request exception gives a response.
     */
    protected static function message(Response $response): string
    {
        return sprintf("HTTP request returned status code %d:\n%s\n", $response->status(), $response->body());
    }

    /**
     * Create the exception cURL reports when a transfer fails after the response headers arrive.
     */
    protected static function incompleteTransfer(HttpRequest $request, int $status): GuzzleRequestException
    {
        $response = new PsrResponse($status, [], 'partial');

        return class_exists(ResponseException::class)
            ? new ResponseException('cURL error 56: Recv failure', $request->toPsrRequest(), $response)
            : new GuzzleRequestException('cURL error 56: Recv failure', $request->toPsrRequest(), $response);
    }
}
