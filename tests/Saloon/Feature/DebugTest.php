<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Feature;

use Closure;
use GuzzleHttp\Psr7\Utils;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Saloon\Enums\Method;
use Hypervel\Saloon\Exceptions\Request\RequestException;
use Hypervel\Saloon\Http\Connector;
use Hypervel\Saloon\Http\Debugger;
use Hypervel\Saloon\Http\Faking\MockClient;
use Hypervel\Saloon\Http\Faking\MockResponse;
use Hypervel\Saloon\Http\PendingRequest;
use Hypervel\Saloon\Http\Request;
use Hypervel\Saloon\Http\Response;
use Hypervel\Saloon\SaloonServiceProvider;
use Hypervel\Saloon\Traits\Body\HasStringBody;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Saloon\Fixtures\Connectors\TestConnector;
use Hypervel\Tests\Saloon\Fixtures\Mocking\UnseekableBodyMockResponse;
use Hypervel\Tests\Saloon\Fixtures\Requests\AlwaysThrowRequest;
use Hypervel\Tests\Saloon\Fixtures\Requests\UserRequest;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Swoole\ExitException;
use Symfony\Component\VarDumper\Cloner\VarCloner;
use Symfony\Component\VarDumper\Dumper\CliDumper;
use Symfony\Component\VarDumper\VarDumper;

// Connectors are read-only, so connector debuggers are registered on the pending request from the connector's boot
// method, and the default dumpers are enabled on the request.
class DebugTest extends TestCase
{
    public function testAUserCanRegisterARequestAndResponseDebuggerOnTheConnectorAndRequest(): void
    {
        $mockClient = new MockClient([
            new MockResponse(['name' => 'Sam']),
        ]);

        $connectorRequestDebuggerValid = false;
        $connectorResponseDebuggerValid = false;

        $requestClassRequestDebuggerValid = false;
        $requestClassResponseDebuggerValid = false;

        // The connector can register callbacks to debug the request and the response

        $connector = new DebuggingConnectorStub(
            onRequest: function (PendingRequest $pendingRequest, RequestInterface $psrRequest) use (&$connectorRequestDebuggerValid): void {
                $connectorRequestDebuggerValid = true;
            },
            onResponse: function (Response $response, ResponseInterface $psrResponse) use (&$connectorResponseDebuggerValid): void {
                $connectorResponseDebuggerValid = true;
            },
        );

        $request = new UserRequest;

        // The request can register a callback to debug the request

        $request->debugRequest(function (PendingRequest $pendingRequest, RequestInterface $psrRequest) use (&$requestClassRequestDebuggerValid): void {
            $requestClassRequestDebuggerValid = true;
        });

        // The request can register a callback to debug the response

        $request->debugResponse(function (Response $response, ResponseInterface $psrResponse) use (&$requestClassResponseDebuggerValid): void {
            $requestClassResponseDebuggerValid = true;
        });

        $connector->send($request, $mockClient);

        // Check these are all true

        $this->assertTrue($connectorRequestDebuggerValid);
        $this->assertTrue($connectorResponseDebuggerValid);
        $this->assertTrue($requestClassRequestDebuggerValid);
        $this->assertTrue($requestClassResponseDebuggerValid);
    }

    public function testTheResponseDebuggerIsAlwaysExecutedBeforeUserMiddleware(): void
    {
        $mockClient = new MockClient([
            new MockResponse(['name' => 'Sam']),
        ]);

        $middlewareOrder = [];

        $connector = new DebuggingConnectorStub(
            onResponse: function () use (&$middlewareOrder): void {
                $middlewareOrder[] = 'C';
            },
            responseMiddleware: function () use (&$middlewareOrder): void {
                $middlewareOrder[] = 'A';
            },
        );
        $request = new UserRequest;

        $request->middleware()->onResponse(function () use (&$middlewareOrder): void {
            $middlewareOrder[] = 'B';
        });

        $request->debugResponse(function () use (&$middlewareOrder): void {
            $middlewareOrder[] = 'D';
        });

        $connector->send($request, $mockClient);

        // Even though the user has registered response middleware, the debugger should always come first.
        $this->assertSame(['C', 'D', 'A', 'B'], $middlewareOrder);
    }

