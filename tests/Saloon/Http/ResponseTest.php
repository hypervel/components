<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Http;

use GuzzleHttp\Psr7\NoSeekStream;
use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\Psr7\Response as PsrResponse;
use GuzzleHttp\Psr7\Utils;
use Hypervel\Contracts\Cache\Factory as CacheFactory;
use Hypervel\Http\Client\Response as HttpResponse;
use Hypervel\RateLimiter\RateLimiter;
use Hypervel\Saloon\Contracts\DataObjects\WithResponse;
use Hypervel\Saloon\Enums\Method;
use Hypervel\Saloon\Exceptions\Request\ClientException;
use Hypervel\Saloon\Exceptions\Request\RequestException;
use Hypervel\Saloon\Exceptions\Request\ServerException;
use Hypervel\Saloon\Http\Connector;
use Hypervel\Saloon\Http\PendingRequest;
use Hypervel\Saloon\Http\Request;
use Hypervel\Saloon\Http\Response;
use Hypervel\Saloon\Traits\Plugins\AlwaysThrowOnErrors;
use Hypervel\Tests\TestCase;
use LogicException;
use Mockery as m;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

class ResponseTest extends TestCase
{
    public function testBuiltInExceptionTypesAndTruncationArePreserved(): void
    {
        $clientResponse = $this->response(422)->truncateExceptionsAt(37);
        $serverResponse = $this->response(500)->truncateExceptionsAt(38);
        $request = new ResponseRequestStub;
        $request->throwVerdict = true;
        $generalResponse = $this->response(200, request: $request)->truncateExceptionsAt(39);

        $clientException = $clientResponse->toException();
        $serverException = $serverResponse->toException();
        $generalException = $generalResponse->toException();

        $this->assertInstanceOf(ClientException::class, $clientException);
        $this->assertSame(37, $clientException->truncateExceptionsAt);
        $this->assertInstanceOf(ServerException::class, $serverException);
        $this->assertSame(38, $serverException->truncateExceptionsAt);
        $this->assertInstanceOf(RequestException::class, $generalException);
        $this->assertSame(39, $generalException->truncateExceptionsAt);
    }

    public function testRequestFailureAndExceptionVerdictsTakePriorityOverConnectorVerdicts(): void
    {
        $connector = new ResponseConnectorStub;
        $connector->failureVerdict = true;
        $connector->customException = true;

        $request = new ResponseRequestStub;
        $request->failureVerdict = false;
        $request->throwVerdict = true;
        $request->customException = true;

        $response = $this->response(500, connector: $connector, request: $request);

        $this->assertFalse($response->failed());
        $this->assertInstanceOf(ResponseRequestExceptionStub::class, $response->toException());
    }

    public function testFailurePoliciesDriveOnErrorAndFailureNamedThrowingHelpers(): void
    {
        $connector = new ResponseConnectorStub;
        $connector->failureVerdict = true;
        $response = $this->response(200, connector: $connector);
        $called = false;

        $response->onError(function () use (&$called): void {
            $called = true;
        });

        $this->assertTrue($response->successful());
        $this->assertTrue($response->failed());
        $this->assertTrue($called);

        $request = new ResponseRequestStub;
        $request->failureVerdict = false;
        $suppressed = $this->response(404, connector: $connector, request: $request);

        $this->assertSame($suppressed, $suppressed->throwIfClientError());

        $this->expectException(ClientException::class);
        $suppressed->throwIfStatus(404);
    }

    public function testThrowPolicyCanSuppressAResponseThatStillReportsFailed(): void
    {
        $connector = new ResponseConnectorStub;
        $connector->throwVerdict = false;
        $request = new ResponseRequestStub;
        $request->throwVerdict = false;
        $response = $this->response(500, connector: $connector, request: $request);

        $this->assertTrue($response->failed());
        $this->assertNull($response->toException());
        $this->assertSame($response, $response->throw());
    }

