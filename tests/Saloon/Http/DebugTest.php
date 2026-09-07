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
use Hypervel\Saloon\Enums\Method;
use Hypervel\Saloon\Http\Connector;
use Hypervel\Saloon\Http\Debugger;
use Hypervel\Saloon\Http\PendingRequest;
use Hypervel\Saloon\Http\Request;
use Hypervel\Saloon\Http\Response;
use Hypervel\Saloon\Traits\Body\HasStringBody;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

class DebugTest extends TestCase
{
    public function testRequestDebuggerObservesTheFinalPsrRequestExactlyOnce(): void
    {
        $capturedRequest = null;
        $request = (new DebugRequestStub)
            ->withBody('request-body', null)
            ->debugRequest(function (PendingRequest $pendingRequest, RequestInterface $psrRequest) use (&$capturedRequest): void {
                $capturedRequest = $psrRequest;
            });
        $pendingRequest = $this->pendingRequest($request)
            ->executeRequestPipeline()
            ->finalizeUri()
            ->prepareBody();

        $pendingRequest->createPsrRequest();

        $this->assertSame(1, DebugRequestStub::$psrHookCalls);
        $this->assertInstanceOf(RequestInterface::class, $capturedRequest);
        $this->assertSame('handled', $capturedRequest->getHeaderLine('X-Psr-Hook'));
        $this->assertSame('request-body', (string) $capturedRequest->getBody());
    }

    public function testResponseDebuggerBuffersANonSeekableBodyBeforeTheCallback(): void
    {
        $capturedBody = null;
        $request = (new DebugRequestStub)->debugResponse(
            function (Response $response, ResponseInterface $psrResponse) use (&$capturedBody): void {
                $capturedBody = $response->body();
                $this->assertTrue($psrResponse->getBody()->isSeekable());
            },
        );
        $pendingRequest = $this->pendingRequest($request);
        $psrRequest = new PsrRequest('GET', 'https://api.example.com/users');
        $response = $pendingRequest->createResponse(
            new HttpResponse(new PsrResponse(body: new NoSeekStream(Utils::streamFor('response-body')))),
            $psrRequest,
        );

        $result = $pendingRequest->executeResponsePipeline($response);

        $this->assertSame('response-body', $capturedBody);
        $this->assertSame('response-body', $result->body());
    }

    public function testDefaultRequestDebuggerRestoresTheSeekableBodyPosition(): void
    {
        $pendingRequest = $this->pendingRequest(new DebugRequestStub);
        $body = Utils::streamFor('request-body');
        $body->seek(4);
        $request = new PsrRequest('POST', 'https://api.example.com/users', body: $body);

        $this->assertSame('request-body', DebuggerReaderStub::readBody($body));

        $this->assertSame(4, $body->tell());
        $this->assertSame('est-body', $body->getContents());
    }

    protected function setUp(): void
    {
        parent::setUp();

        DebugRequestStub::$psrHookCalls = 0;
    }

    /**
     * Create a pending request with isolated framework dependencies.
     */
    protected function pendingRequest(Request $request): PendingRequest
    {
        return new PendingRequest(
            new DebugConnectorStub,
            $request,
            m::mock(CacheFactory::class),
            m::mock(RateLimiter::class),
        );
    }
}

class DebugConnectorStub extends Connector
{
    public function resolveBaseUrl(): string
    {
        return 'https://api.example.com';
    }
}

class DebugRequestStub extends Request
{
    use HasStringBody;

    public static int $psrHookCalls = 0;

    protected Method $method = Method::POST;

    public function resolveEndpoint(): string
    {
        return '/users';
    }

    public function handlePsrRequest(RequestInterface $request, PendingRequest $pendingRequest): RequestInterface
    {
        ++static::$psrHookCalls;

        return $request->withHeader('X-Psr-Hook', 'handled');
    }
}

class DebuggerReaderStub extends Debugger
{
    public static function readBody(StreamInterface $body): string
    {
        return static::requestBody($body);
    }
}