    public function testTheResponseDebuggerIsAlwaysExecutedBeforeTheAlwaysThrowOnErrorsTrait(): void
    {
        $mockClient = new MockClient([
            new MockResponse(['name' => 'Sam'], 500),
        ]);

        $middlewareCount = 0;

        $connector = new DebuggingConnectorStub(onResponse: function () use (&$middlewareCount): void {
            ++$middlewareCount;
        });
        $request = new AlwaysThrowRequest;

        $request->debugResponse(function () use (&$middlewareCount): void {
            ++$middlewareCount;
        });

        try {
            $connector->send($request, $mockClient);

            $this->fail('The request did not throw.');
        } catch (RequestException) {
            $this->assertSame(2, $middlewareCount);
        }
    }

    public function testTheDefaultDebugRequestDriverWillDumpAnOutputUsingSymfonyVarDumper(): void
    {
        $output = $this->dump(fn (): Response => (new TestConnector)->send(
            (new UserRequest)->debugRequest(),
            new MockClient([new MockResponse(['name' => 'Sam'], 500)]),
        ));

        $expected = <<<'END'
            Saloon Request (UserRequest) -> array:6 [
              "connector" => "Hypervel\Tests\Saloon\Fixtures\Connectors\TestConnector"
              "request" => "Hypervel\Tests\Saloon\Fixtures\Requests\UserRequest"
              "method" => "GET"
              "uri" => "https://tests.saloon.dev/api/user"
              "headers" => array:2 [
                "Host" => "tests.saloon.dev"
                "Accept" => "application/json"
              ]
              "body" => ""
            ]
            END;

        $this->assertSame($expected . "\n", $output);
    }

    public function testTheDefaultDebugResponseDriverWillDumpAnOutputUsingSymfonyVarDumper(): void
    {
        $output = $this->dump(fn (): Response => (new TestConnector)->send(
            (new UserRequest)->debugResponse(),
            new MockClient([new MockResponse(['name' => 'Sam'], 500)]),
        ));

        $expected = <<<'END'
            Saloon Response (UserRequest) -> array:3 [
              "status" => 500
              "headers" => []
              "body" => "{"name":"Sam"}"
            ]
            END;

        $this->assertSame($expected . "\n", $output);
    }

    public function testTheDebugMethodWillOutputBothRequestAndResponseAtTheSameTime(): void
    {
        $output = $this->dump(fn (): Response => (new TestConnector)->send(
            (new UserRequest)->debug(),
            new MockClient([new MockResponse(['name' => 'Sam'], 500)]),
        ));

        $expected = <<<'END'
            Saloon Request (UserRequest) -> array:6 [
              "connector" => "Hypervel\Tests\Saloon\Fixtures\Connectors\TestConnector"
              "request" => "Hypervel\Tests\Saloon\Fixtures\Requests\UserRequest"
              "method" => "GET"
              "uri" => "https://tests.saloon.dev/api/user"
              "headers" => array:2 [
                "Host" => "tests.saloon.dev"
                "Accept" => "application/json"
              ]
              "body" => ""
            ]
            Saloon Response (UserRequest) -> array:3 [
              "status" => 500
              "headers" => []
              "body" => "{"name":"Sam"}"
            ]
            END;

        $this->assertSame($expected . "\n", $output);
    }

    // Upstream replaces its die handler. Saloon calls exit, which throws Swoole's ExitException inside a coroutine.
    public function testTheDebugMethodCanKillTheApplication(): void
    {
        try {
            $this->dump(fn (): Response => (new TestConnector)->send(
                (new UserRequest)->debug(die: true),
                new MockClient([new MockResponse(['name' => 'Sam'], 500)]),
            ));

            $this->fail('The application was not killed.');
        } catch (ExitException $exception) {
            $this->assertSame(1, $exception->getStatus());
        }
    }