    public function testAlwaysThrowOnErrorsUsesTheIntegrationThrowPolicy(): void
    {
        $request = new ResponseAlwaysThrowRequestStub;
        $pendingRequest = $this->pendingRequest(new ResponseConnectorStub, $request);
        $request->bootAlwaysThrowOnErrors($pendingRequest);
        $response = $this->response(500, request: $request, pendingRequest: $pendingRequest);

        $this->expectException(ServerException::class);
        $pendingRequest->executeResponsePipeline($response);
    }

    public function testResponseCanBeConvertedToADataUrl(): void
    {
        $pendingRequest = $this->pendingRequest(new ResponseConnectorStub, new ResponseRequestStub);
        $psrRequest = new PsrRequest('GET', 'https://api.example.com/users');
        $response = Response::fromResponse(
            new HttpResponse(new PsrResponse(200, ['Content-Type' => 'text/plain'], 'hello')),
            $pendingRequest,
            $psrRequest,
        );

        $this->assertSame('data:text/plain;base64,aGVsbG8=', $response->dataUrl());
    }

    public function testNonSeekableBodyIsBufferedOnce(): void
    {
        $pendingRequest = $this->pendingRequest(new ResponseConnectorStub, new ResponseRequestStub);
        $psrRequest = new PsrRequest('GET', 'https://api.example.com/users');
        $httpResponse = new HttpResponse(new PsrResponse(
            body: new NoSeekStream(Utils::streamFor('response body')),
        ));
        $response = Response::fromResponse($httpResponse, $pendingRequest, $psrRequest);

        $this->assertSame('response body', $response->body());
        $this->assertSame('response body', $response->body());
        $this->assertTrue($response->stream()->isSeekable());
        $this->assertSame($psrRequest, $response->toPsrRequest());
    }

    public function testBodyExportsPreservePositionsAndCallerOwnedResources(): void
    {
        $response = $this->response(200, body: 'response body');
        $response->stream()->seek(4);
        $resource = fopen('php://temp', 'wb+');
        $this->assertIsResource($resource);
        fwrite($resource, 'stale contents');

        $response->saveBodyToFile($resource, false);

        $this->assertIsResource($resource);
        $this->assertSame(4, $response->stream()->tell());
        $this->assertSame('response body', stream_get_contents($resource));
        fclose($resource);

        $rawStream = $response->getRawStream();
        $this->assertIsResource($rawStream);
        $this->assertSame('response body', stream_get_contents($rawStream));
        fclose($rawStream);
    }

    public function testFailedBodyExportDoesNotCloseACallerOwnedResource(): void
    {
        $source = m::mock(StreamInterface::class);
        $source->shouldReceive('isSeekable')->once()->andReturn(false);
        $source->shouldReceive('eof')->once()->andReturn(false);
        $source->shouldReceive('read')->once()->andThrow(new RuntimeException('read failed'));
        $response = $this->response(200, body: $source);
        $resource = fopen('php://temp', 'wb+');
        $this->assertIsResource($resource);

        try {
            $response->saveBodyToFile($resource, false);
            $this->fail('The response source should fail while being copied.');
        } catch (RuntimeException $exception) {
            $this->assertSame('read failed', $exception->getMessage());
            $this->assertIsResource($resource);
        } finally {
            if (is_resource($resource)) {
                fclose($resource);
            }
        }
    }

    public function testRequestDataObjectTakesPriorityOverConnectorDataObject(): void
    {
        $response = $this->response(
            200,
            connector: new ResponseDtoConnectorStub,
            request: new ResponseDtoRequestStub,
        );

        $this->assertInstanceOf(ResponseRequestDtoStub::class, $response->dto());
    }

    public function testConnectorCreatesTheDataObjectWhenTheRequestDoesNot(): void
    {
        $response = $this->response(200, connector: new ResponseDtoConnectorStub);

        $this->assertInstanceOf(ResponseConnectorDtoStub::class, $response->dto());
    }

    public function testDataObjectReceivesItsOriginalResponse(): void
    {
        $response = $this->response(200, request: new ResponseWithResponseDtoRequestStub);
        $dataObject = $response->dto();

        $this->assertInstanceOf(ResponseWithResponseDtoStub::class, $dataObject);
        $this->assertSame($response, $dataObject->getResponse());
    }

