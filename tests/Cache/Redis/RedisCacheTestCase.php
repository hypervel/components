<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis;

use Hypervel\Cache\RedisStore;
use Hypervel\Contracts\Redis\Factory as RedisFactory;
use Hypervel\Redis\PhpRedisClusterConnection;
use Hypervel\Redis\PhpRedisConnection;
use Hypervel\Redis\RedisConnection;
use Hypervel\Redis\RedisProxy;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Redis\Fixtures\FakeRedisClient;
use Hypervel\Tests\Redis\Fixtures\PhpRedisClusterConnectionStub;
use Hypervel\Tests\Redis\Fixtures\PhpRedisConnectionStub;
use JMac\Testing\Double;
use JMac\Testing\DoubleInterface;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use Redis;
use RedisCluster;

/**
 * Exercise cache operations through real connection forwarding to native client doubles.
 */
abstract class RedisCacheTestCase extends TestCase
{
    use VerifiesDoubles;

    protected function setUp(): void
    {
        parent::setUp();

        // Fixed time for tag tests - ZSET scores use timestamps
        CarbonImmutable::setTestNow('2000-01-01 00:00:00');
    }

    /**
     * Create a standalone connection with an isolated native client.
     */
    protected function mockConnection(): PhpRedisConnection&DoubleInterface
    {
        // Unexpected commands must fail: an extra Redis round trip is a regression.
        $client = Double::for(Redis::class)->strict();
        $client->allows('getMode')->returns(Redis::ATOMIC);
        $client->allows('getOption')->with(Redis::OPT_SERIALIZER)->returns(Redis::SERIALIZER_NONE);
        $client->allows('getOption')->with(Redis::OPT_COMPRESSION)->returns(Redis::COMPRESSION_NONE);
        $client->allows('getOption')->with(Redis::OPT_PREFIX)->returns('');
        $client->allows('getOption')->with(Redis::OPT_SCAN)->returns(0);

        return Double::for((new PhpRedisConnectionStub)->setActiveConnection($client))->passthru();
    }

    /**
     * Create a cluster connection with an isolated native client.
     */
    protected function mockClusterConnection(): PhpRedisClusterConnection&DoubleInterface
    {
        // RedisCluster has no pipeline method, so attempts to pipeline already fail natively.
        $client = Double::for(RedisCluster::class)->strict();
        $client->allows('getOption')->with(Redis::OPT_SERIALIZER)->returns(Redis::SERIALIZER_NONE);
        $client->allows('getOption')->with(Redis::OPT_COMPRESSION)->returns(Redis::COMPRESSION_NONE);
        $client->allows('getOption')->with(Redis::OPT_PREFIX)->returns('');
        $client->allows('getOption')->with(Redis::OPT_SCAN)->returns(0);

        return Double::for((new PhpRedisClusterConnectionStub)->setActiveConnection($client))->passthru();
    }

    /**
     * Create a Redis factory mock for the given connection.
     */
    protected function createRedisFactory(
        RedisConnection $connection,
        string $connectionName = 'default'
    ): RedisFactory&DoubleInterface {
        $redisProxy = Double::for(RedisProxy::class)->strict();
        $redisProxy->allows('withConnection')
            ->resolves(static fn (callable $callback, bool $transform = true): mixed => $callback($connection->shouldTransform($transform)));
        $redisProxy->allows('isCluster')->returns($connection->isCluster());

        $redisFactory = Double::for(RedisFactory::class)->strict();
        $redisFactory->allows('connection')
            ->with($connectionName)
            ->returns($redisProxy);

        return $redisFactory;
    }

    /**
     * Create a store backed by the given connection.
     */
    protected function createStore(
        RedisConnection $connection,
        string $prefix = 'prefix:',
        string $connectionName = 'default',
        ?string $tagMode = null,
    ): RedisStore {
        $store = new RedisStore(
            $this->createRedisFactory($connection, $connectionName),
            $prefix,
            $connectionName
        );

        if ($tagMode !== null) {
            $store->setTagMode($tagMode);
        }

        return $store;
    }

    /**
     * Create a store with its cluster client and connection doubles.
     *
     * @return array{RedisStore, DoubleInterface&RedisCluster, DoubleInterface&PhpRedisClusterConnection}
     */
    protected function createClusterStore(
        string $prefix = 'prefix:',
        string $connectionName = 'default',
        ?string $tagMode = null,
    ): array {
        $connection = $this->mockClusterConnection();

        return [
            $this->createStore($connection, $prefix, $connectionName, $tagMode),
            $connection->client(),
            $connection,
        ];
    }

    /**
     * Create a store with explicit by-reference scan cursor responses.
     */
    protected function createStoreWithFakeClient(
        FakeRedisClient $fakeClient,
        string $prefix = 'prefix:',
        string $connectionName = 'default',
        ?string $tagMode = null,
    ): RedisStore {
        return $this->createStore(
            (new PhpRedisConnectionStub)->setActiveConnection($fakeClient),
            $prefix,
            $connectionName,
            $tagMode,
        );
    }
}
