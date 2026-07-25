<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Horizon\Feature;

use Exception;
use Hypervel\Horizon\Horizon;
use Hypervel\Redis\RedisConfig;
use Hypervel\Redis\RedisConnection;
use Hypervel\Tests\Integration\Horizon\IntegrationTestCase;

class RedisPrefixTest extends IntegrationTestCase
{
    public function testPrefixCanBeConfigured(): void
    {
        config(['horizon.prefix' => 'custom:']);

        Horizon::use('default');

        $this->assertSame('custom:', config('database.redis.horizon.options.prefix'));
        $this->assertSame('custom:', config('horizon.prefix'));
    }

    public function testUseThrowsForUnknownConnection(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Redis connection [missing] has not been configured.');

        Horizon::use('missing');
    }

    public function testStandaloneConnectionConfigurationIsPreserved(): void
    {
        $connection = [
            'host' => 'redis.example.com',
            'username' => 'horizon',
            'password' => 'secret',
            'port' => 6380,
            'database' => 4,
            'timeout' => 2.5,
            'context' => ['stream' => ['verify_peer' => true]],
            'pool' => ['max_connections' => 20],
            'prefix' => 'application:',
            'options' => ['scan' => 1],
        ];

        config([
            'database.redis.horizon-source' => $connection,
            'horizon.prefix' => 'standalone:',
        ]);

        Horizon::use('horizon-source');

        $connection['prefix'] = 'standalone:';
        $connection['options']['prefix'] = 'standalone:';

        $this->assertSame($connection, config('database.redis.horizon'));
        $this->assertSame('standalone:', config('horizon.prefix'));
        $this->assertSame(
            'standalone:',
            $this->app->make(RedisConfig::class)->connectionConfig('horizon')['options']['prefix'],
        );
    }

    public function testClusterConnectionConfigurationAndHashTaggedPrefixArePreserved(): void
    {
        $connection = [
            'username' => 'horizon',
            'password' => 'secret',
            'database' => 4,
            'cluster' => [
                'enable' => true,
                'name' => 'horizon-cluster',
                'seeds' => ['redis-1.example.com:6379', 'redis-2.example.com:6379'],
                'context' => ['stream' => ['verify_peer' => true]],
            ],
            'pool' => ['max_connections' => 20],
            'prefix' => 'application:',
            'options' => ['scan' => 1],
        ];

        config([
            'database.redis.horizon-cluster' => $connection,
            'horizon.prefix' => 'cluster:',
        ]);

        Horizon::use('horizon-cluster');

        $connection['prefix'] = '{cluster:}';
        $connection['options']['prefix'] = '{cluster:}';

        $this->assertSame($connection, config('database.redis.horizon'));
        $this->assertSame('{cluster:}', config('horizon.prefix'));
        $this->assertTrue(RedisConnection::hasHashTag(config('horizon.prefix')));
        $this->assertSame(
            '{cluster:}',
            $this->app->make(RedisConfig::class)->connectionConfig('horizon')['options']['prefix'],
        );
    }

    public function testClusterPrefixIsNotDoubleTagged(): void
    {
        config([
            'database.redis.horizon-cluster' => [
                'cluster' => [
                    'enable' => true,
                    'name' => 'horizon-cluster',
                    'seeds' => ['redis.example.com:6379'],
                ],
            ],
            'horizon.prefix' => '{application}:horizon:',
        ]);

        Horizon::use('horizon-cluster');

        $this->assertSame('{application}:horizon:', config('horizon.prefix'));
        $this->assertSame(
            '{application}:horizon:',
            config('database.redis.horizon.options.prefix'),
        );
    }

    public function testClusterConnectionUsesHashTaggedFallbackPrefix(): void
    {
        config([
            'database.redis.horizon-cluster' => [
                'cluster' => [
                    'enable' => true,
                    'name' => 'horizon-cluster',
                    'seeds' => ['redis.example.com:6379'],
                ],
            ],
            'horizon.prefix' => '',
        ]);

        Horizon::use('horizon-cluster');

        $this->assertSame('{horizon:}', config('horizon.prefix'));
        $this->assertSame('{horizon:}', config('database.redis.horizon.options.prefix'));
    }
}
