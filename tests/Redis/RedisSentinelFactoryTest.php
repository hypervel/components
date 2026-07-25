<?php

declare(strict_types=1);

namespace Hypervel\Tests\Redis;

use Hypervel\Redis\Exceptions\InvalidRedisConnectionException;
use Hypervel\Redis\RedisSentinelFactory;
use Hypervel\Tests\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use RedisSentinel;
use RuntimeException;

class RedisSentinelFactoryTest extends TestCase
{
    public function testResolveMasterFallsBackAcrossNodesAndPreservesZeroAuth(): void
    {
        $first = m::mock(RedisSentinel::class);
        $first->expects('getMasterAddrByName')
            ->with('primary')
            ->andThrow(new RuntimeException('Unavailable.'));
        $second = m::mock(RedisSentinel::class);
        $second->expects('getMasterAddrByName')
            ->with('primary')
            ->andReturn(['10.0.0.1', '6380']);
        $factory = new class([$first, $second]) extends RedisSentinelFactory {
            public array $createdWith = [];

            public function __construct(private array $sentinels)
            {
            }

            public function create(array $options = []): RedisSentinel
            {
                $this->createdWith[] = $options;

                return array_shift($this->sentinels);
            }
        };

        $master = $factory->resolveMaster([
            'timeout' => 2.5,
            'retry_interval' => 100,
            'sentinel' => [
                'nodes' => ['tcp://127.0.0.1:26379', 'tcp://127.0.0.2:26380'],
                'master_name' => 'primary',
                'persistent' => 'sentinel-id',
                'read_timeout' => 1.5,
                'auth' => '0',
            ],
        ]);

        $this->assertSame(['10.0.0.1', 6380], $master);
        $this->assertCount(2, $factory->createdWith);

        foreach ($factory->createdWith as $options) {
            $this->assertSame(2.5, $options['connectTimeout']);
            $this->assertSame('sentinel-id', $options['persistent']);
            $this->assertSame(100, $options['retryInterval']);
            $this->assertSame(1.5, $options['readTimeout']);
            $this->assertSame('0', $options['auth']);
        }
    }

    #[DataProvider('sentinelEndpoints')]
    public function testResolveMasterPreservesSentinelEndpoint(string $node, string $host): void
    {
        $sentinel = m::mock(RedisSentinel::class);
        $sentinel->expects('getMasterAddrByName')
            ->with('primary')
            ->andReturn(['10.0.0.1', 6380]);
        $factory = new class($sentinel) extends RedisSentinelFactory {
            public array $createdWith = [];

            public function __construct(private RedisSentinel $sentinel)
            {
            }

            public function create(array $options = []): RedisSentinel
            {
                $this->createdWith[] = $options;

                return $this->sentinel;
            }
        };

        $factory->resolveMaster([
            'sentinel' => [
                'nodes' => [$node],
                'master_name' => 'primary',
            ],
        ]);

        $this->assertSame($host, $factory->createdWith[0]['host']);
        $this->assertSame(26379, $factory->createdWith[0]['port']);
    }

    public static function sentinelEndpoints(): array
    {
        return [
            'bare IPv4' => ['127.0.0.1:26379', '127.0.0.1'],
            'bracketed IPv6' => ['[::1]:26379', '[::1]'],
            'explicit TCP' => ['tcp://redis.test:26379', 'tcp://redis.test'],
            'explicit TLS with IPv6' => ['tls://[::1]:26379', 'tls://[::1]'],
        ];
    }

    #[DataProvider('sentinelContexts')]
    public function testResolveMasterNormalizesSentinelContext(array $context, ?array $expected): void
    {
        $sentinel = m::mock(RedisSentinel::class);
        $sentinel->expects('getMasterAddrByName')->andReturn(['10.0.0.1', 6380]);
        $factory = new class($sentinel) extends RedisSentinelFactory {
            public array $createdWith = [];

            public function __construct(private RedisSentinel $sentinel)
            {
            }

            public function create(array $options = []): RedisSentinel
            {
                $this->createdWith[] = $options;

                return $this->sentinel;
            }
        };

        $factory->resolveMaster([
            'sentinel' => [
                'nodes' => ['127.0.0.1:26379'],
                'master_name' => 'primary',
                'context' => $context,
            ],
        ]);

        if ($expected === null) {
            $this->assertArrayNotHasKey('ssl', $factory->createdWith[0]);
        } else {
            $this->assertSame($expected, $factory->createdWith[0]['ssl']);
        }
    }

