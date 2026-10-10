<?php

declare(strict_types=1);

namespace Hypervel\Tests\Http;

use Closure;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response as Psr7Response;
use GuzzleHttp\TransferStats;
use Hypervel\Events\Dispatcher;
use Hypervel\Http\Client\ConnectionException;
use Hypervel\Http\Client\Destinations\CurlCapabilities;
use Hypervel\Http\Client\Destinations\DestinationPolicyException;
use Hypervel\Http\Client\Destinations\DestinationResolutionException;
use Hypervel\Http\Client\Destinations\DisallowedDestinationException;
use Hypervel\Http\Client\Destinations\ProxyConnectionException;
use Hypervel\Http\Client\Destinations\PublicDestinationPolicy;
use Hypervel\Http\Client\Events\ConnectionFailed;
use Hypervel\Http\Client\Factory;
use Hypervel\Http\Client\PendingRequest;
use Hypervel\Tests\Http\Fixtures\FakeDestinationPolicy;
use Hypervel\Tests\Http\Fixtures\LoopbackHttpServer;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use Swoole\Coroutine\Socket;
use Throwable;

class HttpClientDestinationPolicyTest extends TestCase
{
    /**
     * The connection failures dispatched by the factory.
     *
     * @var list<ConnectionFailed>
     */
    private array $connectionFailures = [];

    public function testPinsTheRequestToTheVettedAddresses(): void
    {
        $server = LoopbackHttpServer::start();
        $policy = $this->loopbackPolicy('destination.invalid');

        $response = $this->factory()
            ->withDestinationPolicy($policy)
            ->get("http://destination.invalid:{$server->port}/probe");

        $this->assertSame('OK', $response->body());
        $this->assertStringContainsString("Host: destination.invalid:{$server->port}\r\n", (string) $server->request());
        $this->assertSame(["http://destination.invalid:{$server->port}/probe"], $policy->urls);
    }

    public function testRejectsAPrivateAddressLiteralBeforeConnecting(): void
    {
        $this->expectException(DisallowedDestinationException::class);
        $this->expectExceptionMessage('disallowed address [127.0.0.1]');

        $this->factory()
            ->withDestinationPolicy(new PublicDestinationPolicy)
            ->get('http://127.0.0.1:9/');
    }

    public function testPinsAHostWrittenWithATrailingDot(): void
    {
        $server = LoopbackHttpServer::start();
        $policy = $this->loopbackPolicy('destination.invalid');

        $response = $this->factory()
            ->withDestinationPolicy($policy)
            ->get("http://destination.invalid.:{$server->port}/probe");

        $this->assertSame('OK', $response->body());
        $this->assertStringContainsString("Host: destination.invalid.:{$server->port}\r\n", (string) $server->request());
    }

    public function testAPinnedRequestLeavesNoPinsForTheNextRequestOnItsConnection(): void
    {
        $server = LoopbackHttpServer::start([[], []]);
        $factory = $this->factory();
        $factory->registerConnection('api');

        $response = $factory->connection('api')
            ->withDestinationPolicy($this->loopbackPolicy('destination.invalid'))
            ->get("http://destination.invalid:{$server->port}/pinned");

        $this->assertSame('OK', $response->body());

        // The next request reuses the connection's handle and shared DNS cache, so it resolves the host itself and fails.
        $this->expectException(ConnectionException::class);

        $factory->connection('api')->get("http://destination.invalid:{$server->port}/unpinned");
    }

    public function testResolvesEveryRedirectAgain(): void
    {
        $second = LoopbackHttpServer::start();
        $first = LoopbackHttpServer::start([
            ['status' => 302, 'headers' => ['Location' => "http://second.invalid:{$second->port}/next"]],
        ]);
        $policy = $this->loopbackPolicy('first.invalid', 'second.invalid');

        $response = $this->factory()
            ->withDestinationPolicy($policy)
            ->get("http://first.invalid:{$first->port}/start");

        $this->assertSame('OK', $response->body());
        $this->assertSame([
            "http://first.invalid:{$first->port}/start",
            "http://second.invalid:{$second->port}/next",
        ], $policy->urls);
    }