    public function testTheResponseDebuggerReceivesAResponseWithFullBodyWhenTheStreamIsUnseekable(): void
    {
        $expectedBody = '{"name":"Jon"}';
        $mockClient = new MockClient([
            new UnseekableBodyMockResponse(['name' => 'Jon'], 200),
        ]);

        $debuggerReceivedBody = null;
        $debuggerBodyIsSeekable = null;

        $connector = new DebuggingConnectorStub(
            onResponse: function (Response $response, ResponseInterface $psrResponse) use (&$debuggerReceivedBody, &$debuggerBodyIsSeekable): void {
                $debuggerReceivedBody = $response->body();
                $debuggerBodyIsSeekable = $psrResponse->getBody()->isSeekable();
            },
        );

        $response = $connector->send(new UserRequest, $mockClient);

        $this->assertSame($expectedBody, $debuggerReceivedBody);
        $this->assertTrue($debuggerBodyIsSeekable);
        $this->assertSame($expectedBody, $response->body());
    }

    public function testRequestDebuggerObservesTheFinalPsrRequestExactlyOnce(): void
    {
        $capturedRequest = null;
        $request = (new DebugRequestStub)
            ->withBody('request-body', null)
            ->debugRequest(function (PendingRequest $pendingRequest, RequestInterface $psrRequest) use (&$capturedRequest): void {
                $capturedRequest = $psrRequest;
            });
        $pendingRequest = (new DebugConnectorStub)->createPendingRequest($request)
            ->finalizeUri()
            ->prepareBody();

        $pendingRequest->createPsrRequest();

        $this->assertSame(1, DebugRequestStub::$psrHookCalls);
        $this->assertInstanceOf(RequestInterface::class, $capturedRequest);
        $this->assertSame('handled', $capturedRequest->getHeaderLine('X-Psr-Hook'));
        $this->assertSame('request-body', (string) $capturedRequest->getBody());
    }

    public function testDefaultRequestDebuggerRestoresTheSeekableBodyPosition(): void
    {
        $body = Utils::streamFor('request-body');
        $body->seek(4);

        $this->assertSame('request-body', DebuggerReaderStub::readBody($body));

        $this->assertSame(4, $body->tell());
        $this->assertSame('est-body', $body->getContents());
    }

    /**
     * Set up the test environment.
     */
    protected function setUp(): void
    {
        parent::setUp();

        DebugRequestStub::$psrHookCalls = 0;
    }

    /**
     * Run the callback and return what it dumps.
     */
    protected function dump(Closure $callback): string
    {
        $output = fopen('php://memory', 'rwb+');

        VarDumper::setHandler(static function (mixed $var, ?string $label = null) use ($output): void {
            $var = (new VarCloner)->cloneVar($var);

            if ($label !== null) {
                $var = $var->withContext(['label' => $label]);
            }

            (new CliDumper)->dump($var, $output);
        });

        try {
            $callback();
        } finally {
            VarDumper::setHandler(null);
        }

        rewind($output);

        return stream_get_contents($output);
    }

    /**
     * Get the package providers.
     */
    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [SaloonServiceProvider::class];
    }
}

class DebuggingConnectorStub extends TestConnector
{
    /**
     * Create a connector that registers debuggers for each operation.
     */
    public function __construct(
        protected readonly ?Closure $onRequest = null,
        protected readonly ?Closure $onResponse = null,
        protected readonly ?Closure $responseMiddleware = null,
    ) {
        parent::__construct();
    }

    /**
     * Configure a pending request for this resource.
     */
    public function boot(PendingRequest $pendingRequest): void
    {
        if ($this->onRequest !== null) {
            $pendingRequest->debugRequest($this->onRequest);
        }

        if ($this->onResponse !== null) {
            $pendingRequest->debugResponse($this->onResponse);
        }

        if ($this->responseMiddleware !== null) {
            $pendingRequest->middleware()->onResponse($this->responseMiddleware);
        }
    }
}

class DebugConnectorStub extends Connector
{
    /**
     * Resolve the integration base URL.
     */
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

    /**
     * Resolve the request endpoint.
     */
    public function resolveEndpoint(): string
    {
        return '/users';
    }

    /**
     * Modify the final PSR request.
     */
    public function handlePsrRequest(RequestInterface $request, PendingRequest $pendingRequest): RequestInterface
    {
        ++static::$psrHookCalls;

        return $request->withHeader('X-Psr-Hook', 'handled');
    }
}

class DebuggerReaderStub extends Debugger
{
    /**
     * Read a request body through the debugger.
     */
    public static function readBody(StreamInterface $body): string
    {
        return static::requestBody($body);
    }
}
