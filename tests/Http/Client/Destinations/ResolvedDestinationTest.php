<?php

declare(strict_types=1);

namespace Hypervel\Tests\Http\Client\Destinations;

use GuzzleHttp\Psr7\Uri;
use Hypervel\Http\Client\Destinations\ResolvedDestination;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;

class ResolvedDestinationTest extends TestCase
{
    #[DataProvider('targetPorts')]
    public function testDerivesDirectPinIdentityFromTheTargetUri(
        string $uri,
        int $port,
    ): void {
        $destination = ResolvedDestination::direct(
            new Uri($uri),
            '203.0.114.7',
        );

        $this->assertSame('target.example', $destination->resolvedHost);
        $this->assertSame($port, $destination->resolvedPort);
        $this->assertSame(['203.0.114.7'], $destination->addresses);
        $this->assertDirectCurlOptions(
            $destination,
            'target.example',
            $port,
            ['203.0.114.7'],
        );
    }

    /**
     * Provide target URIs with their effective ports.
     *
     * @return iterable<string, array{string, int}>
     */
    public static function targetPorts(): iterable
    {
        yield 'http default' => ['http://target.example/path', 80];
        yield 'https default' => ['https://target.example/path', 443];
        yield 'explicit' => ['https://target.example:8443/path', 8443];
    }

    #[DataProvider('proxyPorts')]
    public function testDerivesProxyPinIdentityFromTheProxyUri(
        string $proxy,
        string $rendered,
        int $port,
        bool $https,
    ): void {
        $destination = ResolvedDestination::proxy(
            new Uri('https://target.example/path'),
            new Uri($proxy),
            '203.0.114.8',
        );

        $this->assertSame($rendered, $destination->proxy);
        $this->assertSame('proxy.example', $destination->resolvedHost);
        $this->assertSame($port, $destination->resolvedPort);
        $this->assertSame(['203.0.114.8'], $destination->addresses);
        $this->assertSame($https, $destination->usesHttpsProxy());
        $this->assertSame([
            CURLOPT_RESOLVE => ["proxy.example:{$port}:203.0.114.8"],
        ], $destination->curlOptions());
    }

    /**
     * Provide proxy URIs with their rendered form, effective port, and TLS mode.
     *
     * @return iterable<string, array{string, string, int, bool}>
     */
    public static function proxyPorts(): iterable
    {
        yield 'http omitted' => ['http://proxy.example', 'http://proxy.example:80', 80, false];
        yield 'http explicit default' => ['http://proxy.example:80', 'http://proxy.example:80', 80, false];
        yield 'http explicit' => ['http://proxy.example:1080', 'http://proxy.example:1080', 1080, false];
        yield 'https omitted' => ['https://proxy.example', 'https://proxy.example:443', 443, true];
        yield 'https explicit' => ['https://proxy.example:8443', 'https://proxy.example:8443', 8443, true];
    }

    public function testRendersIpv6PinningWithExactlyOneSetOfBrackets(): void
    {
        $direct = ResolvedDestination::direct(
            new Uri('https://[2001:db8::1]/path'),
            '2001:db8::2',
            '203.0.114.7',
        );
        $this->assertDirectCurlOptions(
            $direct,
            '[2001:db8::1]',
            443,
            ['[2001:db8::2]', '203.0.114.7'],
        );
    }

    public function testPinsAllApprovedAddressesForAHostnameProxy(): void
    {
        $proxy = ResolvedDestination::proxy(
            new Uri('https://target.example/path'),
            new Uri('https://proxy.example'),
            '2001:db8::4',
            '203.0.114.8',
        );

        $this->assertSame([
            CURLOPT_RESOLVE => ['proxy.example:443:[2001:db8::4],203.0.114.8'],
        ], $proxy->curlOptions());
    }

    #[DataProvider('literalProxies')]
    public function testLiteralProxiesNeedNoDnsPinning(string $uri, string $address): void
    {
        $proxy = ResolvedDestination::proxy(new Uri('https://target.example'), new Uri($uri), $address);

        $this->assertSame([], $proxy->curlOptions());
    }

    /**
     * Provide literal proxies with equivalent approved addresses.
     */
    public static function literalProxies(): iterable
    {
        yield 'IPv4' => ['http://127.0.0.1', '127.0.0.1'];
        yield 'IPv6' => ['http://[::1]', '::1'];
        yield 'equivalent IPv6 spelling' => ['https://[2001:db8::1]', '2001:db8:0:0:0:0:0:1'];
    }

    #[DataProvider('contradictoryLiteralProxyAddresses')]
    public function testRejectsContradictoryLiteralProxyAddresses(array $addresses): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('exactly one approved address matching its host');

        ResolvedDestination::proxy(new Uri('https://target.example'), new Uri('http://[::1]'), ...$addresses);
    }

    /**
     * Provide proxy address sets that cannot describe the literal host.
     */
    public static function contradictoryLiteralProxyAddresses(): iterable
    {
        yield 'different address' => [['::2']];
        yield 'fallback address' => [['::1', '::2']];
    }

    public function testRejectsAnUnsupportedTargetScheme(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('HTTP or HTTPS host');

        ResolvedDestination::direct(
            new Uri('ftp://target.example/path'),
            '203.0.114.7',
        );
    }

    public function testRejectsProxyMetadataBeyondItsAuthority(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('without user information, a path, query, or fragment');

        ResolvedDestination::proxy(
            new Uri('https://target.example/path'),
            new Uri('https://proxy.example/path'),
            '203.0.114.8',
        );
    }

    /**
     * Assert one direct route uses an isolated multi-address DNS entry.
     *
     * @param list<string> $addresses
     */
    private function assertDirectCurlOptions(
        ResolvedDestination $destination,
        string $host,
        int $port,
        array $addresses,
    ): void {
        $options = $destination->curlOptions();
        $this->assertArrayHasKey(CURLOPT_CONNECT_TO, $options);
        $this->assertArrayHasKey(CURLOPT_RESOLVE, $options);
        $this->assertCount(1, $options[CURLOPT_CONNECT_TO]);
        $this->assertCount(1, $options[CURLOPT_RESOLVE]);
        $this->assertMatchesRegularExpression(
            '/\A' . preg_quote("{$host}:{$port}:", '/')
                . '([a-f0-9]{32}\.[a-f0-9]{32}\.hypervel-http\.invalid)'
                . preg_quote(":{$port}", '/') . '\z/D',
            $options[CURLOPT_CONNECT_TO][0],
        );
        preg_match(
            '/:([a-f0-9]{32}\.[a-f0-9]{32}\.hypervel-http\.invalid):/',
            $options[CURLOPT_CONNECT_TO][0],
            $matches,
        );
        $this->assertSame([
            '+' . $matches[1] . ":{$port}:" . implode(',', $addresses),
        ], $options[CURLOPT_RESOLVE]);
    }
}