    public function testPinsAnApprovedProxyWithoutResolvingTheTarget(): void
    {
        $proxy = LoopbackHttpServer::start();
        $policy = new FakeDestinationPolicy(
            ['proxy.invalid' => ['127.0.0.1']],
            allowedNetworks: ['127.0.0.0/8'],
            proxy: "http://proxy.invalid:{$proxy->port}",
        );

        $response = $this->factory()
            ->withDestinationPolicy($policy)
            ->get('http://target.invalid/probe');

        $this->assertSame('OK', $response->body());
        $this->assertStringStartsWith("GET http://target.invalid/probe HTTP/1.1\r\n", (string) $proxy->request());
        $this->assertSame(['proxy.invalid'], $policy->resolvedHosts);
    }

    /**
     * @param Closure(PendingRequest): PendingRequest $configure
     */
    #[DataProvider('unpinnableRequests')]
    public function testRejectsRequestsThePinsCannotCover(Closure $configure, string $message): void
    {
        $policy = $this->loopbackPolicy('destination.invalid');

        try {
            $configure($this->factory()->withDestinationPolicy($policy))->get('http://destination.invalid/');
            $this->fail('The request was not rejected.');
        } catch (DisallowedDestinationException $exception) {
            $this->assertSame($message, $exception->getMessage());
        }

        $this->assertSame([], $policy->urls);
    }

    /**
     * Provide requests whose transport could route around the destination pins.
     *
     * @return iterable<string, array{Closure(PendingRequest): PendingRequest, string}>
     */
    public static function unpinnableRequests(): iterable
    {
        yield 'streamed response' => [
            static fn (PendingRequest $request): PendingRequest => $request->withOptions(['stream' => true]),
            'Destination-restricted requests cannot stream responses; use [sink] instead.',
        ];
        yield 'custom handler' => [
            static fn (PendingRequest $request): PendingRequest => $request->setHandler(
                static fn () => Create::promiseFor(new Psr7Response),
            ),
            'Destination-restricted requests cannot use a custom handler.',
        ];
        yield 'caller proxy' => [
            static fn (PendingRequest $request): PendingRequest => $request->withOptions(['proxy' => 'http://proxy.example:8080']),
            'Destination-restricted requests cannot set the [proxy] option; select proxies in the destination policy.',
        ];
        yield 'raw cURL options' => [
            static fn (PendingRequest $request): PendingRequest => $request->withOptions(['curl' => [CURLOPT_TCP_KEEPALIVE => 1]]),
            'Destination-restricted requests cannot set raw [curl] options.',
        ];
    }

    public function testDeductsResolutionTimeFromTheRequestTimeout(): void
    {
        $server = LoopbackHttpServer::start([['delay' => 0.3]]);
        $policy = $this->loopbackPolicy('destination.invalid', resolutionSeconds: 0.3);

        // The response arrives within the configured timeout, but not within what resolution left of it.
        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('cURL error 28');

        $this->factory()
            ->withDestinationPolicy($policy)
            ->timeout(0.5)
            ->get("http://destination.invalid:{$server->port}/");
    }

    public function testBoundsResolutionByTheSmallestPositiveLimit(): void
    {
        $server = LoopbackHttpServer::start();
        $policy = $this->loopbackPolicy('destination.invalid');

        $this->factory()
            ->withDestinationPolicy($policy)
            ->timeout(1)
            ->connectTimeout(5)
            ->get("http://destination.invalid:{$server->port}/");

        $this->assertSame([1.0], $policy->timeouts);
    }

    public function testUnlimitedRequestsUseTheDefaultResolutionBudgetAndStayUnlimited(): void
    {
        $server = LoopbackHttpServer::start();
        $policy = $this->loopbackPolicy('destination.invalid', resolutionSeconds: 0.01);

        $response = $this->factory()
            ->withDestinationPolicy($policy)
            ->timeout(0)
            ->connectTimeout(0)
            ->get("http://destination.invalid:{$server->port}/");

        $this->assertSame('OK', $response->body());
        $this->assertSame([PendingRequest::DEFAULT_DESTINATION_RESOLUTION_TIMEOUT], $policy->timeouts);
    }

