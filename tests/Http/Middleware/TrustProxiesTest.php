<?php

declare(strict_types=1);

namespace Hypervel\Tests\Http\Middleware;

use Closure;
use Hypervel\Context\RequestContext;
use Hypervel\Http\Middleware\TrustProxies;
use Hypervel\Http\Request;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

class TrustProxiesTest extends TestCase
{
    /**
     * A list of all proxy headers.
     */
    protected int $headerAll = Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_HOST | Request::HEADER_X_FORWARDED_PORT | Request::HEADER_X_FORWARDED_PROTO | Request::HEADER_X_FORWARDED_PREFIX | Request::HEADER_X_FORWARDED_AWS_ELB;

    /**
     * Test that Symfony does indeed NOT trust X-Forwarded-*
     * headers when not given trusted proxies.
     *
     * This re-tests Symfony's Request class, but hopefully provides
     * some clarify to developers looking at the tests.
     */
    public function testRequestDoesNotTrust(): void
    {
        $req = $this->createProxiedRequest();

        $this->assertSame('192.168.10.10', $req->getClientIp(), 'Assert untrusted proxy x-forwarded-for header not used');
        $this->assertSame('http', $req->getScheme(), 'Assert untrusted proxy x-forwarded-proto header not used');
        $this->assertSame('localhost', $req->getHost(), 'Assert untrusted proxy x-forwarded-host header not used');
        $this->assertSame(8888, $req->getPort(), 'Assert untrusted proxy x-forwarded-port header not used');
        $this->assertSame('', $req->getBaseUrl(), 'Assert untrusted proxy x-forwarded-prefix header not used');
    }

    /**
     * Test that Symfony DOES indeed trust X-Forwarded-*
     * headers when given trusted proxies.
     *
     * Again, this re-tests Symfony's Request class.
     */
    public function testDoesTrustTrustedProxy(): void
    {
        $req = $this->createProxiedRequest();
        $req::setTrustedProxies(['192.168.10.10'], $this->headerAll);

        $this->assertSame('173.174.200.38', $req->getClientIp(), 'Assert trusted proxy x-forwarded-for header used');
        $this->assertSame('https', $req->getScheme(), 'Assert trusted proxy x-forwarded-proto header used');
        $this->assertSame('serversforhackers.com', $req->getHost(), 'Assert trusted proxy x-forwarded-host header used');
        $this->assertSame(443, $req->getPort(), 'Assert trusted proxy x-forwarded-port header used');
        $this->assertSame('/prefix', $req->getBaseUrl(), 'Assert trusted proxy x-forwarded-prefix header used');
    }

    /**
     * Test the next most typical usage of TrustedProxies:
     * Trusted X-Forwarded-For header, wildcard for TrustedProxies.
     */
    public function testTrustedProxySetsTrustedProxiesWithWildcard(): void
    {
        $trustedProxy = $this->createTrustedProxy($this->headerAll, '*');
        $request = $this->createProxiedRequest();

        $this->assertThroughMiddleware($trustedProxy, $request, function ($request) {
            $this->assertSame('173.174.200.38', $request->getClientIp(), 'Assert trusted proxy x-forwarded-for header used with wildcard proxy setting');
        });
    }

    /**
     * Test the next most typical usage of TrustedProxies:
     * Trusted X-Forwarded-For header, wildcard for TrustedProxies.
     */
    public function testTrustedProxySetsTrustedProxiesWithDoubleWildcardForBackwardsCompat(): void
    {
        $trustedProxy = $this->createTrustedProxy($this->headerAll, '**');
        $request = $this->createProxiedRequest();

        $this->assertThroughMiddleware($trustedProxy, $request, function ($request) {
            $this->assertSame('173.174.200.38', $request->getClientIp(), 'Assert trusted proxy x-forwarded-for header used with wildcard proxy setting');
        });
    }