    public static function sentinelContexts(): array
    {
        $options = ['verify_peer' => false, 'cafile' => '/tmp/ca.pem'];

        return [
            'empty' => [[], null],
            'flat' => [$options, $options],
            'ssl' => [['ssl' => $options], $options],
            'stream' => [['stream' => $options], $options],
        ];
    }

    public function testResolveMasterRejectsUnsupportedNodeComponents(): void
    {
        $factory = new RedisSentinelFactory;

        try {
            $factory->resolveMaster([
                'sentinel' => [
                    'nodes' => [
                        'user:password@127.0.0.1:26379',
                        'tcp://127.0.0.1:26379/path',
                    ],
                    'master_name' => 'primary',
                ],
            ]);
            $this->fail('Expected Sentinel resolution to fail.');
        } catch (InvalidRedisConnectionException $exception) {
            $this->assertStringContainsString(
                '[user:password@127.0.0.1:26379]: unsupported node format',
                $exception->getMessage(),
            );
            $this->assertStringContainsString(
                '[tcp://127.0.0.1:26379/path]: unsupported node format',
                $exception->getMessage(),
            );
        }
    }

    public function testResolveMasterRejectsUnbracketedIpv6Nodes(): void
    {
        $factory = new RedisSentinelFactory;

        try {
            $factory->resolveMaster([
                'sentinel' => [
                    'nodes' => ['fe80::1:2637', '::1'],
                    'master_name' => 'primary',
                ],
            ]);
            $this->fail('Expected Sentinel resolution to fail.');
        } catch (InvalidRedisConnectionException $exception) {
            $this->assertStringContainsString(
                '[fe80::1:2637]: IPv6 node addresses must be bracketed, for example [::1]:26379',
                $exception->getMessage(),
            );
            $this->assertStringContainsString(
                '[::1]: IPv6 node addresses must be bracketed, for example [::1]:26379',
                $exception->getMessage(),
            );
        }
    }

    public function testResolveMasterAggregatesEveryNodeFailure(): void
    {
        $sentinel = m::mock(RedisSentinel::class);
        $sentinel->expects('getMasterAddrByName')
            ->with('primary')
            ->andThrow(new RuntimeException('Connection refused.'));
        $factory = new class($sentinel) extends RedisSentinelFactory {
            public function __construct(private RedisSentinel $sentinel)
            {
            }

            public function create(array $options = []): RedisSentinel
            {
                return $this->sentinel;
            }
        };

        try {
            $factory->resolveMaster([
                'sentinel' => [
                    'nodes' => ['invalid-node', 'tcp://127.0.0.1:26379'],
                    'master_name' => 'primary',
                ],
            ]);
            $this->fail('Expected Sentinel resolution to fail.');
        } catch (InvalidRedisConnectionException $exception) {
            $this->assertStringContainsString('[invalid-node]: invalid node', $exception->getMessage());
            $this->assertStringContainsString(
                '[tcp://127.0.0.1:26379]: Connection refused.',
                $exception->getMessage(),
            );
        }
    }

    public function testResolveMasterRejectsMalformedMasterResponse(): void
    {
        $sentinel = m::mock(RedisSentinel::class);
        $sentinel->expects('getMasterAddrByName')->andReturn(['', 'not-a-port']);
        $factory = new class($sentinel) extends RedisSentinelFactory {
            public function __construct(private RedisSentinel $sentinel)
            {
            }

            public function create(array $options = []): RedisSentinel
            {
                return $this->sentinel;
            }
        };

        $this->expectException(InvalidRedisConnectionException::class);
        $this->expectExceptionMessage(
            '[tcp://127.0.0.1:26379]: master was not resolved'
        );

        $factory->resolveMaster([
            'sentinel' => [
                'nodes' => ['tcp://127.0.0.1:26379'],
                'master_name' => 'primary',
            ],
        ]);
    }
}
