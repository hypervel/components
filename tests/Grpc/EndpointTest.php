<?php

declare(strict_types=1);

namespace Hypervel\Tests\Grpc;

use Hypervel\Grpc\Client\Endpoint;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;

class EndpointTest extends TestCase
{
    public function testParsesHttpHttpsAndSchemeLessTargets(): void
    {
        $http = Endpoint::parse('http://EXAMPLE.test');
        $https = Endpoint::parse('https://example.test/');
        $plain = Endpoint::parse('example.test:50051');

        $this->assertSame('example.test', $http->host);
        $this->assertSame(80, $http->port);
        $this->assertFalse($http->tls);
        $this->assertSame('example.test:80', $http->authority);
        $this->assertSame('example.test:80', $http->peer);

        $this->assertSame(443, $https->port);
        $this->assertTrue($https->tls);
        $this->assertSame('example.test:443', $https->authority);

        $this->assertSame(50051, $plain->port);
        $this->assertFalse($plain->tls);
        $this->assertSame('example.test:50051', $plain->peer);
    }

    public function testAppliesExplicitTlsToASchemeLessTarget(): void
    {
        $secure = Endpoint::parse('example.test', true);
        $plain = Endpoint::parse('example.test', false);

        $this->assertTrue($secure->tls);
        $this->assertSame(443, $secure->port);
        $this->assertSame('example.test:443', $secure->authority);
        $this->assertFalse($plain->tls);
        $this->assertSame(80, $plain->port);
    }

    public function testParsesIpv4AndBracketedIpv6Targets(): void
    {
        $ipv4 = Endpoint::parse('127.0.0.1:50051');
        $ipv6 = Endpoint::parse('https://[2001:DB8::1]:50052');

        $this->assertSame('127.0.0.1', $ipv4->host);
        $this->assertSame('127.0.0.1:50051', $ipv4->authority);
        $this->assertSame('2001:db8::1', $ipv6->host);
        $this->assertSame(50052, $ipv6->port);
        $this->assertSame('[2001:db8::1]:50052', $ipv6->authority);
        $this->assertSame('[2001:db8::1]:50052', $ipv6->peer);
    }

    public function testAcceptsServiceDiscoveryNamesAndHostnameBoundaries(): void
    {
        $service = Endpoint::parse('_GRPC._TCP.Service-Name:50051');
        $absolute = Endpoint::parse('https://Service._GRPC.Example.:8443');
        $maximum = implode('.', [
            str_repeat('a', 63),
            str_repeat('b', 63),
            str_repeat('c', 63),
            str_repeat('d', 61),
        ]);

        $this->assertSame('_grpc._tcp.service-name', $service->host);
        $this->assertSame('_grpc._tcp.service-name:50051', $service->authority);
        $this->assertSame('service._grpc.example.', $absolute->host);
        $this->assertSame('service._grpc.example.:8443', $absolute->authority);
        $this->assertSame($maximum, Endpoint::parse($maximum)->host);
    }

    public function testRejectsTlsConflictsWithExplicitSchemes(): void
    {
        foreach ([
            ['http://example.test', true],
            ['https://example.test', false],
        ] as [$target, $tls]) {
            try {
                Endpoint::parse($target, $tls);
                $this->fail('Expected the target and TLS option to conflict.');
            } catch (InvalidArgumentException $exception) {
                $this->assertSame(
                    'The gRPC TLS option conflicts with the target scheme.',
                    $exception->getMessage(),
                );
            }
        }
    }

    public function testRejectsUnsupportedMalformedAndResolverTargets(): void
    {
        foreach ([
            '',
            'ftp://example.test',
            'dns:///example.test',
            '://example.test',
            'http://',
            'example test:50051',
            'example.test:',
            'example.test:invalid',
            'example.test:0',
            'example.test:65536',
            '[::1',
            '2001:db8::1:50051',
            'http://user:password@example.test',
            'example.test/service',
            'example.test?query=value',
            'example.test#fragment',
            'service..test',
            'service.test..',
            '-service.test',
            'service-.test',
            'service$name.test',
            str_repeat('a', 64) . '.test',
            implode('.', [
                str_repeat('a', 63),
                str_repeat('b', 63),
                str_repeat('c', 63),
                str_repeat('d', 62),
            ]),
            '127.0.0',
            '127.0.0.256',
            'service.123',
        ] as $target) {
            try {
                Endpoint::parse($target);
                $this->fail("Expected gRPC target [{$target}] to be rejected.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
