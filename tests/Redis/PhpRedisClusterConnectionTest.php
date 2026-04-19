<?php

declare(strict_types=1);

namespace Hypervel\Tests\Redis;

use Hypervel\Contracts\Container\Container as ContainerContract;
use Hypervel\Contracts\Pool\PoolInterface;
use Hypervel\Pool\PoolOption;
use Hypervel\Redis\PhpRedisClusterConnection;
use Hypervel\Tests\Redis\Fixtures\FakeRedisClusterClient;
use Hypervel\Tests\Redis\Fixtures\PhpRedisClusterConnectionStub;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use Mockery as m;
use Redis;
use RedisCluster;

class PhpRedisClusterConnectionTest extends TestCase
{
    public function testIsClusterReturnsTrue()
    {
        $connection = new PhpRedisClusterConnectionStub;

        $this->assertTrue($connection->isCluster());
    }

    public function testTransformFiresInAtomicMode()
    {
        $connection = new PhpRedisClusterConnectionStub;

        // In atomic mode, isQueueingMode() returns false, so transforms fire
        $client = m::mock(RedisCluster::class);
        $client->shouldReceive('getMode')->andReturn(Redis::ATOMIC);
        $client->shouldReceive('setNx')
            ->once()
            ->with('key', 'value')
            ->andReturn(true);

        $connection->setActiveConnection($client);
        $connection->shouldTransform(true);

        $result = $connection->__call('setnx', ['key', 'value']);

        $this->assertSame(1, $result);
    }

    public function testTransformSkippedInMultiMode()
    {
        $connection = new PhpRedisClusterConnectionStub;

        // In multi mode, isQueueingMode() returns true, so transforms are skipped
        // and the raw command is forwarded directly to the client
        $client = m::mock(RedisCluster::class);
        $client->shouldReceive('getMode')->andReturn(Redis::MULTI);
        $client->shouldReceive('setnx')
            ->once()
            ->with('key', 'value')
            ->andReturn($client); // multi() returns self for chaining

        $connection->setActiveConnection($client);
        $connection->shouldTransform(true);

        // Without isQueueingMode, this would call callSetnx() which calls setNx()
        // With isQueueingMode true, it calls setnx() directly on the client
        $result = $connection->__call('setnx', ['key', 'value']);

        $this->assertSame($client, $result);
    }

    public function testMastersReturnsClusterMasterNodes()
    {
        $masters = [['127.0.0.1', 6379], ['127.0.0.1', 6380]];
        $client = new FakeRedisClusterClient(masters: $masters);

        $connection = new PhpRedisClusterConnectionStub;
        $connection->setActiveConnection($client);

        $this->assertSame($masters, $connection->masters());
    }

    public function testFlushdbSyncFlushesAllMasterNodes()
    {
        $masters = [['127.0.0.1', 6379], ['127.0.0.1', 6380], ['127.0.0.1', 6381]];
        $client = new FakeRedisClusterClient(masters: $masters);

        $connection = new PhpRedisClusterConnectionStub;
        $connection->setActiveConnection($client);
        $connection->shouldTransform(true);

        $connection->__call('flushdb', []);

        $flushCalls = $client->getFlushdbCalls();
        $this->assertCount(3, $flushCalls);
        $this->assertSame(['127.0.0.1', 6379], $flushCalls[0]['node']);
        $this->assertSame(['127.0.0.1', 6380], $flushCalls[1]['node']);
        $this->assertSame(['127.0.0.1', 6381], $flushCalls[2]['node']);
    }

    public function testFlushdbAsyncUsesRawCommandOnAllMasters()
    {
        $masters = [['127.0.0.1', 6379], ['127.0.0.1', 6380]];
        $client = new FakeRedisClusterClient(masters: $masters);

        $connection = new PhpRedisClusterConnectionStub;
        $connection->setActiveConnection($client);
        $connection->shouldTransform(true);

        $connection->__call('flushdb', ['ASYNC']);

        $rawCalls = $client->getRawCommandCalls();
        $this->assertCount(2, $rawCalls);
        $this->assertSame([['127.0.0.1', 6379], 'flushdb', 'async'], $rawCalls[0]['args']);
        $this->assertSame([['127.0.0.1', 6380], 'flushdb', 'async'], $rawCalls[1]['args']);
    }