    /**
     * Test that the wildcard trusts proxies in an IPv6 forwarded chain, so the
     * left-most client IP is returned rather than an intermediate IPv6 proxy.
     */
    public function testTrustedProxySetsTrustedProxiesWithWildcardAndIpv6Chain(): void
    {
        $trustedProxy = $this->createTrustedProxy($this->headerAll, '*');
        $request = $this->createProxiedRequest([
            'REMOTE_ADDR' => '2001:db8::1',
            'HTTP_X_FORWARDED_FOR' => '173.174.200.38, 2001:db8::2, 2001:db8::3',
        ]);

        $this->assertThroughMiddleware($trustedProxy, $request, function ($request) {
            $this->assertSame(
                '173.174.200.38',
                $request->getClientIp(),
                'Assert IPv6 proxies in the forwarded chain are trusted with wildcard proxy setting'
            );
        });
    }

    /**
     * Test the next most typical usage of TrustedProxies:
     * Trusted X-Forwarded-For header, REMOTE_ADDR for TrustedProxies.
     */
    public function testTrustedProxySetsTrustedProxiesWithRemoteAddr(): void
    {
        $trustedProxy = $this->createTrustedProxy($this->headerAll, 'REMOTE_ADDR');
        $request = $this->createProxiedRequest();

        $this->assertThroughMiddleware($trustedProxy, $request, function ($request) {
            $this->assertSame('173.174.200.38', $request->getClientIp(), 'Assert trusted proxy x-forwarded-for header used with REMOTE_ADDR proxy setting');
        });
    }

    /**
     * Test the most typical usage of TrustProxies:
     * Trusted X-Forwarded-For header.
     */
    public function testTrustedProxySetsTrustedProxies(): void
    {
        $trustedProxy = $this->createTrustedProxy($this->headerAll, ['192.168.10.10']);
        $request = $this->createProxiedRequest();

        $this->assertThroughMiddleware($trustedProxy, $request, function ($request) {
            $this->assertSame('173.174.200.38', $request->getClientIp(), 'Assert trusted proxy x-forwarded-for header used');
        });
    }

    /**
     * Test X-Forwarded-For header with multiple IP addresses.
     */
    public function testGetClientIps(): void
    {
        $trustedProxy = $this->createTrustedProxy($this->headerAll, ['192.168.10.10']);

        $forwardedFor = [
            '192.0.2.2',
            '192.0.2.2, 192.0.2.199',
            '192.0.2.2, 192.0.2.199, 99.99.99.99',
            '192.0.2.2,192.0.2.199',
        ];

        foreach ($forwardedFor as $forwardedForHeader) {
            $request = $this->createProxiedRequest(['HTTP_X_FORWARDED_FOR' => $forwardedForHeader]);

            $this->assertThroughMiddleware($trustedProxy, $request, function ($request) use ($forwardedForHeader) {
                $ips = $request->getClientIps();
                $this->assertSame('192.0.2.2', end($ips), 'Assert sets the ' . $forwardedForHeader);
            });
        }
    }

    /**
     * Test X-Forwarded-For header with multiple IP addresses, with some of those being trusted.
     */
    public function testGetClientIpWithMultipleIpAddressesSomeOfWhichAreTrusted(): void
    {
        $trustedProxy = $this->createTrustedProxy($this->headerAll, ['192.168.10.10', '192.0.2.199']);

        $forwardedFor = [
            '192.0.2.2',
            '192.0.2.2, 192.0.2.199',
            '99.99.99.99, 192.0.2.2, 192.0.2.199',
            '192.0.2.2,192.0.2.199',
        ];

        foreach ($forwardedFor as $forwardedForHeader) {
            $request = $this->createProxiedRequest(['HTTP_X_FORWARDED_FOR' => $forwardedForHeader]);

            $this->assertThroughMiddleware($trustedProxy, $request, function ($request) use ($forwardedForHeader) {
                $this->assertSame('192.0.2.2', $request->getClientIp(), 'Assert sets the ' . $forwardedForHeader);
            });
        }
    }

