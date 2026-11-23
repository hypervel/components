<?php

declare(strict_types=1);

namespace Hypervel\Tests\Doctor\Feature\Diagnostics;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Contracts\Redis\Factory as RedisFactory;
use Hypervel\Doctor\Diagnostics\RedisConnectionsAreReachable;
use Hypervel\Doctor\Results\Link;
use Hypervel\Redis\RedisProxy;
use Hypervel\Support\Facades\Redis;
use Hypervel\Tests\Doctor\Fixtures\RedisPingServer;
use Hypervel\Tests\Doctor\TestCase;
use Hypervel\Tests\Redis\Fixtures\PhpRedisClusterConnectionStub;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use Redis as PhpRedis;
use RedisCluster;
use RedisClusterException;
use RuntimeException;

class RedisConnectionsAreReachableTest extends TestCase
{
    use VerifiesDoubles;

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
        ]);

        $result = RedisPingServer::check(new RedisConnectionsAreReachable, 'default', '+PONG');

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

        $names = [];
        $proxy = Double::for(RedisProxy::class)->strict();
        $proxy->expects('withConnection')->times(4);
        $redis = Double::for(RedisFactory::class)->strict();
        $redis->expects('connection')->times(4)->resolves(static function (string $name) use (&$names, $proxy): RedisProxy {
            $names[] = $name;
            return $proxy;
        });

        Redis::swap($redis);

        $result = (new RedisConnectionsAreReachable)->check();

        $this->assertSame('pass', $result->status->value);
        $this->assertSame(['cache', 'locks', 'default', 'session'], $names);
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

    public function testReportsConnectionsWhoseServerRejectsThePing(): void
    {
        config([
            'cache.default' => 'redis',
            'cache.stores.redis' => [
                'driver' => 'redis',
                'connection' => '',
                'lock_connection' => 'default',
            ],
        ]);
        $result = RedisPingServer::check(new RedisConnectionsAreReachable, 'default', '-ERR max number of clients reached');

        $this->assertSame('fail', $result->status->value);
        $this->assertSame('- default: Redis rejected PING: ERR max number of clients reached', $result->details);
    }

    public function testReportsTheClusterErrorReturnedByTheSelectedNode(): void
    {
        config(['session.driver' => 'redis', 'session.connection' => 'cluster']);

        $client = Double::for(RedisCluster::class)->strict();
        $client->allows('getMode')->returns(PhpRedis::ATOMIC);
        $client->expects('clearLastError')->returns(true);
        $client->expects('_masters')->returns([['127.0.0.1', 7000]]);
        $client->expects('ping')->with(['127.0.0.1', 7000])->throws(new RedisClusterException('Unable to send command at the specified node'));
        $client->allows('getLastError')->returns('CLUSTERDOWN The cluster is down');

        $connection = (new PhpRedisClusterConnectionStub)->setActiveConnection($client)->shouldTransform();
        $proxy = Double::for(RedisProxy::class)->strict();
        $proxy->expects('withConnection')->resolves(static fn (callable $callback): mixed => $callback($connection));
        $redis = Double::for(RedisFactory::class)->strict();
        $redis->expects('connection')->with('cluster')->returns($proxy);
        Redis::swap($redis);

        $result = (new RedisConnectionsAreReachable)->check();
        $this->assertSame('fail', $result->status->value);
        $this->assertSame('- cluster: Redis rejected PING: CLUSTERDOWN The cluster is down', $result->details);
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