    public function testDtoOrFailPreservesTheRequestException(): void
    {
        $response = $this->response(500, request: new ResponseDtoRequestStub);

        try {
            $response->dtoOrFail();
            $this->fail('A failed response should not be converted into a data object.');
        } catch (LogicException $exception) {
            $this->assertSame('Unable to create a data transfer object because the response failed.', $exception->getMessage());
            $this->assertInstanceOf(ServerException::class, $exception->getPrevious());
        }
    }

    /**
     * Create a Saloon response for the given operation.
     */
    protected function response(
        int $status,
        StreamInterface|string $body = 'response body',
        ?Connector $connector = null,
        ?Request $request = null,
        ?PendingRequest $pendingRequest = null,
    ): Response {
        $connector ??= new ResponseConnectorStub;
        $request ??= new ResponseRequestStub;
        $pendingRequest ??= $this->pendingRequest($connector, $request);
        $psrRequest = new PsrRequest($request->method()->value, 'https://api.example.com/users');

        return Response::fromResponse(
            new HttpResponse(new PsrResponse($status, body: $body)),
            $pendingRequest,
            $psrRequest,
        );
    }

    /**
     * Create a pending request with isolated framework dependencies.
     */
    protected function pendingRequest(Connector $connector, Request $request): PendingRequest
    {
        return new PendingRequest(
            $connector,
            $request,
            m::mock(CacheFactory::class),
            m::mock(RateLimiter::class),
        );
    }
}

class ResponseConnectorStub extends Connector
{
    public ?bool $failureVerdict = null;

    public ?bool $throwVerdict = null;

    public bool $customException = false;

    public function resolveBaseUrl(): string
    {
        return 'https://api.example.com';
    }

    public function hasRequestFailed(Response $response): ?bool
    {
        return $this->failureVerdict;
    }

    public function shouldThrowRequestException(Response $response): bool
    {
        return $this->throwVerdict ?? $response->failed();
    }

    public function getRequestException(Response $response): ?RequestException
    {
        return $this->customException ? new ResponseConnectorExceptionStub($response) : null;
    }
}

class ResponseRequestStub extends Request
{
    public ?bool $failureVerdict = null;

    public ?bool $throwVerdict = null;

    public bool $customException = false;

    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/users';
    }

    public function hasRequestFailed(Response $response): ?bool
    {
        return $this->failureVerdict;
    }

    public function shouldThrowRequestException(Response $response): bool
    {
        return $this->throwVerdict ?? $response->failed();
    }

    public function getRequestException(Response $response): ?RequestException
    {
        return $this->customException ? new ResponseRequestExceptionStub($response) : null;
    }
}

class ResponseAlwaysThrowRequestStub extends ResponseRequestStub
{
    use AlwaysThrowOnErrors;
}

class ResponseDtoConnectorStub extends ResponseConnectorStub
{
    public function createDtoFromResponse(Response $response): ResponseConnectorDtoStub
    {
        return new ResponseConnectorDtoStub;
    }
}

class ResponseDtoRequestStub extends ResponseRequestStub
{
    public function createDtoFromResponse(Response $response): ResponseRequestDtoStub
    {
        return new ResponseRequestDtoStub;
    }
}

class ResponseWithResponseDtoRequestStub extends ResponseRequestStub
{
    public function createDtoFromResponse(Response $response): ResponseWithResponseDtoStub
    {
        return new ResponseWithResponseDtoStub;
    }
}

class ResponseConnectorDtoStub
{
}

class ResponseRequestDtoStub
{
}

class ResponseWithResponseDtoStub implements WithResponse
{
    protected ?Response $response = null;

    public function setResponse(Response $response): static
    {
        $this->response = $response;

        return $this;
    }

    public function getResponse(): Response
    {
        return $this->response ?? throw new LogicException('The response has not been set.');
    }
}

class ResponseConnectorExceptionStub extends RequestException
{
}

class ResponseRequestExceptionStub extends RequestException
{
}