    /**
     * Test X-Forwarded-For header with multiple IP addresses, with * wildcard trusting of all proxies.
     */
    public function testGetClientIpWithMultipleIpAddressesAllProxiesAreTrusted(): void
    {
        $trustedProxy = $this->createTrustedProxy($this->headerAll, '*');

        $forwardedFor = [
            '192.0.2.2',
            '192.0.2.2, 192.0.2.199',
            '192.0.2.2,192.0.2.199',
            '192.0.2.2,99.99.99.99,192.0.2.199',
        ];

        foreach ($forwardedFor as $forwardedForHeader) {
            $request = $this->createProxiedRequest(['HTTP_X_FORWARDED_FOR' => $forwardedForHeader]);

            $this->assertThroughMiddleware($trustedProxy, $request, function ($request) use ($forwardedForHeader) {
                $this->assertSame('192.0.2.2', $request->getClientIp(), 'Assert sets the ' . $forwardedForHeader);
            });
        }
    }

    /**
     * Test Forwarded header with multiple IP addresses, with * wildcard trusting of all proxies.
     */
    public function testGetClientIpWithMultipleIpAddressesAllProxiesAreTrustedUsingForwardedHeader(): void
    {
        $trustedProxy = $this->createTrustedProxy(Request::HEADER_FORWARDED, '*');

        $forwarded = [
            'for=192.0.2.2',
            'for=192.0.2.2,for=192.0.2.199',
            'for=192.0.2.2,for=99.99.99.99,for=192.0.2.199',
        ];

        foreach ($forwarded as $forwardedHeader) {
            $request = $this->createProxiedRequest(['HTTP_FORWARDED' => $forwardedHeader, 'HTTP_X_FORWARDED_FOR' => '']);

            $this->assertThroughMiddleware($trustedProxy, $request, function ($request) use ($forwardedHeader) {
                $this->assertSame('192.0.2.2', $request->getClientIp(), 'Assert sets the ' . $forwardedHeader);
            });
        }
    }

    /**
     * Test distrusting a header.
     */
    public function testCanDistrustHeaders(): void
    {
        $trustedProxy = $this->createTrustedProxy(Request::HEADER_FORWARDED, ['192.168.10.10']);

        $request = $this->createProxiedRequest([
            'HTTP_FORWARDED' => 'for=173.174.200.40:443; proto=https; host=serversforhackers.com',
            'HTTP_X_FORWARDED_FOR' => '173.174.200.38',
            'HTTP_X_FORWARDED_HOST' => 'svrs4hkrs.com',
            'HTTP_X_FORWARDED_PORT' => '80',
            'HTTP_X_FORWARDED_PROTO' => 'http',
        ]);

        $this->assertThroughMiddleware($trustedProxy, $request, function ($request) {
            $this->assertSame(
                '173.174.200.40',
                $request->getClientIp(),
                'Assert trusted proxy used forwarded header for IP'
            );
            $this->assertSame(
                'https',
                $request->getScheme(),
                'Assert trusted proxy used forwarded header for scheme'
            );
            $this->assertSame(
                'serversforhackers.com',
                $request->getHost(),
                'Assert trusted proxy used forwarded header for host'
            );
            $this->assertSame(443, $request->getPort(), 'Assert trusted proxy used forwarded header for port');
        });
    }

    /**
     * Test that only the X-Forwarded-For header is trusted.
     */
    public function testXForwardedForHeaderOnlyTrusted(): void
    {
        $trustedProxy = $this->createTrustedProxy(Request::HEADER_X_FORWARDED_FOR, '*');

        $request = $this->createProxiedRequest();

        $this->assertThroughMiddleware($trustedProxy, $request, function ($request) {
            $this->assertSame(
                '173.174.200.38',
                $request->getClientIp(),
                'Assert trusted proxy used forwarded header for IP'
            );
            $this->assertSame(
                'http',
                $request->getScheme(),
                'Assert trusted proxy did not use forwarded header for scheme'
            );
            $this->assertSame(
                'localhost',
                $request->getHost(),
                'Assert trusted proxy did not use forwarded header for host'
            );
            $this->assertSame(8888, $request->getPort(), 'Assert trusted proxy did not use forwarded header for port');
            $this->assertSame('', $request->getBaseUrl(), 'Assert trusted proxy did not use forwarded header for prefix');
        });
    }

