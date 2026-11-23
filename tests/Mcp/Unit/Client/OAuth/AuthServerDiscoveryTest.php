<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Unit\Client\OAuth;

use Hypervel\Http\Client\Destinations\DestinationResolutionException;
use Hypervel\Http\Client\Request;
use Hypervel\Mcp\Client\Exceptions\OAuthException;
use Hypervel\Mcp\Client\OAuth\AuthServerDiscovery;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Mcp\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;

class AuthServerDiscoveryTest extends TestCase
{
    /**
     * Supply controlled DNS answers for the given hosts.
     *
     * @param array<string, list<string>> $hosts
     */
    private function resolveHosts(array $hosts): void
    {
        AuthServerDiscovery::resolveHostsUsing(fn (string $host): array => $hosts[$host] ?? []);
    }

    /**
     * Fake the resource and authorization server metadata responses.
     */
    private function fakeAuthServer(
        string $resource = 'https://mcp.test',
        string $authorizationEndpoint = 'https://auth.test/authorize',
        ?string $registrationEndpoint = null,
    ): void {
        Http::fake([
            $resource . '/.well-known/oauth-protected-resource/mcp' => Http::response([
                'authorization_servers' => ['https://auth.test'],
            ]),
            'https://auth.test/.well-known/oauth-authorization-server' => Http::response(array_filter([
                'issuer' => 'https://auth.test',
                'authorization_endpoint' => $authorizationEndpoint,
                'token_endpoint' => 'https://auth.test/token',
                'registration_endpoint' => $registrationEndpoint,
            ])),
        ]);
    }

    /**
     * Assert that discovery rejects a disallowed address before sending to it.
     */
    private function assertDiscoveryRejects(string $address, string $resourceUrl, ?string $resourceMetadataUrl = null): void
    {
        try {
            (new AuthServerDiscovery)->discover($resourceUrl, $resourceMetadataUrl);
            $this->fail('Expected discovery to reject the disallowed address.');
        } catch (OAuthException $exception) {
            $this->assertStringContainsString("disallowed address [{$address}]", $exception->getMessage());
        }
    }

    #[DataProvider('nonPublicAddresses')]
    public function testRejectsHostsThatResolveToNonPublicAddresses(string $address): void
    {
        $this->resolveHosts(['mcp.test' => ['93.184.216.34'], 'meta.test' => ['93.184.216.34', $address]]);

        Http::fake();

        $this->assertDiscoveryRejects($address, 'https://mcp.test/mcp', 'https://meta.test/prm');

        Http::assertNothingSent();
    }

    /**
     * Provide addresses outside the public internet.
     */
    public static function nonPublicAddresses(): array
    {
        return [
            'rfc1918 10/8' => ['10.0.0.5'],
            'rfc1918 172.16/12' => ['172.16.0.1'],
            'rfc1918 192.168/16' => ['192.168.1.1'],
            'cloud metadata' => ['169.254.169.254'],
            'loopback' => ['127.0.0.1'],
            'carrier grade nat' => ['100.64.0.1'],
            'ipv4 multicast' => ['224.0.0.1'],
            'ipv6 loopback' => ['::1'],
            'ipv6 link local' => ['fe80::1'],
            'ipv6 unique local' => ['fc00::1'],
            'ipv4 mapped ipv6' => ['::ffff:10.0.0.1'],
            'ipv6 multicast' => ['ff02::1'],
            'nat64' => ['64:ff9b::a9fe:a9fe'],
            'local use nat64' => ['64:ff9b:1::a9fe:a9fe'],
            '6to4' => ['2002:7f00:1::'],
            'teredo' => ['2001:0:4136:e378:8000:63bf:3fff:fdd2'],
            'benchmarking' => ['198.18.0.1'],
            'ietf protocol assignments' => ['192.0.0.1'],
            'ipv4 compatible ipv6' => ['::7f00:1'],
            'ipv4 translated ipv6' => ['::ffff:0:7f00:1'],
            'ipv6 site local' => ['fec0::1'],
        ];
    }

    public function testRejectsInternalEndpointsWhenTheResourceAlsoResolvesToPublicAddresses(): void
    {
        $this->resolveHosts(['mcp.test' => ['93.184.216.34', '10.0.0.1'], 'meta.test' => ['10.0.0.5']]);

        Http::fake();

        $this->assertDiscoveryRejects('10.0.0.5', 'https://mcp.test/mcp', 'https://meta.test/prm');

        Http::assertNothingSent();
    }

