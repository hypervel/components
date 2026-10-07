<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Feature;

use GuzzleHttp\Promise\PromiseInterface;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Http\Client\Request as HttpRequest;
use Hypervel\Saloon\SaloonServiceProvider;
use Hypervel\Support\Facades\Http;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Saloon\Fixtures\Connectors\TestConnector;
use Hypervel\Tests\Saloon\Fixtures\Requests\UserRequest;
use Psr\Http\Message\RequestInterface;

// Saloon sends through Hypervel's HTTP client rather than its own Guzzle sender, so these cases capture the request
// and options that reach the HTTP client.
class GuzzleSenderTest extends TestCase
{
    /**
     * Get the package providers.
     */
    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [SaloonServiceProvider::class];
    }

    public function testTheGuzzleSenderWillSendToTheRightUrlUsingTheCorrectMethod(): void
    {
        $connector = new TestConnector;
        $request = new UserRequest;

        $pendingRequest = $connector->createPendingRequest($request);

        $sent = null;
        Http::fake(function (HttpRequest $request) use (&$sent): PromiseInterface {
            $sent = $request->toPsrRequest();

            return Http::response();
        });

        $connector->send($request);

        $this->assertSame($pendingRequest->method()->value, $sent->getMethod());
        $this->assertSame((string) $pendingRequest->uri(), (string) $sent->getUri());
    }

    public function testTheGuzzleSenderWillSendAllHeadersQueryParametersAndConfig(): void
    {
        $request = (new UserRequest)
            ->withOptions(['timeout' => 120, 'debug' => true])
            ->withQueryParameters(['shanty' => 'yes', 'sing' => 'yes'])
            ->withHeader('X-Bound-For', 'South-Australia')
            ->withHeader('X-Fancy', ['keyOne' => 'valOne', 'keyTwo' => 'valTwo']);

        $sent = null;
        $sentOptions = null;
        Http::fake(function (HttpRequest $request, array $options) use (&$sent, &$sentOptions): PromiseInterface {
            $sent = $request->toPsrRequest();
            $sentOptions = $options;

            return Http::response();
        });

        (new TestConnector)->send($request);

        $this->assertSame(120, $sentOptions['timeout']);
        $this->assertTrue($sentOptions['debug']);
        $this->assertSame('shanty=yes&sing=yes', $sent->getUri()->getQuery());
        $this->assertSame('South-Australia', $sent->getHeaderLine('X-Bound-For'));
        $this->assertSame('valOne, valTwo', $sent->getHeaderLine('X-Fancy'));
    }

    // REMOVED: the default handler stack cases. Registered HTTP connections own the transport handler; Guzzle-level
    // hooks are Http::globalMiddleware(), which the next case covers, and Saloon's PSR request hooks and middleware.

    public function testHttpGlobalMiddlewareRunsOnSaloonRequests(): void
    {
        Http::globalRequestMiddleware(
            fn (RequestInterface $request): RequestInterface => $request->withHeader('X-Global', 'applied'),
        );

        $sent = null;
        Http::fake(function (HttpRequest $request) use (&$sent): PromiseInterface {
            $sent = $request->toPsrRequest();

            return Http::response();
        });

        (new TestConnector)->send(new UserRequest);

        $this->assertSame('applied', $sent->getHeaderLine('X-Global'));
    }

    // Saloon decides when a response has failed, so the HTTP client never throws for an error status.
    public function testTheGuzzleSenderHasDefaultOptionsConfigured(): void
    {
        $sentOptions = null;
        Http::fake(function (HttpRequest $request, array $options) use (&$sentOptions): PromiseInterface {
            $sentOptions = $options;

            return Http::response();
        });

        (new TestConnector)->send(new UserRequest);

        $this->assertSame(10, $sentOptions['connect_timeout']);
        $this->assertSame(30, $sentOptions['timeout']);
        $this->assertFalse($sentOptions['http_errors']);
        $this->assertSame(STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT, $sentOptions['crypto_method']);
    }

    // REMOVED: the custom handler stack case; see above.
}
