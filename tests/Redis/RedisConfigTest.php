<?php

declare(strict_types=1);

namespace Hypervel\Tests\Redis;

use Hypervel\Config\Repository;
use Hypervel\Redis\RedisConfig;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;

class RedisConfigTest extends TestCase
{
    public function testConnectionConfigAppliesOptionalStandaloneDefaults(): void
    {
        $config = m::mock(Repository::class);
        $config->shouldReceive('array')->with('database.redis')->andReturn([
            'client' => 'phpredis',
            'options' => ['prefix' => 'shared:'],
            'default' => ['host' => '127.0.0.1', 'port' => 6379],
        ]);

        $connection = (new RedisConfig($config))->connectionConfig('default');

        $this->assertSame([
            'scheme' => null,
            'username' => null,
            'password' => null,
            'database' => 0,
            'name' => null,
            'timeout' => null,
            'read_timeout' => 0.0,
            'context' => [],
            'options' => ['prefix' => 'shared:'],
            'prefix' => null,
            'events' => false,
            'max_retries' => 3,
            'backoff_algorithm' => 'decorrelated_jitter',
            'backoff_base' => 100,
            'backoff_cap' => 1000,
            'pool' => [],
        ], [
            'scheme' => $connection['scheme'],
            'username' => $connection['username'],
            'password' => $connection['password'],
            'database' => $connection['database'],
            'name' => $connection['name'],
            'timeout' => $connection['timeout'],
            'read_timeout' => $connection['read_timeout'],
            'context' => $connection['context'],
            'options' => $connection['options'],
            'prefix' => $connection['prefix'],
            'events' => $connection['events'],
            'max_retries' => $connection['max_retries'],
            'backoff_algorithm' => $connection['backoff_algorithm'],
            'backoff_base' => $connection['backoff_base'],
            'backoff_cap' => $connection['backoff_cap'],
            'pool' => $connection['pool'],
        ]);
        $this->assertArrayNotHasKey('retry_interval', $connection);
    }

    public function testConnectionConfigRejectsUnsupportedClient(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The phpredis Redis client is the only supported client.');

        $config = m::mock(Repository::class);
        $config->shouldReceive('array')->with('database.redis')->andReturn([
            'client' => 'predis',
            'default' => ['host' => '127.0.0.1', 'port' => 6379],
        ]);

        (new RedisConfig($config))->connectionConfig('default');
    }

    public function testConnectionConfigRejectsLaravelClusterNamespace(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'The redis.clusters configuration is not supported. Configure cluster settings on a named Redis connection.'
        );

        $config = m::mock(Repository::class);
        $config->shouldReceive('array')->with('database.redis')->andReturn([
            'clusters' => ['cache' => []],
            'default' => ['host' => '127.0.0.1', 'port' => 6379],
        ]);

