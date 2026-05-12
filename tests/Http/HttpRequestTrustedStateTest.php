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
    public function testGetClientIpsWithoutTrustedProxiesReturnsRemoteAddr()
    {
        $request = Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => '1.2.3.4']);

        $this->assertSame(['1.2.3.4'], $request->getClientIps());
    }

    public function testGetClientIpsWithTrustedProxyHonorsXForwardedFor()
    {
        $request = $this->trustedRequest(
            ['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_FOR' => '9.9.9.9'],
            ['10.0.0.1'],
            Request::HEADER_X_FORWARDED_FOR
        );

        $this->assertSame(['9.9.9.9'], $request->getClientIps());
        $this->assertSame('9.9.9.9', $request->getClientIp());
    }

    public function testGetClientIpsIgnoresXForwardedForFromUntrustedProxy()
    {
        $request = $this->trustedRequest(
            ['REMOTE_ADDR' => '20.0.0.1', 'HTTP_X_FORWARDED_FOR' => '9.9.9.9'],
            ['10.0.0.1'],
            Request::HEADER_X_FORWARDED_FOR
        );

        $this->assertSame(['20.0.0.1'], $request->getClientIps());
    }

    public function testIsFromTrustedProxyHandlesCidrRanges()
    {
        $request = $this->trustedRequest(
            ['REMOTE_ADDR' => '10.0.0.55'],
            ['10.0.0.0/24'],
            Request::HEADER_X_FORWARDED_FOR
        );

        $this->assertTrue($request->isFromTrustedProxy());
    }

    public function testGetHostWithoutTrustReadsHttpHost()
    {
        $request = Request::create('http://example.com/');

        $this->assertSame('example.com', $request->getHost());
    }

    public function testGetHostWithTrustedProxyHonorsXForwardedHost()
    {
        $request = $this->trustedRequest(
            ['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_HOST' => 'real.com'],
            ['10.0.0.1'],
            Request::HEADER_X_FORWARDED_HOST
        );

        $this->assertSame('real.com', $request->getHost());
    }

    public function testGetHostThrowsOnUntrustedHostWhenPatternsConfigured()
    {
        $request = Request::create('http://evil.com/');
        RequestContext::set($request);
        Request::setTrustedHosts(['^example\.com$']);

        $this->expectException(SuspiciousOperationException::class);

        $request->getHost();
    }

    public function testGetHostThrowsOnlyOncePerRequest()
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

    public function testGetHostHonorsValidWildcardPattern()
    {
        $request = Request::create('http://api.example.com/');
        RequestContext::set($request);
        Request::setTrustedHosts(['^.+\.example\.com$']);

        $this->assertSame('api.example.com', $request->getHost());
    }

    public function testIsSecureFromTrustedProxyXForwardedProto()
    {
        $request = $this->trustedRequest(
            ['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_PROTO' => 'https'],
            ['10.0.0.1'],
            Request::HEADER_X_FORWARDED_PROTO
        );

        $this->assertTrue($request->isSecure());
    }

    public function testIsSecureIgnoresXForwardedProtoFromUntrustedProxy()
    {
        $request = $this->trustedRequest(
            ['REMOTE_ADDR' => '20.0.0.1', 'HTTP_X_FORWARDED_PROTO' => 'https'],
            ['10.0.0.1'],
            Request::HEADER_X_FORWARDED_PROTO
        );

        $this->assertFalse($request->isSecure());
    }

    public function testGetPortFromXForwardedPort()
    {
        $request = $this->trustedRequest(
            ['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_PORT' => '8443'],
            ['10.0.0.1'],
            Request::HEADER_X_FORWARDED_PORT
        );

        $this->assertSame(8443, $request->getPort());
    }

    public function testGetPortFromXForwardedHostWithPort()
    {
        $request = $this->trustedRequest(
            ['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_HOST' => 'real.com:8080'],
            ['10.0.0.1'],
            Request::HEADER_X_FORWARDED_HOST
        );

        $this->assertSame(8080, $request->getPort());
    }

    public function testGetBaseUrlIncludesXForwardedPrefix()
    {
        $request = $this->trustedRequest(
            ['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_PREFIX' => '/app'],
            ['10.0.0.1'],
            Request::HEADER_X_FORWARDED_PREFIX,
            '/users'
        );

        $this->assertSame('/app', $request->getBaseUrl());
    }

    public function testGetBaseUrlIgnoresXForwardedPrefixFromUntrustedProxy()
    {
        $request = $this->trustedRequest(
            ['REMOTE_ADDR' => '20.0.0.1', 'HTTP_X_FORWARDED_PREFIX' => '/app'],
            ['10.0.0.1'],
            Request::HEADER_X_FORWARDED_PREFIX,
            '/users'
        );

        $this->assertSame('', $request->getBaseUrl());
    }

    public function testConflictingForwardedHeaderThrowsOnce()
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

    public function testSetTrustedProxiesResolvesRemoteAddrSentinel()
    {
        $request = Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => '5.5.5.5']);
        RequestContext::set($request);

        Request::setTrustedProxies(['REMOTE_ADDR'], Request::HEADER_X_FORWARDED_FOR);

        $this->assertSame(['5.5.5.5'], Request::getTrustedProxies());
    }

    public function testSetTrustedProxiesExpandsPrivateSubnetsSentinel()
    {
        $request = Request::create('/');
        RequestContext::set($request);

        Request::setTrustedProxies(['PRIVATE_SUBNETS'], Request::HEADER_X_FORWARDED_FOR);

        $this->assertSame(IpUtils::PRIVATE_SUBNETS, Request::getTrustedProxies());
    }

    public function testSetTrustedHostsCompilesRegexPatterns()
    {
        $request = Request::create('/');
        RequestContext::set($request);

        Request::setTrustedHosts(['^example\.com$']);

        $this->assertSame(['{^example\.com$}i'], Request::getTrustedHosts());
    }

    public function testCreateFromPreservesTrustedRequestConfiguration()
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

    public function testCreateFromBasePreservesTrustedRequestConfigurationForHypervelRequests()
    {
        $source = $this->trustedRequest(
            ['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_FOR' => '9.9.9.9'],
            ['10.0.0.1'],
            Request::HEADER_X_FORWARDED_FOR
        );

        $copy = Request::createFromBase($source);

        $this->assertSame('9.9.9.9', $copy->ip());
    }

    public function testInitializeResetsTrustedRequestState()
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

    public function testClonePreservesConfigurationButResetsOneShotFlags()
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

    public function testDuplicatePreservesConfigurationThroughCloneLifecycle()
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

    public function testSetTrustedProxiesClearsTrustedValuesCache()
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

    public function testSetTrustedHostsClearsTrustedHostCacheAndFlags()
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

    public function testStaticSettersAreNoOpWithoutCurrentRequest()
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

    public function testStaticGettersReturnDefaultsWithoutCurrentRequest()
    {
        RequestContext::forget();

        $this->assertSame([], Request::getTrustedProxies());
        $this->assertSame(-1, Request::getTrustedHeaderSet());
        $this->assertSame([], Request::getTrustedHosts());
    }

    public function testSingleRequestMatchesSymfonyTrustedProxyBehavior()
    {
        $server = [
            'REMOTE_ADDR' => '10.0.0.1',
            'HTTP_X_FORWARDED_FOR' => '9.9.9.9, 10.0.0.2',
            'HTTP_X_FORWARDED_HOST' => 'api.example.com',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_PORT' => '8443',
        ];
        $headers = Request::HEADER_X_FORWARDED_FOR
            | Request::HEADER_X_FORWARDED_HOST
            | Request::HEADER_X_FORWARDED_PROTO
            | Request::HEADER_X_FORWARDED_PORT;

        try {
            $symfony = SymfonyRequest::create('http://internal.test/users', 'GET', [], [], [], $server);
            SymfonyRequest::setTrustedProxies(['10.0.0.1', '10.0.0.2'], $headers);

            $hypervel = $this->trustedRequest($server, ['10.0.0.1', '10.0.0.2'], $headers, '/users');

            $this->assertSame($symfony->getClientIps(), $hypervel->getClientIps());
            $this->assertSame($symfony->getHost(), $hypervel->getHost());
            $this->assertSame($symfony->getPort(), $hypervel->getPort());
            $this->assertSame($symfony->isSecure(), $hypervel->isSecure());
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
