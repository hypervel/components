<?php

declare(strict_types=1);

namespace Hypervel\Tests\Redis;

use Hypervel\Contracts\Config\Repository;
use Hypervel\Redis\RedisConfig;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use Mockery as m;

/**
 * @internal
 * @coversNothing
 */
class RedisConfigTest extends TestCase
{
    public function testConnectionNamesExcludesMetadataKeys(): void
    {
        $redisConfig = [
            'client' => 'phpredis',
            'options' => ['prefix' => 'global:'],
            'clusters' => ['cache' => []],
            'default' => ['host' => '127.0.0.1', 'port' => 6379, 'db' => 0],
            'cache' => ['host' => '127.0.0.1', 'port' => 6379, 'db' => 1],
        ];

        $config = m::mock(Repository::class);
        $config->shouldReceive('get')->with('database.redis')->andReturn($redisConfig);

        $this->assertSame(['default', 'cache'], (new RedisConfig($config))->connectionNames());
    }

    public function testConnectionNamesThrowsForNonArrayConnectionEntry(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The redis connection [default] must be an array.');

        $config = m::mock(Repository::class);
        $config->shouldReceive('get')->with('database.redis')->andReturn([
            'default' => 'tcp://127.0.0.1:6379',
        ]);

        (new RedisConfig($config))->connectionNames();
    }

    public function testConnectionNamesThrowsWhenHostPortMissingForDirectConnection(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The redis connection [custom] must define host and port.');

        $config = m::mock(Repository::class);
        $config->shouldReceive('get')->with('database.redis')->andReturn([
            'custom' => ['foo' => 'bar'],
        ]);

        (new RedisConfig($config))->connectionNames();
    }

    public function testConnectionConfigMergesSharedAndConnectionOptions(): void
    {
        $redisConfig = [
            'options' => ['prefix' => 'global:', 'serializer' => 1],
            'default' => [
                'host' => '127.0.0.1',
                'port' => 6379,
                'db' => 0,
                'options' => ['prefix' => 'default:'],
            ],
        ];

        $config = m::mock(Repository::class);
        $config->shouldReceive('get')->with('database.redis')->andReturn($redisConfig);

        $connectionConfig = (new RedisConfig($config))->connectionConfig('default');

        $this->assertSame(
            ['prefix' => 'default:', 'serializer' => 1],
            $connectionConfig['options'],
        );
    }

    public function testConnectionConfigThrowsForMissingConnection(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The redis connection [default] must be an array.');

        $config = m::mock(Repository::class);
        $config->shouldReceive('get')->with('database.redis')->andReturn([]);

        (new RedisConfig($config))->connectionConfig('default');
    }

    public function testConnectionConfigThrowsForInvalidConnectionOptions(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The redis connection [default] options must be an array.');

        $config = m::mock(Repository::class);
        $config->shouldReceive('get')->with('database.redis')->andReturn([
            'options' => ['prefix' => 'global:'],
            'default' => [
                'host' => '127.0.0.1',
                'port' => 6379,
                'db' => 0,
                'options' => 'invalid',
            ],
        ]);

        (new RedisConfig($config))->connectionConfig('default');
    }

    public function testConnectionNamesAcceptsClusterConnectionWithoutHostAndPort(): void
    {
        $config = m::mock(Repository::class);
        $config->shouldReceive('get')->with('database.redis')->andReturn([
            'clustered' => [
                'db' => 0,
                'cluster' => [
                    'enable' => true,
                    'seeds' => ['127.0.0.1:7000', '127.0.0.1:7001'],
                ],
            ],
        ]);

        $this->assertSame(['clustered'], (new RedisConfig($config))->connectionNames());
    }

    public function testConnectionNamesThrowsWhenClusterEnabledWithoutSeeds(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The redis connection [clustered] cluster seeds must be a non-empty array.');

        $config = m::mock(Repository::class);
        $config->shouldReceive('get')->with('database.redis')->andReturn([
            'clustered' => [
                'cluster' => [
                    'enable' => true,
                    'seeds' => [],
                ],
            ],
        ]);

        (new RedisConfig($config))->connectionNames();
    }

    public function testConnectionNamesAcceptsSentinelConnectionWithoutHostAndPort(): void
    {
        $config = m::mock(Repository::class);
        $config->shouldReceive('get')->with('database.redis')->andReturn([
            'sentinel' => [
                'db' => 0,
                'sentinel' => [
                    'enable' => true,
                    'nodes' => ['tcp://127.0.0.1:26379'],
                    'master_name' => 'mymaster',
                ],
            ],
        ]);

        $this->assertSame(['sentinel'], (new RedisConfig($config))->connectionNames());
    }

    public function testConnectionNamesThrowsWhenSentinelEnabledWithoutNodes(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The redis connection [sentinel] sentinel nodes must be a non-empty array.');

        $config = m::mock(Repository::class);
        $config->shouldReceive('get')->with('database.redis')->andReturn([
            'sentinel' => [
                'sentinel' => [
                    'enable' => true,
                    'nodes' => [],
                    'master_name' => 'mymaster',
                ],
            ],
        ]);

        (new RedisConfig($config))->connectionNames();
    }

    public function testConnectionNamesThrowsWhenSentinelEnabledWithoutMasterName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The redis connection [sentinel] sentinel master name must be configured.');

        $config = m::mock(Repository::class);
        $config->shouldReceive('get')->with('database.redis')->andReturn([
            'sentinel' => [
                'sentinel' => [
                    'enable' => true,
                    'nodes' => ['tcp://127.0.0.1:26379'],
                    'master_name' => '',
                ],
            ],
        ]);

        (new RedisConfig($config))->connectionNames();
    }

    public function testConnectionNamesThrowsWhenClusterAndSentinelBothEnabled(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The redis connection [mixed] cannot enable both cluster and sentinel.');

        $config = m::mock(Repository::class);
        $config->shouldReceive('get')->with('database.redis')->andReturn([
            'mixed' => [
                'cluster' => [
                    'enable' => true,
                    'seeds' => ['127.0.0.1:7000'],
                ],
                'sentinel' => [
                    'enable' => true,
                    'nodes' => ['tcp://127.0.0.1:26379'],
                    'master_name' => 'mymaster',
                ],
            ],
        ]);

        (new RedisConfig($config))->connectionNames();
    }
}