        (new RedisConfig($config))->connectionConfig('default');
    }

    public function testConnectionConfigThrowsForNonArrayConnectionEntry(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The redis connection [default] must be an array.');

        $config = m::mock(Repository::class);
        $config->shouldReceive('array')->with('database.redis')->andReturn([
            'default' => 'tcp://127.0.0.1:6379',
        ]);

        (new RedisConfig($config))->connectionConfig('default');
    }

    public function testConnectionConfigThrowsWhenHostPortMissingForDirectConnection(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The redis connection [custom] must define host and port.');

        $config = m::mock(Repository::class);
        $config->shouldReceive('array')->with('database.redis')->andReturn([
            'custom' => ['foo' => 'bar'],
        ]);

        (new RedisConfig($config))->connectionConfig('custom');
    }

    public function testConnectionConfigRejectsUnsupportedScheme(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The redis connection [default] scheme must be tcp or tls.');

        $config = m::mock(Repository::class);
        $config->shouldReceive('array')->with('database.redis')->andReturn([
            'default' => [
                'scheme' => 'ssl',
                'host' => '127.0.0.1',
                'port' => 6379,
            ],
        ]);

        (new RedisConfig($config))->connectionConfig('default');
    }

    public function testConnectionConfigRejectsInvalidContext(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The redis connection [default] context must be an array.');

        $config = m::mock(Repository::class);
        $config->shouldReceive('array')->with('database.redis')->andReturn([
            'default' => [
                'context' => 'invalid',
                'host' => '127.0.0.1',
                'port' => 6379,
            ],
        ]);

        (new RedisConfig($config))->connectionConfig('default');
    }

    public function testConnectionConfigMergesSharedAndConnectionOptions(): void
    {
        $redisConfig = [
            'options' => ['prefix' => 'global:', 'serializer' => 1],
            'default' => [
                'host' => '127.0.0.1',
                'port' => 6379,
                'database' => 0,
                'options' => ['prefix' => 'default:'],
            ],
        ];

        $config = m::mock(Repository::class);
        $config->shouldReceive('array')->with('database.redis')->andReturn($redisConfig);

        $connectionConfig = (new RedisConfig($config))->connectionConfig('default');

        $this->assertSame(
            ['prefix' => 'default:', 'serializer' => 1],
            $connectionConfig['options'],
        );
    }

    public function testTopLevelConnectionPrefixOverridesSharedAndLocalOptions(): void
    {
        $config = m::mock(Repository::class);
        $config->shouldReceive('array')->with('database.redis')->andReturn([
            'options' => ['prefix' => 'shared:', 'serializer' => 1],
            'default' => [
                'host' => '127.0.0.1',
                'port' => 6379,
                'prefix' => 'top-level:',
                'options' => ['prefix' => 'local:', 'scan' => 1],
            ],
        ]);

        $connection = (new RedisConfig($config))->connectionConfig('default');

        $this->assertSame(
            [
                'prefix' => 'top-level:',
                'serializer' => 1,
                'scan' => 1,
            ],
            $connection['options'],
        );
    }

    public function testEventOverridePreservesConfigUntilExplicitlyChanged(): void
    {
        $config = m::mock(Repository::class);
        $config->shouldReceive('array')->with('database.redis')->andReturn([
            'options' => [],
            'default' => [
                'host' => '127.0.0.1',
                'port' => 6379,
                'events' => true,
                'options' => [],
            ],
        ]);
        $redisConfig = new RedisConfig($config);

        $this->assertTrue($redisConfig->connectionConfig('default')['events']);

        $redisConfig->disableEvents();
        $this->assertFalse($redisConfig->connectionConfig('default')['events']);

        $redisConfig->enableEvents();
        $this->assertTrue($redisConfig->connectionConfig('default')['events']);
    }

    public function testEventOverrideCreatesEventConfigForFutureAssemblies(): void
    {
        $config = m::mock(Repository::class);
        $config->shouldReceive('array')->with('database.redis')->andReturn([
            'options' => [],
            'default' => [
                'host' => '127.0.0.1',
                'port' => 6379,
                'options' => [],
            ],
        ]);
        $redisConfig = new RedisConfig($config);

        $redisConfig->enableEvents();

        $this->assertTrue($redisConfig->connectionConfig('default')['events']);
    }

    public function testConnectionConfigThrowsForMissingConnection(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The redis connection [default] must be an array.');

        $config = m::mock(Repository::class);
        $config->shouldReceive('array')->with('database.redis')->andReturn([]);

        (new RedisConfig($config))->connectionConfig('default');
    }

    public function testConnectionConfigThrowsForInvalidConnectionOptions(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The redis connection [default] options must be an array.');

        $config = m::mock(Repository::class);
        $config->shouldReceive('array')->with('database.redis')->andReturn([
            'options' => ['prefix' => 'global:'],
            'default' => [
                'host' => '127.0.0.1',
                'port' => 6379,
                'database' => 0,
                'options' => 'invalid',
            ],
        ]);

        (new RedisConfig($config))->connectionConfig('default');
    }

    public function testNullConnectionPrefixInheritsSharedPrefix(): void
    {
        $config = m::mock(Repository::class);
        $config->shouldReceive('array')->with('database.redis')->andReturn([
            'options' => ['prefix' => 'shared:'],
            'default' => [
                'host' => '127.0.0.1',
                'port' => 6379,
                'options' => ['scan' => 1],
                'prefix' => null,
            ],
        ]);

        $connection = (new RedisConfig($config))->connectionConfig('default');

        $this->assertSame(['prefix' => 'shared:', 'scan' => 1], $connection['options']);
    }

    public function testConnectionConfigAcceptsClusterConnectionWithoutHostAndPort(): void
    {
        $config = m::mock(Repository::class);
        $config->shouldReceive('array')->with('database.redis')->andReturn([
            'options' => [],
            'clustered' => [
                'cluster' => [
                    'enabled' => true,
                    'seeds' => ['127.0.0.1:7000', '127.0.0.1:7001'],
                ],
            ],
        ]);

        $connection = (new RedisConfig($config))->connectionConfig('clustered');

        $this->assertSame('tcp', $connection['scheme']);
        $this->assertSame([], $connection['context']);
        $this->assertNull($connection['username']);
        $this->assertNull($connection['password']);
        $this->assertSame(
            ['tcp://127.0.0.1:7000', 'tcp://127.0.0.1:7001'],
            $connection['cluster']['seeds'],
        );
        $this->assertArrayNotHasKey('database', $connection);
        $this->assertArrayNotHasKey('name', $connection);
    }

    public function testConnectionConfigThrowsWhenClusterEnabledWithoutSeeds(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The redis connection [clustered] cluster seeds must be a non-empty array.');

        $config = m::mock(Repository::class);
        $config->shouldReceive('array')->with('database.redis')->andReturn([
            'clustered' => [
                'cluster' => [
                    'enabled' => true,
                    'seeds' => [],
                ],
            ],
        ]);

        (new RedisConfig($config))->connectionConfig('clustered');
    }

    #[DataProvider('invalidTopologyEntries')]
    public function testConnectionConfigRejectsInvalidClusterSeeds(mixed $seed): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The redis connection [clustered] cluster seeds must all be non-empty strings.');

        $config = m::mock(Repository::class);
        $config->shouldReceive('array')->with('database.redis')->andReturn([
            'clustered' => [
                'cluster' => [
                    'enabled' => true,
                    'seeds' => [$seed],
                ],
            ],
        ]);

        (new RedisConfig($config))->connectionConfig('clustered');
    }

    public function testConfiguredTlsSchemeSecuresBareClusterSeeds(): void
    {
        $config = m::mock(Repository::class);
        $config->shouldReceive('array')->with('database.redis')->andReturn([
            'options' => [],
            'clustered' => [
                'scheme' => 'tls',
                'options' => [],
                'cluster' => [
                    'enabled' => true,
                    'seeds' => ['127.0.0.1:7000'],
                ],
            ],
        ]);

        $connection = (new RedisConfig($config))->connectionConfig('clustered');

        $this->assertSame('tls', $connection['scheme']);
        $this->assertSame([], $connection['context']);
        $this->assertSame(['tls://127.0.0.1:7000'], $connection['cluster']['seeds']);
    }

    public function testSecureClusterSeedSelectsTlsTransport(): void
    {
        $config = m::mock(Repository::class);
        $config->shouldReceive('array')->with('database.redis')->andReturn([
            'options' => [],
            'clustered' => [
                'options' => [],
                'cluster' => [
                    'enabled' => true,
                    'seeds' => ['ssl://127.0.0.1:7000', '127.0.0.1:7001'],
                ],
            ],
        ]);

        $connection = (new RedisConfig($config))->connectionConfig('clustered');

        $this->assertSame('tls', $connection['scheme']);
        $this->assertSame(
            ['tls://127.0.0.1:7000', 'tls://127.0.0.1:7001'],
            $connection['cluster']['seeds'],
        );
    }

    public function testNonEmptyClusterContextSelectsTlsTransport(): void
    {
        $context = ['ssl' => ['verify_peer' => true]];
        $config = m::mock(Repository::class);
        $config->shouldReceive('array')->with('database.redis')->andReturn([
            'options' => [],
            'clustered' => [
                'context' => $context,
                'options' => [],
                'cluster' => [
                    'enabled' => true,
                    'seeds' => ['127.0.0.1:7000'],
                ],
            ],
        ]);

        $connection = (new RedisConfig($config))->connectionConfig('clustered');

        $this->assertSame('tls', $connection['scheme']);
        $this->assertSame($context, $connection['context']);
        $this->assertSame(['tls://127.0.0.1:7000'], $connection['cluster']['seeds']);
    }

    #[DataProvider('inconsistentClusterTransports')]
    public function testConnectionConfigRejectsInconsistentClusterTransport(array $connection): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'PhpRedis applies one stream context to every discovered node; use a single tcp or tls transport across scheme, context, and seeds.'
        );

        $config = m::mock(Repository::class);
        $config->shouldReceive('array')->with('database.redis')->andReturn([
            'clustered' => $connection,
        ]);

        (new RedisConfig($config))->connectionConfig('clustered');
    }

    public static function inconsistentClusterTransports(): array
    {
        return [
            'configured tcp with tls seed' => [[
                'scheme' => 'tcp',
                'cluster' => [
                    'enabled' => true,
                    'seeds' => ['tls://127.0.0.1:7000'],
                ],
            ]],
            'stream context with tcp seed' => [[
                'context' => ['ssl' => ['verify_peer' => true]],
                'cluster' => [
                    'enabled' => true,
                    'seeds' => ['tcp://127.0.0.1:7000'],
                ],
            ]],
            'configured tcp with stream context' => [[
                'scheme' => 'tcp',
                'context' => ['ssl' => ['verify_peer' => true]],
                'cluster' => [
                    'enabled' => true,
                    'seeds' => ['127.0.0.1:7000'],
                ],
            ]],
            'mixed seed schemes' => [[
                'cluster' => [
                    'enabled' => true,
                    'seeds' => ['tcp://127.0.0.1:7000', 'tls://127.0.0.1:7001'],
                ],
            ]],
        ];
    }

    public function testConnectionConfigRejectsUnsupportedClusterSeedScheme(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The redis connection [clustered] cluster seeds may only use tcp, tls, or ssl schemes.');

        $config = m::mock(Repository::class);
        $config->shouldReceive('array')->with('database.redis')->andReturn([
            'clustered' => [
                'cluster' => [
                    'enabled' => true,
                    'seeds' => ['redis://127.0.0.1:7000'],
                ],
            ],
        ]);

        (new RedisConfig($config))->connectionConfig('clustered');
    }

    public function testConnectionConfigNormalizesOptionalSentinelSettings(): void
    {
        $config = m::mock(Repository::class);
        $config->shouldReceive('array')->with('database.redis')->andReturn([
            'options' => [],
            'sentinel' => [
                'options' => [],
                'sentinel' => [
                    'enabled' => true,
                    'nodes' => ['tcp://127.0.0.1:26379'],
                    'master_name' => 'mymaster',
                ],
            ],
        ]);

        $connection = (new RedisConfig($config))->connectionConfig('sentinel');

        $this->assertNull($connection['username']);
        $this->assertNull($connection['password']);
        $this->assertSame(0, $connection['database']);
        $this->assertNull($connection['name']);
        $this->assertSame('mymaster', $connection['sentinel']['master_name']);
        $this->assertNull($connection['sentinel']['username']);
        $this->assertNull($connection['sentinel']['password']);
        $this->assertSame(0.0, $connection['sentinel']['timeout']);
        $this->assertSame(0.0, $connection['sentinel']['read_timeout']);
        $this->assertSame([], $connection['sentinel']['context']);
    }

    public function testConnectionConfigThrowsWhenSentinelEnabledWithoutNodes(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The redis connection [sentinel] sentinel nodes must be a non-empty array.');

        $config = m::mock(Repository::class);
        $config->shouldReceive('array')->with('database.redis')->andReturn([
            'sentinel' => [
                'sentinel' => [
                    'enabled' => true,
                    'nodes' => [],
                    'master_name' => 'mymaster',
                ],
            ],
        ]);

        (new RedisConfig($config))->connectionConfig('sentinel');
    }

    #[DataProvider('invalidTopologyEntries')]
    public function testConnectionConfigRejectsInvalidSentinelNodes(mixed $node): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The redis connection [sentinel] sentinel nodes must all be non-empty strings.');

        $config = m::mock(Repository::class);
        $config->shouldReceive('array')->with('database.redis')->andReturn([
            'sentinel' => [
                'sentinel' => [
                    'enabled' => true,
                    'nodes' => [$node],
                    'master_name' => 'mymaster',
                ],
            ],
        ]);

        (new RedisConfig($config))->connectionConfig('sentinel');
    }

    public static function invalidTopologyEntries(): array
    {
        return [
            'non-string' => [null],
            'empty string' => [''],
        ];
    }

    public function testConnectionConfigThrowsWhenSentinelEnabledWithoutMasterName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The redis connection [sentinel] sentinel master name must be configured.');

        $config = m::mock(Repository::class);
        $config->shouldReceive('array')->with('database.redis')->andReturn([
            'sentinel' => [
                'sentinel' => [
                    'enabled' => true,
                    'nodes' => ['tcp://127.0.0.1:26379'],
                    'master_name' => '',
                ],
            ],
        ]);

        (new RedisConfig($config))->connectionConfig('sentinel');
    }

    public function testConnectionConfigParsesUrl(): void
    {
        $config = m::mock(Repository::class);
        $config->shouldReceive('array')->with('database.redis')->andReturn([
            'options' => [],
            'default' => [
                'url' => 'redis://myuser:secret@redis.example.com:6380/3',
                'options' => [],
            ],
        ]);

        $connectionConfig = (new RedisConfig($config))->connectionConfig('default');

        $this->assertSame('redis.example.com', $connectionConfig['host']);
        $this->assertSame(6380, $connectionConfig['port']);
        $this->assertSame('myuser', $connectionConfig['username']);
        $this->assertSame('secret', $connectionConfig['password']);
        $this->assertSame(3, $connectionConfig['database']);
    }

    public function testConnectionConfigUrlOverridesExplicitValues(): void
    {
        $config = m::mock(Repository::class);
        $config->shouldReceive('array')->with('database.redis')->andReturn([
            'options' => [],
            'default' => [
                'url' => 'redis://urlhost:6380/2',
                'host' => 'confighost',
                'port' => 6379,
                'database' => 0,
                'options' => [],
            ],
        ]);

        $connectionConfig = (new RedisConfig($config))->connectionConfig('default');

        $this->assertSame('urlhost', $connectionConfig['host']);
        $this->assertSame(6380, $connectionConfig['port']);
        $this->assertSame(2, $connectionConfig['database']);
    }

    public function testConnectionConfigWithoutUrlPreservesExplicitValues(): void
    {
        $config = m::mock(Repository::class);
        $config->shouldReceive('array')->with('database.redis')->andReturn([
            'options' => [],
            'default' => [
                'host' => '127.0.0.1',
                'port' => 6379,
                'database' => 0,
                'options' => [],
            ],
        ]);

        $connectionConfig = (new RedisConfig($config))->connectionConfig('default');

        $this->assertSame('127.0.0.1', $connectionConfig['host']);
        $this->assertSame(6379, $connectionConfig['port']);
        $this->assertSame(0, $connectionConfig['database']);
    }

    public function testConnectionConfigNormalizesExplicitStringDatabase(): void
    {
        $config = m::mock(Repository::class);
        $config->shouldReceive('array')->with('database.redis')->andReturn([
            'options' => [],
            'default' => [
                'host' => '127.0.0.1',
                'port' => 6379,
                'database' => '4',
                'options' => [],
            ],
        ]);

        $connectionConfig = (new RedisConfig($config))->connectionConfig('default');

        $this->assertSame(4, $connectionConfig['database']);
    }

    public function testConnectionConfigAcceptsUrlOnlyConnection(): void
    {
        $config = m::mock(Repository::class);
        $config->shouldReceive('array')->with('database.redis')->andReturn([
            'options' => [],
            'default' => [
                'url' => 'redis://127.0.0.1:6379/0',
                'options' => [],
            ],
        ]);

        $connection = (new RedisConfig($config))->connectionConfig('default');

        $this->assertSame('127.0.0.1', $connection['host']);
        $this->assertSame(0, $connection['database']);
    }

    public function testConnectionConfigThrowsWhenClusterAndSentinelBothEnabled(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The redis connection [mixed] cannot enable both cluster and sentinel.');

        $config = m::mock(Repository::class);
        $config->shouldReceive('array')->with('database.redis')->andReturn([
            'mixed' => [
                'cluster' => [
                    'enabled' => true,
                    'seeds' => ['127.0.0.1:7000'],
                ],
                'sentinel' => [
                    'enabled' => true,
                    'nodes' => ['tcp://127.0.0.1:26379'],
                    'master_name' => 'mymaster',
                ],
            ],
        ]);

        (new RedisConfig($config))->connectionConfig('mixed');
    }
}