    public function testFailsTheRequestWhenResolutionUsesUpALimit(): void
    {
        $policy = $this->loopbackPolicy('destination.invalid', resolutionSeconds: 0.06);

        try {
            $this->factory()
                ->withDestinationPolicy($policy)
                ->timeout(0.05)
                ->get('http://destination.invalid/');
            $this->fail('The request was not failed.');
        } catch (DestinationResolutionException $exception) {
            $this->assertSame('The request timeout ran out while resolving the destination.', $exception->getMessage());
        }

        $this->assertCount(1, $this->connectionFailures);
        $this->assertSame($exception, $this->connectionFailures[0]->exception);
    }

    #[DataProvider('sendModes')]
    public function testAnUnresolvableProxyIsARetriedProxyConnectionFailure(bool $async): void
    {
        $statistics = [];
        $policy = new FakeDestinationPolicy(proxy: 'http://proxy.invalid:8080');
        $request = $this->factory()
            ->withDestinationPolicy($policy)
            ->withOptions(['on_stats' => static function (TransferStats $stats) use (&$statistics): void {
                $statistics[] = $stats;
            }])
            ->retry(2);

        $exception = $this->failure($request, 'http://target.invalid/', $async);

        $this->assertInstanceOf(ProxyConnectionException::class, $exception);
        $this->assertSame('The proxy [proxy.invalid] could not be resolved.', $exception->getMessage());
        $this->assertCount(2, $policy->urls);
        $this->assertCount(2, $this->connectionFailures);
        $this->assertSame($exception, $this->connectionFailures[1]->exception);
        $this->assertSame([], $statistics);
    }

    #[DataProvider('refusingProxies')]
    public function testAProxyRefusingConnectionsIsARetriedProxyConnectionFailure(bool $async, bool $ipv6): void
    {
        [$refusing, $port] = $this->refusingPort($ipv6);
        $errors = [];
        $policy = new FakeDestinationPolicy(
            ['proxy.invalid' => ['127.0.0.1']],
            allowedNetworks: ['127.0.0.0/8', '::1'],
            proxy: $ipv6 ? "http://[::1]:{$port}" : "http://proxy.invalid:{$port}",
        );
        $request = $this->factory()
            ->withDestinationPolicy($policy)
            ->withOptions(['on_stats' => static function (TransferStats $stats) use (&$errors): void {
                $errors[] = $stats->getHandlerErrorData();
            }])
            ->retry(2);

        try {
            $exception = $this->failure($request, 'http://target.invalid/', $async);
        } finally {
            $refusing->close();
        }

        $this->assertInstanceOf(ProxyConnectionException::class, $exception);
        $this->assertInstanceOf(ConnectException::class, $exception->getPrevious());
        $this->assertCount(2, $policy->urls);
        $this->assertCount(2, $this->connectionFailures);
        $this->assertSame($exception, $this->connectionFailures[1]->exception);
        $this->assertSame([CURLE_COULDNT_CONNECT, CURLE_COULDNT_CONNECT], $errors);
    }

    /**
     * Provide send modes for hostname and literal proxies.
     */
    public static function refusingProxies(): iterable
    {
        yield 'synchronous hostname' => [false, false];
        yield 'asynchronous hostname' => [true, false];
        yield 'synchronous IPv6 literal' => [false, true];
        yield 'asynchronous IPv6 literal' => [true, true];
    }

    public function testARefusedDirectConnectionStaysAPlainConnectionException(): void
    {
        [$refusing, $port] = $this->refusingPort();

        try {
            $this->factory()
                ->withDestinationPolicy($this->loopbackPolicy('destination.invalid'))
                ->get("http://destination.invalid:{$port}/");
            $this->fail('The refused connection was not reported.');
        } catch (ConnectionException $exception) {
            $this->assertSame(ConnectionException::class, $exception::class);
        } finally {
            $refusing->close();
        }

        $this->assertCount(1, $this->connectionFailures);
    }

