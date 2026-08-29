<?php

declare(strict_types=1);

namespace Hypervel\Tests\Http;

use Hypervel\Context\RequestContext;
use Hypervel\Http\Request;
use Hypervel\Tests\TestCase;
use ReflectionProperty;
use Symfony\Component\HttpFoundation\Exception\ConflictingHeadersException;
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

class HttpRequestTrustedStateTest extends TestCase
{
    public function testGetClientIpsWithoutTrustedProxiesReturnsRemoteAddr(): void
    {
        $request = Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => '1.2.3.4']);

        $this->assertSame(['1.2.3.4'], $request->getClientIps());
    }

    public function testGetClientIpsWithTrustedProxyHonorsXForwardedFor(): void
    {
        $request = $this->trustedRequest(
            ['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_FOR' => '9.9.9.9'],
            ['10.0.0.1'],
            Request::HEADER_X_FORWARDED_FOR
        );

        $this->assertSame(['9.9.9.9'], $request->getClientIps());
        $this->assertSame('9.9.9.9', $request->getClientIp());
    }

    public function testGetClientIpsIgnoresXForwardedForFromUntrustedProxy(): void
    {
        $request = $this->trustedRequest(
            ['REMOTE_ADDR' => '20.0.0.1', 'HTTP_X_FORWARDED_FOR' => '9.9.9.9'],
            ['10.0.0.1'],
            Request::HEADER_X_FORWARDED_FOR
        );

        $this->assertSame(['20.0.0.1'], $request->getClientIps());
    }

    public function testIsFromTrustedProxyHandlesCidrRanges(): void
    {
        $request = $this->trustedRequest(
            ['REMOTE_ADDR' => '10.0.0.55'],
            ['10.0.0.0/24'],
            Request::HEADER_X_FORWARDED_FOR
        );

        $this->assertTrue($request->isFromTrustedProxy());
    }

    public function testGetHostWithoutTrustReadsHttpHost(): void
    {
        $request = Request::create('http://example.com/');

        $this->assertSame('example.com', $request->getHost());
    }

    public function testGetHostWithTrustedProxyHonorsXForwardedHost(): void
    {
        $request = $this->trustedRequest(
            ['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_HOST' => 'real.com'],
            ['10.0.0.1'],
            Request::HEADER_X_FORWARDED_HOST
        );

        $this->assertSame('real.com', $request->getHost());
    }

    public function testGetHostThrowsOnUntrustedHostWhenPatternsConfigured(): void
    {
        $request = Request::create('http://evil.com/');
        RequestContext::set($request);
        Request::setTrustedHosts(['^example\.com$']);

        $this->expectException(SuspiciousOperationException::class);

        $request->getHost();
    }

    public function testGetHostThrowsOnlyOncePerRequest(): void
    {
        $request = Request::create('http://evil.com/');
        RequestContext::set($request);
        Request::setTrustedHosts(['^example\.com$']);

        try {
            $request->getHost();
            $this->fail('Expected first host read to throw.');
        } catch (SuspiciousOperationException) {
            $this->assertSame('', $request->getHost());
        }
    }

    public function testGetHostHonorsValidWildcardPattern(): void
    {
        $request = Request::create('http://api.example.com/');
        RequestContext::set($request);
        Request::setTrustedHosts(['^.+\.example\.com$']);

        $this->assertSame('api.example.com', $request->getHost());
    }

    public function testIsSecureFromTrustedProxyXForwardedProto(): void
    {
        $request = $this->trustedRequest(
            ['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_PROTO' => 'https'],
            ['10.0.0.1'],
            Request::HEADER_X_FORWARDED_PROTO
        );

        $this->assertTrue($request->isSecure());
    }

    public function testIsSecureIgnoresXForwardedProtoFromUntrustedProxy(): void
    {
        $request = $this->trustedRequest(
            ['REMOTE_ADDR' => '20.0.0.1', 'HTTP_X_FORWARDED_PROTO' => 'https'],
            ['10.0.0.1'],
            Request::HEADER_X_FORWARDED_PROTO
        );

        $this->assertFalse($request->isSecure());
    }

    public function testGetPortFromXForwardedPort(): void
    {
        $request = $this->trustedRequest(
            ['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_PORT' => '8443'],
            ['10.0.0.1'],
            Request::HEADER_X_FORWARDED_PORT
        );

        $this->assertSame(8443, $request->getPort());
    }

    public function testGetPortFromXForwardedHostWithPort(): void
    {
        $request = $this->trustedRequest(
            ['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_HOST' => 'real.com:8080'],
            ['10.0.0.1'],
            Request::HEADER_X_FORWARDED_HOST
        );

        $this->assertSame(8080, $request->getPort());
    }

    public function testGetBaseUrlIncludesXForwardedPrefix(): void
    {
        $request = $this->trustedRequest(
            ['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_PREFIX' => '/app'],
            ['10.0.0.1'],
            Request::HEADER_X_FORWARDED_PREFIX,
            '/users'
        );

        $this->assertSame('/app', $request->getBaseUrl());
    }

    public function testGetBaseUrlIgnoresXForwardedPrefixFromUntrustedProxy(): void
    {
        $request = $this->trustedRequest(
            ['REMOTE_ADDR' => '20.0.0.1', 'HTTP_X_FORWARDED_PREFIX' => '/app'],
            ['10.0.0.1'],
            Request::HEADER_X_FORWARDED_PREFIX,
            '/users'
        );

        $this->assertSame('', $request->getBaseUrl());
    }

    public function testConflictingForwardedHeaderThrowsOnce(): void
    {
        $request = $this->trustedRequest(
            [
                'REMOTE_ADDR' => '10.0.0.1',
                'HTTP_FORWARDED' => 'for=8.8.8.8',
                'HTTP_X_FORWARDED_FOR' => '9.9.9.9',
            ],
            ['10.0.0.1'],
            Request::HEADER_FORWARDED | Request::HEADER_X_FORWARDED_FOR
        );

        try {
            $request->getClientIps();
            $this->fail('Expected conflicting forwarded headers to throw.');
        } catch (ConflictingHeadersException) {
            $this->assertSame(['0.0.0.0', '10.0.0.1'], $request->getClientIps());
        }
    }

    public function testSetTrustedProxiesResolvesRemoteAddrSentinel(): void
    {
        $request = Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => '5.5.5.5']);
        RequestContext::set($request);

        Request::setTrustedProxies(['REMOTE_ADDR'], Request::HEADER_X_FORWARDED_FOR);

        $this->assertSame(['5.5.5.5'], Request::getTrustedProxies());
    }

    public function testSetTrustedProxiesExpandsPrivateSubnetsSentinel(): void
    {
        $request = Request::create('/');
        RequestContext::set($request);

        Request::setTrustedProxies(['PRIVATE_SUBNETS'], Request::HEADER_X_FORWARDED_FOR);

        $this->assertSame(IpUtils::PRIVATE_SUBNETS, Request::getTrustedProxies());
    }

    public function testSetTrustedHostsCompilesRegexPatterns(): void
    {
        $request = Request::create('/');
        RequestContext::set($request);

        Request::setTrustedHosts(['^example\.com$']);

        $this->assertSame(['{^example\.com$}i'], Request::getTrustedHosts());
    }

    public function testCreateFromPreservesTrustedRequestConfiguration(): void
    {
        $source = $this->trustedRequest(
            [
                'REMOTE_ADDR' => '10.0.0.1',
                'HTTP_X_FORWARDED_FOR' => '9.9.9.9',
                'HTTP_X_FORWARDED_HOST' => 'api.example.com',
                'HTTP_X_FORWARDED_PREFIX' => '/app',
            ],
            ['10.0.0.1'],
            Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_HOST | Request::HEADER_X_FORWARDED_PREFIX,
            '/users'
        );
        Request::setTrustedHosts(['^api\.example\.com$']);

        $copy = Request::createFrom($source);

        $this->assertSame('9.9.9.9', $copy->ip());
        $this->assertSame('api.example.com', $copy->host());
        $this->assertSame('/app', $copy->getBaseUrl());
    }

    public function testCreateFromBasePreservesTrustedRequestConfigurationForHypervelRequests(): void
    {
        $source = $this->trustedRequest(
            ['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_FOR' => '9.9.9.9'],
            ['10.0.0.1'],
            Request::HEADER_X_FORWARDED_FOR
        );

        $copy = Request::createFromBase($source);

        $this->assertSame('9.9.9.9', $copy->ip());
    }

    public function testInitializeResetsTrustedRequestState(): void
    {
        $request = $this->trustedRequest(
            ['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_FOR' => '9.9.9.9'],
            ['10.0.0.1'],
            Request::HEADER_X_FORWARDED_FOR
        );

        $request->initialize(server: ['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_FOR' => '8.8.8.8']);

        $this->assertSame([], Request::getTrustedProxies());
        $this->assertSame(-1, Request::getTrustedHeaderSet());
        $this->assertSame(['10.0.0.1'], $request->getClientIps());
    }

    public function testClonePreservesConfigurationButResetsOneShotFlags(): void
    {
        $request = Request::create('http://evil.com/');
        RequestContext::set($request);
        Request::setTrustedHosts(['^example\.com$']);

        try {
            $request->getHost();
            $this->fail('Expected original request host read to throw.');
        } catch (SuspiciousOperationException) {
            $clone = clone $request;
        }

        $this->expectException(SuspiciousOperationException::class);

        $clone->getHost();
    }

    public function testDuplicatePreservesConfigurationThroughCloneLifecycle(): void
    {
        $request = $this->trustedRequest(
            ['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_FOR' => '9.9.9.9, 10.0.0.2'],
            ['10.0.0.1', '10.0.0.2'],
            Request::HEADER_X_FORWARDED_FOR
        );
        $this->assertSame(['9.9.9.9'], $request->getClientIps());

        $this->assertNotSame([], $this->trustedValuesCache($request));

        $duplicate = $request->duplicate();

        $this->assertSame([], $this->trustedValuesCache($duplicate));
        $this->assertSame(['9.9.9.9'], $duplicate->getClientIps());
    }

    public function testDuplicateRecomputesTrustedValuesForNewRequestState(): void
    {
        $request = $this->trustedRequest(
            [
                'REMOTE_ADDR' => '10.0.0.1',
                'HTTP_FORWARDED' => 'host=example.com',
                'HTTPS' => 'on',
            ],
            ['10.0.0.1'],
            Request::HEADER_FORWARDED
        );

        $this->assertSame(443, $request->getPort());

        $duplicate = $request->duplicate(server: [
            'REMOTE_ADDR' => '10.0.0.1',
            'HTTP_FORWARDED' => 'host=example.com',
        ]);

        $this->assertSame(80, $duplicate->getPort());
    }

    public function testSetTrustedProxiesClearsTrustedValuesCache(): void
    {
        $request = $this->trustedRequest(
            ['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_FOR' => '9.9.9.9, 10.0.0.2'],
            ['10.0.0.1', '10.0.0.2'],
            Request::HEADER_X_FORWARDED_FOR
        );
        $this->assertSame(['9.9.9.9'], $request->getClientIps());

        Request::setTrustedProxies(['10.0.0.1'], Request::HEADER_X_FORWARDED_FOR);

        $this->assertSame(['10.0.0.2', '9.9.9.9'], $request->getClientIps());
    }

    public function testSetTrustedHostsClearsTrustedHostCacheAndFlags(): void
    {
        $request = Request::create('http://evil.com/');
        RequestContext::set($request);
        Request::setTrustedHosts(['^example\.com$']);

        try {
            $request->getHost();
            $this->fail('Expected first host read to throw.');
        } catch (SuspiciousOperationException) {
            Request::setTrustedHosts(['^evil\.com$']);
        }

        $this->assertSame('evil.com', $request->getHost());
    }

    public function testStaticSettersAreNoOpWithoutCurrentRequest(): void
    {
        RequestContext::forget();
        Request::setTrustedProxies(['10.0.0.1'], Request::HEADER_X_FORWARDED_FOR);
        Request::setTrustedHosts(['^example\.com$']);

        $request = Request::create('http://evil.com/', 'GET', [], [], [], [
            'REMOTE_ADDR' => '10.0.0.1',
            'HTTP_X_FORWARDED_FOR' => '9.9.9.9',
        ]);

        $this->assertSame(['10.0.0.1'], $request->getClientIps());
        $this->assertSame('evil.com', $request->getHost());
    }

    public function testStaticGettersReturnDefaultsWithoutCurrentRequest(): void
    {
        RequestContext::forget();

        $this->assertSame([], Request::getTrustedProxies());
        $this->assertSame(-1, Request::getTrustedHeaderSet());
        $this->assertSame([], Request::getTrustedHosts());
    }

    public function testSingleRequestMatchesSymfonyTrustedProxyBehavior(): void
    {
        $server = [
            'REMOTE_ADDR' => '10.0.0.1',
            'HTTP_X_FORWARDED_FOR' => '9.9.9.9, 10.0.0.2',
            'HTTP_X_FORWARDED_HOST' => 'api.example.com',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_PORT' => '8443',
            'HTTP_X_FORWARDED_PREFIX' => '/app',
        ];
        $headers = Request::HEADER_X_FORWARDED_FOR
            | Request::HEADER_X_FORWARDED_HOST
            | Request::HEADER_X_FORWARDED_PROTO
            | Request::HEADER_X_FORWARDED_PORT
            | Request::HEADER_X_FORWARDED_PREFIX;

        try {
            $symfony = SymfonyRequest::create('http://internal.test/users', 'GET', [], [], [], $server);
            SymfonyRequest::setTrustedProxies(['10.0.0.1', '10.0.0.2'], $headers);

            $hypervel = $this->trustedRequest($server, ['10.0.0.1', '10.0.0.2'], $headers, '/users');

            $this->assertSame($symfony->getClientIps(), $hypervel->getClientIps());
            $this->assertSame($symfony->getHost(), $hypervel->getHost());
            $this->assertSame($symfony->getPort(), $hypervel->getPort());
            $this->assertSame($symfony->isSecure(), $hypervel->isSecure());
            $this->assertSame($symfony->getBaseUrl(), $hypervel->getBaseUrl());
            $this->assertSame($symfony->isFromTrustedProxy(), $hypervel->isFromTrustedProxy());
        } finally {
            SymfonyRequest::setTrustedProxies([], -1);
        }
    }

    private function trustedRequest(array $server, array $proxies, int $headers, string $uri = '/'): Request
    {
        $request = Request::create($uri, 'GET', [], [], [], $server);
        RequestContext::set($request);
        Request::setTrustedProxies($proxies, $headers);

        return $request;
    }

    private function trustedValuesCache(Request $request): array
    {
        return (new ReflectionProperty(Request::class, 'trustedValuesCacheValue'))->getValue($request);
    }
}