    /**
     * Test that only the X-Forwarded-Host header is trusted.
     */
    public function testXForwardedHostHeaderOnlyTrusted(): void
    {
        $trustedProxy = $this->createTrustedProxy(Request::HEADER_X_FORWARDED_HOST, '*');

        $request = $this->createProxiedRequest(['HTTP_X_FORWARDED_HOST' => 'serversforhackers.com:8888']);

        $this->assertThroughMiddleware($trustedProxy, $request, function ($request) {
            $this->assertSame(
                '192.168.10.10',
                $request->getClientIp(),
                'Assert trusted proxy did not use forwarded header for IP'
            );
            $this->assertSame(
                'http',
                $request->getScheme(),
                'Assert trusted proxy did not use forwarded header for scheme'
            );
            $this->assertSame(
                'serversforhackers.com',
                $request->getHost(),
                'Assert trusted proxy used forwarded header for host'
            );
            $this->assertSame(8888, $request->getPort(), 'Assert trusted proxy did not use forwarded header for port');
            $this->assertSame('', $request->getBaseUrl(), 'Assert trusted proxy did not use forwarded header for prefix');
        });
    }

    /**
     * Test that only the X-Forwarded-Port header is trusted.
     */
    public function testXForwardedPortHeaderOnlyTrusted(): void
    {
        $trustedProxy = $this->createTrustedProxy(Request::HEADER_X_FORWARDED_PORT, '*');

        $request = $this->createProxiedRequest();

        $this->assertThroughMiddleware($trustedProxy, $request, function ($request) {
            $this->assertSame(
                '192.168.10.10',
                $request->getClientIp(),
                'Assert trusted proxy did not use forwarded header for IP'
            );
            $this->assertSame(
                'http',
                $request->getScheme(),
                'Assert trusted proxy did not use forwarded header for scheme'
            );
            $this->assertSame(
                'localhost',
                $request->getHost(),
                'Assert trusted proxy did not use forwarded header for host'
            );
            $this->assertSame(443, $request->getPort(), 'Assert trusted proxy used forwarded header for port');
            $this->assertSame('', $request->getBaseUrl(), 'Assert trusted proxy did not use forwarded header for prefix');
        });
    }

    /**
     * Test that only the X-Forwarded-Prefix header is trusted.
     */
    public function testXForwardedPrefixHeaderOnlyTrusted(): void
    {
        $trustedProxy = $this->createTrustedProxy(Request::HEADER_X_FORWARDED_PREFIX, '*');

        $request = $this->createProxiedRequest();

        $this->assertThroughMiddleware($trustedProxy, $request, function ($request) {
            $this->assertSame(
                '192.168.10.10',
                $request->getClientIp(),
                'Assert trusted proxy did not use forwarded header for IP'
            );
            $this->assertSame(
                'http',
                $request->getScheme(),
                'Assert trusted proxy did not use forwarded header for scheme'
            );
            $this->assertSame(
                'localhost',
                $request->getHost(),
                'Assert trusted proxy did not use forwarded header for host'
            );
            $this->assertSame(8888, $request->getPort(), 'Assert trusted proxy did not use forwarded header for port');
            $this->assertSame('/prefix', $request->getBaseUrl(), 'Assert trusted proxy used forwarded header for prefix');
        });
    }

    /**
     * Test that only the X-Forwarded-Proto header is trusted.
     */
    public function testXForwardedProtoHeaderOnlyTrusted(): void
    {
        $trustedProxy = $this->createTrustedProxy(Request::HEADER_X_FORWARDED_PROTO, '*');

        $request = $this->createProxiedRequest();

        $this->assertThroughMiddleware($trustedProxy, $request, function ($request) {
            $this->assertSame(
                '192.168.10.10',
                $request->getClientIp(),
                'Assert trusted proxy did not use forwarded header for IP'
            );
            $this->assertSame(
                'https',
                $request->getScheme(),
                'Assert trusted proxy used forwarded header for scheme'
            );
            $this->assertSame(
                'localhost',
                $request->getHost(),
                'Assert trusted proxy did not use forwarded header for host'
            );
            $this->assertSame(8888, $request->getPort(), 'Assert trusted proxy did not use forwarded header for port');
            $this->assertSame('', $request->getBaseUrl(), 'Assert trusted proxy did not use forwarded header for prefix');
        });
    }