    public function testFakedRequestsBypassThePolicy(): void
    {
        $factory = $this->factory();
        $factory->fake(['127.0.0.1/*' => Factory::response('faked')]);

        $response = $factory
            ->withDestinationPolicy(new PublicDestinationPolicy)
            ->get('http://127.0.0.1/status');

        $this->assertSame('faked', $response->body());
    }

    #[DataProvider('sendModes')]
    public function testDisallowedDestinationsAreNeverRetried(bool $async): void
    {
        $decisions = 0;
        $policy = new FakeDestinationPolicy(['internal.invalid' => ['10.0.0.1']]);
        $request = $this->factory()
            ->withDestinationPolicy($policy)
            ->retry(3, when: static function () use (&$decisions): bool {
                ++$decisions;

                return true;
            });

        $exception = $this->failure($request, 'http://internal.invalid/', $async);

        $this->assertInstanceOf(DisallowedDestinationException::class, $exception);
        $this->assertCount(1, $policy->urls);
        $this->assertSame(0, $decisions);
    }

    #[DataProvider('sendModes')]
    public function testMissingCurlCapabilitiesAreNeverRetried(bool $async): void
    {
        (new ReflectionProperty(CurlCapabilities::class, 'versionInfo'))->setValue(null, [
            'version' => '7.74.0',
            'features' => CURL_VERSION_SSL,
        ]);

        $attempts = 0;
        $decisions = 0;
        $request = $this->factory()
            ->withDestinationPolicy($this->loopbackPolicy('destination.invalid'))
            ->beforeSending(static function () use (&$attempts): void {
                ++$attempts;
            })
            ->retry(3, when: static function () use (&$decisions): bool {
                ++$decisions;

                return true;
            });

        $exception = $this->failure($request, 'http://destination.invalid/', $async);

        $this->assertInstanceOf(DestinationPolicyException::class, $exception);
        $this->assertSame(1, $attempts);
        $this->assertSame(0, $decisions);
    }

    /**
     * Provide the synchronous and asynchronous send paths.
     *
     * @return iterable<string, array{bool}>
     */
    public static function sendModes(): iterable
    {
        yield 'synchronous' => [false];
        yield 'asynchronous' => [true];
    }

    /**
     * Create an HTTP client factory that records connection failures.
     */
    private function factory(): Factory
    {
        $events = new Dispatcher;
        $events->listen(ConnectionFailed::class, function (ConnectionFailed $event): void {
            $this->connectionFailures[] = $event;
        });

        return new Factory($events);
    }

    /**
     * Create a policy that resolves the given hostnames to the loopback address.
     */
    private function loopbackPolicy(string $host, string $otherHost = 'other.invalid', float $resolutionSeconds = 0.0): FakeDestinationPolicy
    {
        return new FakeDestinationPolicy(
            [$host => ['127.0.0.1'], $otherHost => ['127.0.0.1']],
            allowedNetworks: ['127.0.0.0/8'],
            resolutionSeconds: $resolutionSeconds,
        );
    }

    /**
     * Send a request that is expected to fail and return its failure.
     */
    private function failure(PendingRequest $request, string $url, bool $async): Throwable
    {
        if ($async) {
            $result = $request->async()->get($url)->wait();
            $this->assertInstanceOf(Throwable::class, $result);

            return $result;
        }

        try {
            $request->get($url);
        } catch (Throwable $exception) {
            return $exception;
        }

        $this->fail('The request did not fail.');
    }

    /**
     * Reserve a loopback port that refuses connections.
     *
     * @return array{Socket, int}
     */
    private function refusingPort(bool $ipv6 = false): array
    {
        $socket = new Socket($ipv6 ? AF_INET6 : AF_INET, SOCK_STREAM, 0);
        $socket->bind($ipv6 ? '::1' : '127.0.0.1', 0);

        return [$socket, $socket->getsockname()['port']];
    }
}
