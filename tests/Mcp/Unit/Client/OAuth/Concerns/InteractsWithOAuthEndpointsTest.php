<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Unit\Client\OAuth\Concerns;

use GuzzleHttp\Psr7\DroppingStream;
use GuzzleHttp\Psr7\Utils;
use Hypervel\Http\Client\PendingRequest;
use Hypervel\Http\Client\Response;
use Hypervel\Mcp\Client\Exceptions\OAuthException;
use Hypervel\Mcp\Client\OAuth\AuthServerDiscovery;
use Hypervel\Mcp\Client\OAuth\DynamicClientRegistration;
use Hypervel\Mcp\Client\OAuth\OAuthDestinationPolicy;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Http\Fixtures\LoopbackHttpServer;
use Hypervel\Tests\Mcp\Fixtures\Client\OAuth\FakeDestinationPolicy;
use Hypervel\Tests\Mcp\TestCase;
use PHPUnit\Framework\Attributes\TestWith;
use Psr\Http\Message\UriInterface;
use Swoole\Coroutine\Socket;

class InteractsWithOAuthEndpointsTest extends TestCase
{
    /**
     * Resolve fake endpoints without external DNS.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(OAuthDestinationPolicy::class, new FakeDestinationPolicy);
    }

    // REMOVED: raw cURL option assertions. Protocols are transport-owned; response limits use capped sinks.
    public function testLimitsDiscoveryRequestsToHttpAndHttpsWithACappedResponseSize(): void
    {
        $options = [];

        Http::fake(function ($request, array $requestOptions) use (&$options): void {
            $options[] = $requestOptions['sink'] ?? null;
        });

        Http::fake([
            'https://mcp.test/.well-known/oauth-protected-resource/mcp' => Http::response([
                'authorization_servers' => ['https://auth.test'],
            ]),
            'https://auth.test/.well-known/oauth-authorization-server' => Http::response([
                'issuer' => 'https://auth.test',
                'authorization_endpoint' => 'https://auth.test/authorize',
                'token_endpoint' => 'https://auth.test/token',
            ]),
        ]);

        (new AuthServerDiscovery)->discover('https://mcp.test/mcp');

        $this->assertCount(2, $options);
        $this->assertNotSame($options[0], $options[1]);

        foreach ($options as $sink) {
            $this->assertInstanceOf(DroppingStream::class, $sink);
            $sink->seek(0, SEEK_END);
            $remaining = 1_048_576 - $sink->getSize();
            $this->assertSame($remaining, $sink->write(str_repeat('x', 1_048_577)));
            $this->assertSame(0, $sink->write('x'));
        }
    }

    public function testLimitsRegistrationRequestsToHttpAndHttps(): void
    {
        $options = null;

        Http::fake(function ($request, array $requestOptions) use (&$options): void {
            $options = $requestOptions['sink'] ?? null;
        });

        Http::fake(['https://auth.test/register' => Http::response(['client_id' => 'client-1'])]);

        (new DynamicClientRegistration)->register('https://auth.test/register', 'https://app.test/callback');

        $this->assertInstanceOf(DroppingStream::class, $options);
    }

    #[TestWith([false], 'direct')]
    #[TestWith([true], 'configured proxy')]
    public function testRedirectedProbesKeepTheFinalBodyAndRejectOversizedUnknownLengthResponses(bool $proxy): void
    {
        $this->app->instance(OAuthDestinationPolicy::class, new FakeDestinationPolicy(['localhost' => ['127.0.0.1']]));
        $metadataUrl = 'https://metadata.test/resource';
        $server = LoopbackHttpServer::start([
            ['status' => 307, 'headers' => ['Location' => '/final'], 'body' => '<html>Redirecting to the resource...</html>'],
            ['status' => 401, 'headers' => ['WWW-Authenticate' => 'Bearer resource_metadata="' . $metadataUrl . '"'], 'body' => '{"error":"unauthorized"}'],
            static function (Socket $socket): void {
                $body = str_repeat('x', 1_048_577);
                $socket->sendAll("HTTP/1.1 401 Unauthorized\r\nTransfer-Encoding: chunked\r\nConnection: close\r\n\r\n"
                    . dechex(strlen($body)) . "\r\n" . $body . "\r\n0\r\n\r\n");
            },
        ]);
        $resourceUrl = 'http://localhost:' . ($proxy ? 9 : $server->port);
        if ($proxy) {
            Http::globalOptions(['proxy' => 'http://127.0.0.1:' . $server->port]);
        }
        $discovery = new class extends AuthServerDiscovery {
            public ?Response $response = null;

            /**
             * Retain the normal response exposed to discovery subclasses.
             */
            protected function probe(string $resourceUrl, array $headers): Response
            {
                return $this->response = parent::probe($resourceUrl, $headers);
            }
        };

        try {
            $discovery->requireAdvertised($resourceUrl, $metadataUrl);
            $this->assertSame('{"error":"unauthorized"}', $discovery->response->body());
            $this->assertStringStartsWith('POST ' . ($proxy ? $resourceUrl : '') . '/ HTTP/1.1', $server->request());
            $this->assertStringStartsWith('POST ' . ($proxy ? $resourceUrl : '') . '/final HTTP/1.1', $server->request());

            $this->expectException(OAuthException::class);
            $this->expectExceptionMessage('Unable to reach the resource');
            $discovery->requireAdvertised($resourceUrl . '/oversized', $metadataUrl);
        } finally {
            $discovery->response?->close();
        }
    }

    public function testExplicitCallerSinkIsPreserved(): void
    {
        Http::fake(['https://auth.test/token' => Http::response('custom body')]);
        $discovery = new class extends AuthServerDiscovery {
            /**
             * Expose the request builder to customize its sink.
             */
            public function request(): PendingRequest
            {
                return $this->oAuthRequest();
            }
        };
        $sink = Utils::streamFor('');

        try {
            $discovery->request()->sink($sink)->get('https://auth.test/token');
            $this->assertSame('custom body', (string) $sink);
        } finally {
            $sink->close();
        }
    }

    #[TestWith(['https://other.test/mcp'], 'different host')]
    #[TestWith(['https://mcp.test:8443/mcp'], 'different port')]
    public function testResourceHeadersStayOnTheirOriginDuringProbeRedirects(string $redirectUrl): void
    {
        $requests = [];
        Http::fake(function ($request) use (&$requests): void {
            $requests[] = $request;
        });
        Http::fake([
            'https://mcp.test/mcp' => Http::response('', 307, ['Location' => 'https://mcp.test:443/next']),
            'https://mcp.test/next' => Http::response('', 307, ['Location' => $redirectUrl]),
            $redirectUrl => Http::response('', 401, [
                'WWW-Authenticate' => 'Bearer resource_metadata="https://metadata.test/resource"',
            ]),
        ]);

        (new AuthServerDiscovery)->requireAdvertised(
            'https://mcp.test/mcp',
            'https://metadata.test/resource',
            ['X-Api-Key' => 'resource-secret'],
        );

        $this->assertCount(3, $requests);
        $this->assertTrue($requests[0]->hasHeader('X-Api-Key', 'resource-secret'));
        $this->assertTrue($requests[1]->hasHeader('X-Api-Key', 'resource-secret'));
        $this->assertFalse($requests[2]->hasHeader('X-Api-Key'));
    }

    #[TestWith([false], 'environment proxy')]
    #[TestWith([true], 'policy proxy')]
    public function testProbesPreserveHttpsProxiesAndPinsOnEachHop(bool $policyProxy): void
    {
        $this->app->instance(OAuthDestinationPolicy::class, new class($policyProxy) extends FakeDestinationPolicy {
            /**
             * Select whether the policy owns the proxy route.
             */
            public function __construct(private bool $policyProxy)
            {
                parent::__construct();
            }

            /**
             * Select a trusted HTTPS proxy when configured by the policy.
             */
            protected function proxyFor(UriInterface $uri): ?string
            {
                return $this->policyProxy ? 'https://policy-proxy.test:8443' : null;
            }
        });
        $requests = [];
        Http::fake(function ($request, array $options) use (&$requests): void {
            $requests[] = [$request->url(), $options];
        });
        Http::fake([
            'https://mcp.test/mcp' => Http::response('', 307, ['Location' => 'https://next.test/mcp']),
            'https://next.test/mcp' => Http::response('', 401, [
                'WWW-Authenticate' => 'Bearer resource_metadata="https://metadata.test/resource"',
            ]),
        ]);

        $this->withEnvironmentValues(
            ['HTTPS_PROXY' => 'https://environment-proxy.test:8443', 'NO_PROXY' => ''],
            fn () => (new AuthServerDiscovery)->requireAdvertised('https://mcp.test/mcp', 'https://metadata.test/resource'),
        );

        $this->assertCount(2, $requests);
        foreach ($requests as [$url, $options]) {
            $host = parse_url($url, PHP_URL_HOST);
            $this->assertSame(
                [$policyProxy ? 'policy-proxy.test:8443:93.184.216.34' : $host . ':443:93.184.216.34'],
                $options['curl'][CURLOPT_RESOLVE],
            );
            $this->assertSame(
                $policyProxy ? 'https://policy-proxy.test:8443' : 'https://environment-proxy.test:8443',
                $policyProxy ? $options['proxy'] : $options['proxy']['https'],
            );
        }
    }
}