    /**
     * Test a combination of individual X-Forwarded-* headers are trusted.
     */
    public function testXForwardedMultipleIndividualHeadersTrusted(): void
    {
        $trustedProxy = $this->createTrustedProxy(
            Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_HOST
            | Request::HEADER_X_FORWARDED_PORT | Request::HEADER_X_FORWARDED_PROTO,
            '*'
        );

        $request = $this->createProxiedRequest();

        $this->assertThroughMiddleware($trustedProxy, $request, function ($request) {
            $this->assertSame(
                '173.174.200.38',
                $request->getClientIp(),
                'Assert trusted proxy used forwarded header for IP'
            );
            $this->assertSame(
                'https',
                $request->getScheme(),
                'Assert trusted proxy used forwarded header for scheme'
            );
            $this->assertSame(
                'serversforhackers.com',
                $request->getHost(),
                'Assert trusted proxy used forwarded header for host'
            );
            $this->assertSame(443, $request->getPort(), 'Assert trusted proxy used forwarded header for port');
            $this->assertSame('', $request->getBaseUrl(), 'Assert trusted proxy did not use forwarded header for prefix');
        });
    }

    /**
     * Test to ensure it's reading text-based configurations and converting it correctly.
     */
    public function testIsReadingTextBasedConfigurations(): void
    {
        $request = $this->createProxiedRequest();

        // trust *all* "X-Forwarded-*" headers
        $trustedProxy = $this->createTrustedProxy('HEADER_X_FORWARDED_ALL', '192.168.1.1, 192.168.1.2');
        $this->assertThroughMiddleware($trustedProxy, $request, function (Request $request) {
            $this->assertSame(
                $this->headerAll,
                $request->getTrustedHeaderSet(),
                'Assert trusted proxy used all "X-Forwarded-*" header'
            );

            $this->assertSame(
                ['192.168.1.1', '192.168.1.2'],
                $request->getTrustedProxies(),
                'Assert trusted proxy using proxies as string separated by comma.'
            );
        });

        // or, if your proxy instead uses the "Forwarded" header
        $trustedProxy = $this->createTrustedProxy('HEADER_FORWARDED', '192.168.1.1, 192.168.1.2');
        $this->assertThroughMiddleware($trustedProxy, $request, function (Request $request) {
            $this->assertSame(
                Request::HEADER_FORWARDED,
                $request->getTrustedHeaderSet(),
                'Assert trusted proxy used forwarded header'
            );

            $this->assertSame(
                ['192.168.1.1', '192.168.1.2'],
                $request->getTrustedProxies(),
                'Assert trusted proxy using proxies as string separated by comma.'
            );
        });

        // or, if you're using AWS ELB
        $trustedProxy = $this->createTrustedProxy('HEADER_X_FORWARDED_AWS_ELB', '192.168.1.1, 192.168.1.2');
        $this->assertThroughMiddleware($trustedProxy, $request, function (Request $request) {
            $this->assertSame(
                Request::HEADER_X_FORWARDED_AWS_ELB,
                $request->getTrustedHeaderSet(),
                'Assert trusted proxy used AWS ELB header'
            );

            $this->assertSame(
                ['192.168.1.1', '192.168.1.2'],
                $request->getTrustedProxies(),
                'Assert trusted proxy using proxies as string separated by comma.'
            );
        });

        // or, if you're using Traefik
        $trustedProxy = $this->createTrustedProxy('HEADER_X_FORWARDED_TRAEFIK', '192.168.1.1, 192.168.1.2');
        $this->assertThroughMiddleware($trustedProxy, $request, function (Request $request) {
            $this->assertSame(
                Request::HEADER_X_FORWARDED_TRAEFIK,
                $request->getTrustedHeaderSet(),
                'Assert trusted proxy used Traefik headers'
            );

            $this->assertSame(
                ['192.168.1.1', '192.168.1.2'],
                $request->getTrustedProxies(),
                'Assert trusted proxy using proxies as string separated by comma.'
            );
        });
    }

    public function testUnsupportedTextHeaderConfigurationIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported trusted header configuration [HEADER_FORWARDE].');

        $trustedProxy = $this->createTrustedProxy('HEADER_FORWARDE', '192.168.10.10');
        $request = $this->createProxiedRequest();

        $this->assertThroughMiddleware($trustedProxy, $request, fn () => null);
    }

    public function testExplicitEmptyProxyOverrideIsPreserved(): void
    {
        TrustProxies::at([]);

        $trustedProxy = $this->createTrustedProxy($this->headerAll, ['192.168.10.10']);
        $request = $this->createProxiedRequest();

        $this->assertThroughMiddleware($trustedProxy, $request, function (Request $request) {
            $this->assertSame('192.168.10.10', $request->getClientIp());
            $this->assertSame([], $request::getTrustedProxies());
        });
    }

    public function testExplicitZeroHeaderMaskIsPreserved(): void
    {
        TrustProxies::withHeaders(0);

        $trustedProxy = $this->createTrustedProxy($this->headerAll, ['192.168.10.10']);
        $request = $this->createProxiedRequest();

        $this->assertThroughMiddleware($trustedProxy, $request, function (Request $request) {
            $this->assertSame('192.168.10.10', $request->getClientIp());
            $this->assertSame(0, $request::getTrustedHeaderSet());
        });
    }

    /**
     * Fake an HTTP request by generating a Symfony Request object.
     *
     * @param array<string, mixed> $serverOverrides
     */
    protected function createProxiedRequest(array $serverOverrides = []): Request
    {
        // Add some X-Forwarded headers and over-ride
        // defaults, simulating a request made over a proxy
        $serverOverrides = array_replace([
            'HTTP_X_FORWARDED_FOR' => '173.174.200.38',         // X-Forwarded-For    -- getClientIp()
            'HTTP_X_FORWARDED_HOST' => 'serversforhackers.com', // X-Forwarded-Host   -- getHosts()
            'HTTP_X_FORWARDED_PORT' => '443',                   // X-Forwarded-Port   -- getPort()
            'HTTP_X_FORWARDED_PREFIX' => '/prefix',             // X-Forwarded-Prefix -- getBaseUrl()
            'HTTP_X_FORWARDED_PROTO' => 'https',                // X-Forwarded-Proto  -- getScheme() / isSecure()
            'SERVER_PORT' => 8888,
            'HTTP_HOST' => 'localhost',
            'REMOTE_ADDR' => '192.168.10.10',
        ], $serverOverrides);

        // Create a fake request made over "http", one that we'd get over a proxy
        // which is likely something like this:
        $request = Request::create('http://localhost:8888/tag/proxy', 'GET', [], [], [], $serverOverrides, null);

        // Trusted state is written through the current coroutine request.
        RequestContext::set($request);

        $request::setTrustedProxies([], $this->headerAll);

        return $request;
    }

    /**
     * Run assertions through the response-typed middleware boundary.
     */
    protected function assertThroughMiddleware(TrustProxies $middleware, Request $request, Closure $assertion): void
    {
        $middleware->handle($request, function (Request $request) use ($assertion): Response {
            $assertion($request);

            return new Response;
        });
    }

    /**
     * Create an anonymous middleware class.
     *
     * @param null|array<int, string>|string $trustedProxies
     */
    protected function createTrustedProxy(int|string $trustedHeaders, array|string|null $trustedProxies): TrustProxies
    {
        return new class($trustedHeaders, $trustedProxies) extends TrustProxies {
            public function __construct(int|string $trustedHeaders, array|string|null $trustedProxies)
            {
                $this->headers = $trustedHeaders;
                $this->proxies = $trustedProxies;
            }
        };
    }
}