    public function testScanTransformIncludesDefaultNode()
    {
        $masters = [['127.0.0.1', 6379], ['127.0.0.1', 6380]];
        $nodeKey = '127.0.0.1:6379';
        $client = new FakeRedisClusterClient(
            masters: $masters,
            scanResults: [
                $nodeKey => [
                    ['keys' => ['key1', 'key2'], 'iterator' => 0],
                ],
            ],
        );

        $connection = new PhpRedisClusterConnectionStub;
        $connection->setActiveConnection($client);
        $connection->shouldTransform(true);

        $cursor = null;
        $result = $connection->scan($cursor, ['match' => '*', 'count' => 10]);

        $this->assertSame([0, ['key1', 'key2']], $result);

        // Verify the scan was called with the default node (first master)
        $scanCalls = $client->getScanCalls();
        $this->assertCount(1, $scanCalls);
        $this->assertSame(['127.0.0.1', 6379], $scanCalls[0]['node']);
    }

    public function testScanTransformUsesExplicitNodeOption()
    {
        $explicitNode = ['127.0.0.1', 6380];
        $nodeKey = '127.0.0.1:6380';
        $client = new FakeRedisClusterClient(
            masters: [['127.0.0.1', 6379], ['127.0.0.1', 6380]],
            scanResults: [
                $nodeKey => [
                    ['keys' => ['key3'], 'iterator' => 0],
                ],
            ],
        );

        $connection = new PhpRedisClusterConnectionStub;
        $connection->setActiveConnection($client);
        $connection->shouldTransform(true);

        $cursor = null;
        $result = $connection->scan($cursor, ['match' => '*', 'count' => 10, 'node' => $explicitNode]);

        $this->assertSame([0, ['key3']], $result);

        $scanCalls = $client->getScanCalls();
        $this->assertCount(1, $scanCalls);
        $this->assertSame($explicitNode, $scanCalls[0]['node']);
    }

    public function testDefaultNodeIsCached()
    {
        $client = m::mock(RedisCluster::class);
        $client->shouldReceive('_masters')
            ->once() // Only called once despite two scan calls
            ->andReturn([['127.0.0.1', 6379]]);
        $client->shouldReceive('scan')
            ->twice()
            ->andReturn(false);

        $connection = new PhpRedisClusterConnectionStub;
        $connection->setActiveConnection($client);
        $connection->shouldTransform(true);

        $cursor = null;
        $connection->scan($cursor, ['match' => '*']);
        $cursor = null;
        $connection->scan($cursor, ['match' => '*']);
    }

    public function testDefaultNodeThrowsWhenNoMasters()
    {
        $client = m::mock(RedisCluster::class);
        $client->shouldReceive('_masters')
            ->once()
            ->andReturn([]);

        $connection = new PhpRedisClusterConnectionStub;
        $connection->setActiveConnection($client);
        $connection->shouldTransform(true);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unable to determine default node');

        $cursor = null;
        $connection->scan($cursor, ['match' => '*']);
    }

    public function testReconnectClearsCachedDefaultNode()
    {
        $pool = m::mock(PoolInterface::class);
        $pool->shouldReceive('getOption')->andReturn(m::mock(PoolOption::class));

        $container = m::mock(ContainerContract::class);
        $container->shouldReceive('has')->andReturn(false);
        $container->shouldReceive('bound')->with('events')->andReturn(false);

        // First client: master is node A
        $clientA = m::mock(RedisCluster::class);
        $clientA->shouldReceive('_masters')->once()->andReturn([['10.0.0.1', 6379]]);
        $clientA->shouldReceive('scan')->andReturn(false);

        // Second client (after reconnect): master is node B
        $clientB = m::mock(RedisCluster::class);
        $clientB->shouldReceive('_masters')->once()->andReturn([['10.0.0.2', 6379]]);
        $clientB->shouldReceive('scan')->andReturn(false);

        $callCount = 0;
        $connection = new class($container, $pool, ['cluster' => ['enable' => true, 'seeds' => ['10.0.0.1:6379']]], $clientA, $clientB, $callCount) extends PhpRedisClusterConnection {
            public function __construct(
                ContainerContract $container,
                PoolInterface $pool,
                array $config,
                private RedisCluster $clientA,
                private RedisCluster $clientB,
                private int &$callCount,
            ) {
                // Call grandparent to merge config, then reconnect via our override
                \Hypervel\Redis\RedisConnection::__construct($container, $pool, $config);
                $this->reconnect();
            }

            protected function createRedisCluster(): RedisCluster
            {
                return $this->callCount++ === 0 ? $this->clientA : $this->clientB;
            }
        };

        $connection->shouldTransform(true);

        // First scan: caches defaultNode as node A
        $cursor = null;
        $connection->scan($cursor, ['match' => '*']);

        // Reconnect: should clear cached defaultNode
        $connection->reconnect();

        // Second scan: should re-query _masters() and get node B
        $cursor = null;
        $connection->scan($cursor, ['match' => '*']);

        // Mockery's ->once() on each client's _masters() verifies each was called exactly once,
        // proving the cache was cleared and re-populated after reconnect.
    }
}
