<?php

declare(strict_types=1);

namespace Hypervel\Tests\Http\Client\Destinations;

use Hypervel\Http\Client\Destinations\DestinationResolutionException;
use Hypervel\Http\Client\Destinations\DisallowedDestinationException;
use Hypervel\Http\Client\Destinations\ProxyConnectionException;
use Hypervel\Http\Client\Destinations\PublicDestinationPolicy;
use Hypervel\Tests\Http\Fixtures\FakeDestinationPolicy;
use Hypervel\Tests\Http\Fixtures\LoopbackHttpServer;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class PublicDestinationPolicyTest extends TestCase
{
    private const float TIMEOUT_SECONDS = 1.0;

    public function testNormalizesAndPinsAnAuthorizedPublicDestination(): void
    {
        $policy = new FakeDestinationPolicy([
            'example.com' => ['203.0.114.10', '203.0.114.2'],
        ]);

        $destination = $policy->resolve(
            'HTTPS://EXAMPLE.COM./reports?month=8#totals',
            self::TIMEOUT_SECONDS,
        );

        $this->assertSame(
            'https://example.com/reports?month=8',
            (string) $destination->uri,
        );
        $this->assertSame(
            ['203.0.114.10', '203.0.114.2'],
            $destination->addresses,
        );
    }

    #[DataProvider('reservedAddresses')]
    public function testRejectsReservedDestinations(string $address): void
    {
        $policy = new FakeDestinationPolicy(['example.com' => [$address]]);

        $this->expectException(DisallowedDestinationException::class);
        $this->expectExceptionMessage('disallowed address');

        $policy->resolve('https://example.com', self::TIMEOUT_SECONDS);
    }

    /**
     * Return representative private, local, documentation, and protocol ranges.
     *
     * @return iterable<string, array{string}>
     */
    public static function reservedAddresses(): iterable
    {
        yield 'private IPv4' => ['10.0.0.1'];
        yield 'carrier-grade NAT' => ['100.64.0.1'];
        yield 'loopback IPv4' => ['127.0.0.1'];
        yield 'link-local IPv4' => ['169.254.169.254'];
        yield 'documentation IPv4' => ['198.51.100.1'];
        yield 'benchmark IPv4' => ['198.18.0.1'];
        yield 'multicast IPv4' => ['224.0.0.1'];
        yield 'loopback IPv6' => ['::1'];
        yield 'private IPv6' => ['fc00::1'];
        yield 'link-local IPv6' => ['fe80::1'];
        yield 'documentation IPv6' => ['2001:db8::1'];
    }

    public function testRejectsTheCompleteDnsResultWhenAnyAddressIsReserved(): void
    {
        $policy = new FakeDestinationPolicy([
            'example.com' => ['203.0.114.2', '127.0.0.1'],
        ]);

        $this->expectException(DisallowedDestinationException::class);
        $this->expectExceptionMessage('disallowed address');

        $policy->resolve('https://example.com', self::TIMEOUT_SECONDS);
    }

    public function testNarrowPolicyOverrideCanAllowAnInternalDestination(): void
    {
        $policy = new FakeDestinationPolicy(
            ['internal.example' => ['10.0.0.9']],
            allowedAddresses: ['10.0.0.9'],
        );

        $destination = $policy->resolve(
            'https://internal.example',
            self::TIMEOUT_SECONDS,
        );

        $this->assertSame(['10.0.0.9'], $destination->addresses);
    }

    public function testAllowedNetworksAllowOnlyTheirOwnInternalAddresses(): void
    {
        $policy = new FakeDestinationPolicy(
            [
                'internal.example' => ['10.0.0.9', 'fd00::9'],
                'other.example' => ['10.0.0.9', '192.168.0.9'],
            ],
            allowedNetworks: ['10.0.0.0/8', 'fd00::/8'],
        );

        $destination = $policy->resolve(
            'https://internal.example',
            self::TIMEOUT_SECONDS,
        );

        $this->assertSame(['10.0.0.9', 'fd00::9'], $destination->addresses);

        $this->expectException(DisallowedDestinationException::class);
        $this->expectExceptionMessage('disallowed address [192.168.0.9]');

        $policy->resolve('https://other.example', self::TIMEOUT_SECONDS);
    }

    public function testProxyResolutionPinsOnlyTheApprovedProxy(): void
    {
        $policy = new FakeDestinationPolicy(
            ['proxy.example' => ['203.0.114.9']],
            proxy: 'https://proxy.example:8443',
        );

        $destination = $policy->resolve(
            'https://target.example/orders',
            self::TIMEOUT_SECONDS,
        );

        $this->assertSame(['proxy.example'], $policy->resolvedHosts);
        $this->assertSame('https://proxy.example:8443', $destination->proxy);
        $this->assertSame([
            CURLOPT_RESOLVE => ['proxy.example:8443:203.0.114.9'],
        ], $destination->curlOptions());
    }

    public function testProxyResolutionStillRejectsAPrivateAddressLiteralTarget(): void
    {
        $policy = new FakeDestinationPolicy(
            ['proxy.example' => ['203.0.114.9']],
            proxy: 'https://proxy.example:8443',
        );

        try {
            $policy->resolve('http://169.254.169.254/latest/meta-data', self::TIMEOUT_SECONDS);
            $this->fail('The private target was not rejected.');
        } catch (DisallowedDestinationException $exception) {
            $this->assertStringContainsString('disallowed address [169.254.169.254]', $exception->getMessage());
        }

        $this->assertSame([], $policy->resolvedHosts);
    }

    public function testAnUnresolvableProxyIsAProxyConnectionFailure(): void
    {
        $policy = new FakeDestinationPolicy(proxy: 'https://proxy.example:8443');

        try {
            $policy->resolve('https://target.example', self::TIMEOUT_SECONDS);
            $this->fail('The unresolvable proxy was not reported.');
        } catch (ProxyConnectionException $exception) {
            $this->assertSame('The proxy [proxy.example] could not be resolved.', $exception->getMessage());
            $this->assertInstanceOf(DestinationResolutionException::class, $exception->getPrevious());
        }
    }

    public function testSupportsAuthorizedPublicIpv6Literals(): void
    {
        $policy = new FakeDestinationPolicy;

        $destination = $policy->resolve(
            'https://[2001:4860:4860::8888]/',
            self::TIMEOUT_SECONDS,
        );

        $this->assertSame(
            ['2001:4860:4860::8888'],
            $destination->addresses,
        );
    }

    public function testDirectPinningFallsBackAcrossAuthorizedAddresses(): void
    {
        $server = LoopbackHttpServer::start();
        $policy = new FakeDestinationPolicy(
            ['target.invalid' => ['127.0.0.0', '127.0.0.1']],
            allowedNetworks: ['127.0.0.0/8'],
        );
        $destination = $policy->resolve(
            "http://target.invalid:{$server->port}/probe",
            self::TIMEOUT_SECONDS,
        );
        $curl = curl_init((string) $destination->uri);
        curl_setopt_array($curl, [
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 2,
            CURLOPT_PROXY => '',
        ] + $destination->curlOptions());

        try {
            $this->assertSame('OK', curl_exec($curl));
            $this->assertSame(0, curl_errno($curl));
            $this->assertStringContainsString("Host: target.invalid:{$server->port}\r\n", (string) $server->request());
        } finally {
            curl_close($curl);
        }
    }

    public function testDirectPinningPreservesTheHostWithoutSharedDnsResidue(): void
    {
        $server = LoopbackHttpServer::start();
        $port = $server->port;
        $policy = new FakeDestinationPolicy(
            ['target.invalid' => ['127.0.0.1']],
            allowedNetworks: ['127.0.0.0/8'],
        );
        $destination = $policy->resolve(
            "http://target.invalid:{$port}/probe",
            self::TIMEOUT_SECONDS,
        );
        $share = curl_share_init();
        curl_share_setopt($share, CURLSHOPT_SHARE, CURL_LOCK_DATA_DNS);
        $first = curl_init((string) $destination->uri);
        curl_setopt_array($first, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 2,
            CURLOPT_PROXY => '',
            CURLOPT_SHARE => $share,
        ] + $destination->curlOptions());

        try {
            $this->assertSame('OK', curl_exec($first));
            $this->assertSame(0, curl_errno($first));
            $this->assertStringContainsString("Host: target.invalid:{$port}\r\n", (string) $server->request());

            $followUp = curl_init((string) $destination->uri);
            curl_setopt_array($followUp, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 2,
                CURLOPT_PROXY => '',
                CURLOPT_SHARE => $share,
            ]);

            try {
                $this->assertFalse(curl_exec($followUp));
                $this->assertSame(CURLE_COULDNT_RESOLVE_HOST, curl_errno($followUp));
            } finally {
                curl_close($followUp);
            }
        } finally {
            curl_close($first);
            curl_share_close($share);
        }
    }

    public function testProxyPinningPreservesTheProxyAndTargetHosts(): void
    {
        $server = LoopbackHttpServer::start();
        $policy = new FakeDestinationPolicy(
            ['proxy.invalid' => ['127.0.0.1']],
            allowedNetworks: ['127.0.0.0/8'],
            proxy: "http://proxy.invalid:{$server->port}",
        );
        $destination = $policy->resolve(
            'http://target.invalid/probe',
            self::TIMEOUT_SECONDS,
        );
        $curl = curl_init((string) $destination->uri);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 2,
            CURLOPT_PROXY => $destination->proxy,
        ] + $destination->curlOptions());

        try {
            $this->assertSame('OK', curl_exec($curl));
            $this->assertSame(0, curl_errno($curl));
            $request = (string) $server->request();
            $this->assertStringStartsWith(
                "GET http://target.invalid/probe HTTP/1.1\r\n",
                $request,
            );
            $this->assertStringContainsString("Host: target.invalid\r\n", $request);
        } finally {
            curl_close($curl);
        }
    }

    #[DataProvider('invalidUrls')]
    public function testRejectsInvalidDestinationUrls(string $url): void
    {
        $this->expectException(DisallowedDestinationException::class);

        (new FakeDestinationPolicy)->resolve($url, self::TIMEOUT_SECONDS);
    }

    /**
     * Return invalid absolute destination URLs.
     *
     * @return iterable<string, array{string}>
     */
    public static function invalidUrls(): iterable
    {
        yield 'unsupported scheme' => ['ftp://example.com/file'];
        yield 'userinfo' => ['https://user:secret@example.com'];
        yield 'relative' => ['/relative/path'];
        yield 'unicode' => ["https://example.com/\u{00e9}"];
        yield 'invalid host' => ['https://exa_mple.com'];
    }

    public function testRejectsAProxyUrlWithAPath(): void
    {
        $policy = new FakeDestinationPolicy(proxy: 'https://proxy.example/egress');

        $this->expectException(DisallowedDestinationException::class);
        $this->expectExceptionMessage('Proxy URLs cannot contain a path or query string.');

        $policy->resolve('https://target.example', self::TIMEOUT_SECONDS);
    }

    public function testRejectsInvalidResolverOutput(): void
    {
        $policy = new FakeDestinationPolicy(['example.com' => ['not-an-ip']]);

        $this->expectException(DestinationResolutionException::class);
        $this->expectExceptionMessage('invalid address');

        $policy->resolve('https://example.com', self::TIMEOUT_SECONDS);
    }

    public function testKeepsOneAddressFamilyWhenTheOtherFailsWithinItsBudget(): void
    {
        $policy = new AddressFamilyDestinationPolicy([
            AF_INET => ['8.8.8.8'],
            AF_INET6 => false,
        ]);

        $destination = $policy->resolve(
            'https://example.com',
            self::TIMEOUT_SECONDS,
        );

        $this->assertSame(['8.8.8.8'], $destination->addresses);
        $this->assertSame([AF_INET, AF_INET6], $policy->families);
        $this->assertSame([0.5, 0.5], $policy->timeouts);
    }

    public function testAHostWithoutAddressesIsAResolutionFailure(): void
    {
        $policy = new AddressFamilyDestinationPolicy([
            AF_INET => false,
            AF_INET6 => false,
        ]);

        $this->expectException(DestinationResolutionException::class);
        $this->expectExceptionMessage('The host [example.com] did not resolve to an address.');

        $policy->resolve('https://example.com', self::TIMEOUT_SECONDS);
    }

    public function testProductionResolverRejectsEveryLocalhostAddress(): void
    {
        $this->expectException(DisallowedDestinationException::class);
        $this->expectExceptionMessage('disallowed address');

        (new PublicDestinationPolicy)->resolve(
            'http://localhost',
            self::TIMEOUT_SECONDS,
        );
    }
}

class AddressFamilyDestinationPolicy extends PublicDestinationPolicy
{
    /** @var list<int> */
    public array $families = [];

    /** @var list<float> */
    public array $timeouts = [];

    /**
     * Create a deterministic address-family resolver.
     *
     * @param array<int, false|list<string>> $results
     */
    public function __construct(protected array $results)
    {
        parent::__construct();
    }

    /**
     * Resolve one address family from the configured results.
     */
    protected function resolveAddressFamily(
        string $host,
        int $family,
        float $timeoutSeconds,
    ): array|false {
        $this->families[] = $family;
        $this->timeouts[] = $timeoutSeconds;

        return $this->results[$family];
    }
}