    // REMOVED: the IPv4-mapped case. The HTTP client's destination policy rejects every IPv4-mapped
    // address; resolvers only return them from nonstandard AAAA records.
    #[TestWith(['64:ff9b::5db8:d822'], 'nat64')]
    public function testAllowsHostsThatResolveToAWrappedPublicIpv4Address(string $address): void
    {
        $this->resolveHosts(['mcp.test' => ['93.184.216.34'], 'auth.test' => [$address]]);

        $this->fakeAuthServer();

        $this->assertSame('https://auth.test', (new AuthServerDiscovery)->discover('https://mcp.test/mcp')->server->issuer);
    }

    public function testRejectsHostsThatDoNotResolve(): void
    {
        $this->resolveHosts(['mcp.test' => ['93.184.216.34'], 'meta.test' => []]);

        // DNS failures pass through unchanged rather than as OAuth policy errors.
        $this->expectException(DestinationResolutionException::class);
        $this->expectExceptionMessage('The host [meta.test] did not resolve to an address.');

        (new AuthServerDiscovery)->discover('https://mcp.test/mcp', 'https://meta.test/prm');
    }

    // REMOVED: allowing private endpoints because the resource itself is private, on the same or
    // another host. Private destinations always require an explicit OAuthDestinationPolicy allowance.
    #[TestWith(['https://intranet.test', ['intranet.test' => ['10.0.0.5']], '10.0.0.5'], 'same host')]
    #[TestWith(['https://mcp.test', ['mcp.test' => ['127.0.0.1'], 'auth.test' => ['127.0.0.1']], '127.0.0.1'], 'other host')]
    public function testPrivateResourcesStillRequireAnAllowanceForPrivateEndpoints(string $resource, array $hosts, string $address): void
    {
        $this->resolveHosts($hosts);

        $this->fakeAuthServer($resource);

        $this->assertDiscoveryRejects($address, "{$resource}/mcp");
    }

    // REMOVED: rejecting registration endpoints during discovery. Each endpoint is checked when a request
    // is sent to it; see OAuthClientTest::testRejectsPrivateDnsTargetsBeforeSendingAtEveryOAuthBoundary().

    public function testPinsEachRequestToTheAddressesThatWereChecked(): void
    {
        $this->resolveHosts(['mcp.test' => ['93.184.216.34'], 'auth.test' => ['93.184.216.35', '2606:4700::1111']]);

        $pins = [];

        Http::fake(function (Request $request, array $options) use (&$pins): void {
            $pins[$request->url()] = $options['curl'][CURLOPT_RESOLVE] ?? null;
        });

        $this->fakeAuthServer();

        (new AuthServerDiscovery)->discover('https://mcp.test/mcp');

        $this->assertSame([
            'https://mcp.test/.well-known/oauth-protected-resource/mcp' => ['mcp.test:443:93.184.216.34'],
            'https://auth.test/.well-known/oauth-authorization-server' => ['auth.test:443:[2606:4700::1111],93.184.216.35'],
        ], $pins);
    }

    public function testLooksUpEachHostOncePerDiscovery(): void
    {
        $lookups = [];

        AuthServerDiscovery::resolveHostsUsing(function (string $host) use (&$lookups): array {
            $lookups[$host] = ($lookups[$host] ?? 0) + 1;

            return ['93.184.216.34'];
        });

        $this->fakeAuthServer();

        (new AuthServerDiscovery)->discover('https://mcp.test/mcp');

        $this->assertSame(['mcp.test' => 1, 'auth.test' => 1], $lookups);
    }

    public function testDoesNotResolveTheAuthorizationEndpointTheBrowserOpens(): void
    {
        $this->resolveHosts([
            'mcp.test' => ['93.184.216.34'],
            'auth.test' => ['93.184.216.34'],
            'login.corp.test' => ['10.0.0.5'],
        ]);

        $this->fakeAuthServer(authorizationEndpoint: 'https://login.corp.test/authorize');

        $this->assertSame(
            'https://login.corp.test/authorize',
            (new AuthServerDiscovery)->discover('https://mcp.test/mcp')->server->authorizationEndpoint,
        );
    }

    // REMOVED: skipping resolution behind an HTTPS proxy, and rejecting local names unresolved there.
    // DNS is resolved and validated locally behind ordinary proxies; a policy selects proxies that resolve themselves.

    public function testStillResolvesHostsWhenOnlyAnHttpProxyIsConfigured(): void
    {
        $this->resolveHosts(['mcp.test' => ['93.184.216.34'], 'meta.test' => ['10.0.0.5']]);

        Http::fake();

        $this->withEnvironmentValues(
            ['HTTP_PROXY' => 'http://proxy.test:3128'],
            fn () => $this->assertDiscoveryRejects('10.0.0.5', 'https://mcp.test/mcp', 'https://meta.test/prm'),
        );
    }
}
