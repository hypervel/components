<?php

declare(strict_types=1);

namespace Hypervel\Tests\Doctor\Feature\Diagnostics;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Doctor\Diagnostics\RedisConnectionsAreReachable;
use Hypervel\Doctor\Results\Link;
use Hypervel\Support\Facades\Redis;
use Hypervel\Tests\Doctor\TestCase;
use RuntimeException;

class RedisConnectionsAreReachableTest extends TestCase
{
    /**
     * Keep Redis inactive unless selected by the test.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set(['cache.default' => 'array', 'queue.default' => 'sync', 'session.driver' => 'file']);
    }

    public function testPassesWhenConfiguredRedisConnectionsRespond(): void
    {
        config([
            'cache.default' => 'redis',
            'cache.stores.redis' => [
                'driver' => 'redis',
                'connection' => 'default',
            ],
            'database.redis' => [
                'client' => 'phpredis',
                'default' => [],
            ],
        ]);

        Redis::swap(new class {
            /**
             * Resolve a responding connection.
             */
            public function connection(string $name): object
            {
                return new class {
                    /**
                     * Respond to the reachability probe.
                     */
                    public function ping(): string
                    {
                        return 'PONG';
                    }
                };
            }
        });

        $result = (new RedisConnectionsAreReachable)->check();

        $this->assertSame('pass', $result->status->value);
    }

    public function testOnlyProbesRedisConnectionsUsedBySelectedServices(): void
    {
        config([
            'cache.default' => 'stack',
            'cache.stores.stack' => ['driver' => 'stack', 'stores' => ['array', 'failover' => ['ttl' => 60]]],
            'cache.stores.failover' => ['driver' => 'failover', 'stores' => ['redis', 'redis']],
            'cache.stores.redis' => [
                'driver' => 'redis',
                'connection' => 'cache',
                'lock_connection' => 'locks',
            ],
            'cache.stores.unused' => ['driver' => 'redis', 'connection' => 'unused'],
            'queue.default' => 'failover',
            'queue.connections.failover' => ['driver' => 'failover', 'connections' => ['redis', 'sync']],
            'queue.connections.redis' => [
                'driver' => 'redis',
                'connection' => 'default',
            ],
            'session.driver' => 'redis',
            'session.connection' => null,
            'database.redis' => [
                'client' => 'phpredis',
                'default' => [],
                'cache' => [],
                'locks' => [],
                'session' => [],
                'unused' => [],
            ],
        ]);

        $redis = new class {
            public array $connections = [];

            /**
             * Record the active connections and provide a responding probe.
             */
            public function connection(string $name): object
            {
                $this->connections[] = $name;

                return new class {
                    /**
                     * Respond to the reachability probe.
                     */
                    public function ping(): string
                    {
                        return 'PONG';
                    }
                };
            }
        };

        Redis::swap($redis);

        $result = (new RedisConnectionsAreReachable)->check();

        $this->assertSame('pass', $result->status->value);
        $this->assertSame(['cache', 'locks', 'default', 'session'], $redis->connections);
    }

    public function testSkipsWhenRedisIsOnlyScaffoldedAndUnused(): void
    {
        config([
            'cache.default' => 'array',
            'queue.default' => 'sync',
            'session.driver' => 'file',
            'database.redis' => [
                'client' => 'phpredis',
                'default' => [],
            ],
        ]);

        $result = (new RedisConnectionsAreReachable)->check();

        $this->assertSame('skip', $result->status->value);
    }

    public function testLinksToRedisConfigurationWhenAnActiveConnectionCannotBeReached(): void
    {
        config([
            'cache.default' => 'redis',
            'cache.stores.redis' => [
                'driver' => 'redis',
                'connection' => 'default',
            ],
        ]);

        Redis::swap(new class {
            /**
             * Simulate an unreachable Redis connection.
             */
            public function connection(string $name): object
            {
                throw new RuntimeException("Redis connection [{$name}] is unavailable.");
            }
        });

        $result = (new RedisConnectionsAreReachable)->check();

        $this->assertSame('fail', $result->status->value);
        $this->assertEquals([Link::docs('redis', 'configuration')], $result->links);
    }
}
