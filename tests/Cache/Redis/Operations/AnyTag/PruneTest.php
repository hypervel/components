<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis\Operations\AnyTag;

use Hyperf\Redis\Pool\PoolFactory;
use Hyperf\Redis\Pool\RedisPool;
use Hyperf\Redis\RedisFactory;
use Hypervel\Cache\Redis\Operations\AnyTag\Prune;
use Hypervel\Cache\RedisStore;
use Hypervel\Redis\RedisConnection;
use Hypervel\Tests\Cache\Redis\Concerns\MocksRedisConnections;
use Hypervel\Tests\Redis\Stub\FakeRedisClient;
use Hypervel\Tests\TestCase;
use Mockery as m;

/**
 * Tests for the AnyTag/Prune operation.
 *
 * @internal
 * @coversNothing
 */
class PruneTest extends TestCase
{
    use MocksRedisConnections;

    /**
     * @test
     */
    public function testPruneReturnsEmptyStatsWhenNoActiveTagsInRegistry(): void
    {
        $connection = $this->mockConnection();
        $client = $connection->_mockClient;

        // ZREMRANGEBYSCORE on registry removes expired tags
        $client->shouldReceive('zRemRangeByScore')
            ->once()
            ->with('prefix:_any:tag:registry', '-inf', m::type('string'))
            ->andReturn(2); // 2 expired tags removed

        // ZRANGE returns empty (no active tags)
        $client->shouldReceive('zRange')
            ->once()
            ->with('prefix:_any:tag:registry', 0, -1)
            ->andReturn([]);

        $store = $this->createStore($connection);
        $store->setTagMode('any');
        $operation = new Prune($store->getContext());

        $result = $operation->execute();

        $this->assertSame(0, $result['hashes_scanned']);
        $this->assertSame(0, $result['fields_checked']);
        $this->assertSame(0, $result['orphans_removed']);
        $this->assertSame(0, $result['empty_hashes_deleted']);
        $this->assertSame(2, $result['expired_tags_removed']);
    }

    /**
     * @test
     */
    public function testPruneRemovesOrphanedFieldsFromTagHash(): void
    {
        $connection = $this->mockConnection();
        $client = $connection->_mockClient;

        // Step 1: Remove expired tags from registry
        $client->shouldReceive('zRemRangeByScore')
            ->once()
            ->with('prefix:_any:tag:registry', '-inf', m::type('string'))
            ->andReturn(0);

        // Step 2: Get active tags
        $client->shouldReceive('zRange')
            ->once()
            ->with('prefix:_any:tag:registry', 0, -1)
            ->andReturn(['users']);

        // Step 3: HSCAN the tag hash
        $client->shouldReceive('hScan')
            ->once()
            ->andReturnUsing(function ($tagHash, &$iterator, $match, $count) {
                $iterator = 0;
                return [
                    'key1' => '1',
                    'key2' => '1',
                    'key3' => '1',
                ];
            });

        // Pipeline for EXISTS checks
        $client->shouldReceive('pipeline')->once()->andReturn($client);
        $client->shouldReceive('exists')
            ->times(3)
            ->andReturn($client);
        $client->shouldReceive('exec')
            ->once()
            ->andReturn([1, 0, 1]); // key2 doesn't exist (orphaned)

        // HDEL orphaned key2
        $client->shouldReceive('hDel')
            ->once()
            ->with('prefix:_any:tag:users:entries', 'key2')
            ->andReturn(1);

        // HLEN to check if hash is empty
        $client->shouldReceive('hLen')
            ->once()
            ->with('prefix:_any:tag:users:entries')
            ->andReturn(2);

        $store = $this->createStore($connection);
        $store->setTagMode('any');
        $operation = new Prune($store->getContext());

        $result = $operation->execute();

        $this->assertSame(1, $result['hashes_scanned']);
        $this->assertSame(3, $result['fields_checked']);
        $this->assertSame(1, $result['orphans_removed']);
        $this->assertSame(0, $result['empty_hashes_deleted']);
        $this->assertSame(0, $result['expired_tags_removed']);
    }

    /**
     * @test
     */
    public function testPruneDeletesEmptyHashAfterRemovingOrphans(): void
    {
        $connection = $this->mockConnection();
        $client = $connection->_mockClient;

        $client->shouldReceive('zRemRangeByScore')
            ->once()
            ->andReturn(0);

        $client->shouldReceive('zRange')
            ->once()
            ->andReturn(['users']);

        $client->shouldReceive('hScan')
            ->once()
            ->andReturnUsing(function ($tagHash, &$iterator, $match, $count) {
                $iterator = 0;
                return ['key1' => '1'];
            });

        $client->shouldReceive('pipeline')->once()->andReturn($client);
        $client->shouldReceive('exists')->once()->andReturn($client);
        $client->shouldReceive('exec')
            ->once()
            ->andReturn([0]); // key1 doesn't exist (orphaned)

        $client->shouldReceive('hDel')
            ->once()
            ->with('prefix:_any:tag:users:entries', 'key1')
            ->andReturn(1);

        // Hash is now empty
        $client->shouldReceive('hLen')
            ->once()
            ->andReturn(0);

        // Delete empty hash
        $client->shouldReceive('del')
            ->once()
            ->with('prefix:_any:tag:users:entries')
            ->andReturn(1);

        $store = $this->createStore($connection);
        $store->setTagMode('any');
        $operation = new Prune($store->getContext());

        $result = $operation->execute();

        $this->assertSame(1, $result['hashes_scanned']);
        $this->assertSame(1, $result['fields_checked']);
        $this->assertSame(1, $result['orphans_removed']);
        $this->assertSame(1, $result['empty_hashes_deleted']);
    }

    /**
     * @test
     */
    public function testPruneHandlesMultipleTagHashes(): void
    {
        $connection = $this->mockConnection();
        $client = $connection->_mockClient;

        $client->shouldReceive('zRemRangeByScore')
            ->once()
            ->andReturn(1); // 1 expired tag removed

        $client->shouldReceive('zRange')
            ->once()
            ->andReturn(['users', 'posts', 'comments']);

        // First tag: users - 2 fields, 1 orphan
        $client->shouldReceive('hScan')
            ->once()
            ->with('prefix:_any:tag:users:entries', m::any(), '*', m::any())
            ->andReturnUsing(function ($tagHash, &$iterator) {
                $iterator = 0;
                return ['u1' => '1', 'u2' => '1'];
            });
        $client->shouldReceive('pipeline')->once()->andReturn($client);
        $client->shouldReceive('exists')->twice()->andReturn($client);
        $client->shouldReceive('exec')->once()->andReturn([1, 0]);
        $client->shouldReceive('hDel')
            ->once()
            ->with('prefix:_any:tag:users:entries', 'u2')
            ->andReturn(1);
        $client->shouldReceive('hLen')
            ->once()
            ->with('prefix:_any:tag:users:entries')
            ->andReturn(1);

        // Second tag: posts - 1 field, 0 orphans
        $client->shouldReceive('hScan')
            ->once()
            ->with('prefix:_any:tag:posts:entries', m::any(), '*', m::any())
            ->andReturnUsing(function ($tagHash, &$iterator) {
                $iterator = 0;
                return ['p1' => '1'];
            });
        $client->shouldReceive('pipeline')->once()->andReturn($client);
        $client->shouldReceive('exists')->once()->andReturn($client);
        $client->shouldReceive('exec')->once()->andReturn([1]);
        $client->shouldReceive('hLen')
            ->once()
            ->with('prefix:_any:tag:posts:entries')
            ->andReturn(1);

        // Third tag: comments - 3 fields, all orphans (hash becomes empty)
        $client->shouldReceive('hScan')
            ->once()
            ->with('prefix:_any:tag:comments:entries', m::any(), '*', m::any())
            ->andReturnUsing(function ($tagHash, &$iterator) {
                $iterator = 0;
                return ['c1' => '1', 'c2' => '1', 'c3' => '1'];
            });
        $client->shouldReceive('pipeline')->once()->andReturn($client);
        $client->shouldReceive('exists')->times(3)->andReturn($client);
        $client->shouldReceive('exec')->once()->andReturn([0, 0, 0]);
        $client->shouldReceive('hDel')
            ->once()
            ->with('prefix:_any:tag:comments:entries', 'c1', 'c2', 'c3')
            ->andReturn(3);
        $client->shouldReceive('hLen')
            ->once()
            ->with('prefix:_any:tag:comments:entries')
            ->andReturn(0);
        $client->shouldReceive('del')
            ->once()
            ->with('prefix:_any:tag:comments:entries')
            ->andReturn(1);

        $store = $this->createStore($connection);
        $store->setTagMode('any');
        $operation = new Prune($store->getContext());

        $result = $operation->execute();

        $this->assertSame(3, $result['hashes_scanned']);
        $this->assertSame(6, $result['fields_checked']); // 2 + 1 + 3
        $this->assertSame(4, $result['orphans_removed']); // 1 + 0 + 3
        $this->assertSame(1, $result['empty_hashes_deleted']);
        $this->assertSame(1, $result['expired_tags_removed']);
    }

    /**
     * @test
     */
    public function testPruneUsesCorrectTagHashKeyFormat(): void
    {
        $connection = $this->mockConnection();
        $client = $connection->_mockClient;

        $client->shouldReceive('zRemRangeByScore')
            ->once()
            ->with('custom:_any:tag:registry', '-inf', m::type('string'))
            ->andReturn(0);

        $client->shouldReceive('zRange')
            ->once()
            ->with('custom:_any:tag:registry', 0, -1)
            ->andReturn(['users']);

        // Verify correct tag hash key format
        $client->shouldReceive('hScan')
            ->once()
            ->with('custom:_any:tag:users:entries', m::any(), '*', m::any())
            ->andReturnUsing(function ($tagHash, &$iterator) {
                $iterator = 0;
                return [];
            });

        $client->shouldReceive('hLen')
            ->once()
            ->with('custom:_any:tag:users:entries')
            ->andReturn(0);

        $client->shouldReceive('del')
            ->once()
            ->with('custom:_any:tag:users:entries')
            ->andReturn(1);

        $store = $this->createStore($connection, 'custom:');
        $store->setTagMode('any');
        $operation = new Prune($store->getContext());

        $operation->execute();
    }

    /**
     * @test
     */
    public function testPruneClusterModeUsesSequentialExistsChecks(): void
    {
        [$store, $clusterClient] = $this->createClusterStore(tagMode: 'any');

        // Should NOT use pipeline in cluster mode
        $clusterClient->shouldNotReceive('pipeline');

        $clusterClient->shouldReceive('zRemRangeByScore')
            ->once()
            ->andReturn(0);

        $clusterClient->shouldReceive('zRange')
            ->once()
            ->andReturn(['users']);

        $clusterClient->shouldReceive('hScan')
            ->once()
            ->andReturnUsing(function ($tagHash, &$iterator) {
                $iterator = 0;
                return ['key1' => '1', 'key2' => '1'];
            });

        // Sequential EXISTS checks in cluster mode
        $clusterClient->shouldReceive('exists')
            ->once()
            ->with('prefix:key1')
            ->andReturn(1);
        $clusterClient->shouldReceive('exists')
            ->once()
            ->with('prefix:key2')
            ->andReturn(0);

        $clusterClient->shouldReceive('hDel')
            ->once()
            ->with('prefix:_any:tag:users:entries', 'key2')
            ->andReturn(1);

        $clusterClient->shouldReceive('hLen')
            ->once()
            ->andReturn(1);

        $operation = new Prune($store->getContext());
        $result = $operation->execute();

        $this->assertSame(1, $result['hashes_scanned']);
        $this->assertSame(2, $result['fields_checked']);
        $this->assertSame(1, $result['orphans_removed']);
    }

    /**
     * @test
     */
    public function testPruneHandlesEmptyHscanResult(): void
    {
        $connection = $this->mockConnection();
        $client = $connection->_mockClient;

        $client->shouldReceive('zRemRangeByScore')
            ->once()
            ->andReturn(0);

        $client->shouldReceive('zRange')
            ->once()
            ->andReturn(['users']);

        // HSCAN returns empty (no fields in hash)
        $client->shouldReceive('hScan')
            ->once()
            ->andReturnUsing(function ($tagHash, &$iterator) {
                $iterator = 0;
                return [];
            });

        // Should still check HLEN
        $client->shouldReceive('hLen')
            ->once()
            ->andReturn(0);

        $client->shouldReceive('del')
            ->once()
            ->andReturn(1);

        $store = $this->createStore($connection);
        $store->setTagMode('any');
        $operation = new Prune($store->getContext());

        $result = $operation->execute();

        $this->assertSame(1, $result['hashes_scanned']);
        $this->assertSame(0, $result['fields_checked']);
        $this->assertSame(0, $result['orphans_removed']);
        $this->assertSame(1, $result['empty_hashes_deleted']);
    }

    /**
     * @test
     */
    public function testPruneHandlesHscanWithMultipleIterations(): void
    {
        // Use FakeRedisClient stub for proper reference parameter handling
        // (Mockery's andReturnUsing doesn't propagate &$iterator modifications)
        $registryKey = 'prefix:_any:tag:registry';
        $tagHashKey = 'prefix:_any:tag:users:entries';

        $fakeClient = new FakeRedisClient(
            scanResults: [],
            execResults: [
                [1, 0], // First EXISTS batch: key1 exists, key2 orphaned
                [0],    // Second EXISTS batch: key3 orphaned
            ],
            hScanResults: [
                $tagHashKey => [
                    // First hScan: returns 2 fields, iterator = 100 (continue)
                    ['fields' => ['key1' => '1', 'key2' => '1'], 'iterator' => 100],
                    // Second hScan: returns 1 field, iterator = 0 (done)
                    ['fields' => ['key3' => '1'], 'iterator' => 0],
                ],
            ],
            zRangeResults: [
                $registryKey => ['users'],
            ],
            hLenResults: [
                $tagHashKey => 1, // 1 field remaining after cleanup
            ],
        );

        $connection = m::mock(RedisConnection::class);
        $connection->shouldReceive('release')->zeroOrMoreTimes();
        $connection->shouldReceive('serialized')->andReturn(false);
        $connection->shouldReceive('client')->andReturn($fakeClient);

        $pool = m::mock(RedisPool::class);
        $pool->shouldReceive('get')->andReturn($connection);

        $poolFactory = m::mock(PoolFactory::class);
        $poolFactory->shouldReceive('getPool')->with('default')->andReturn($pool);

        $store = new RedisStore(
            m::mock(RedisFactory::class),
            'prefix:',
            'default',
            $poolFactory
        );
        $store->setTagMode('any');

        $operation = new Prune($store->getContext());
        $result = $operation->execute();

        // Verify hScan was called twice (multi-iteration)
        $this->assertSame(2, $fakeClient->getHScanCallCount());

        // Verify stats
        $this->assertSame(1, $result['hashes_scanned']);
        $this->assertSame(3, $result['fields_checked']); // 2 + 1 fields
        $this->assertSame(2, $result['orphans_removed']); // key2 + key3
    }

    /**
     * @test
     */
    public function testPruneUsesCustomScanCount(): void
    {
        $connection = $this->mockConnection();
        $client = $connection->_mockClient;

        $client->shouldReceive('zRemRangeByScore')
            ->once()
            ->andReturn(0);

        $client->shouldReceive('zRange')
            ->once()
            ->andReturn(['users']);

        // HSCAN should use custom count
        $client->shouldReceive('hScan')
            ->once()
            ->with(m::any(), m::any(), '*', 500)
            ->andReturnUsing(function ($tagHash, &$iterator) {
                $iterator = 0;
                return [];
            });

        $client->shouldReceive('hLen')
            ->once()
            ->andReturn(0);

        $client->shouldReceive('del')
            ->once()
            ->andReturn(1);

        $store = $this->createStore($connection);
        $store->setTagMode('any');
        $operation = new Prune($store->getContext());

        $operation->execute(500);
    }

    /**
     * @test
     */
    public function testPruneViaStoreOperationsContainer(): void
    {
        $connection = $this->mockConnection();
        $client = $connection->_mockClient;

        $client->shouldReceive('zRemRangeByScore')
            ->once()
            ->andReturn(0);

        $client->shouldReceive('zRange')
            ->once()
            ->andReturn([]);

        $store = $this->createStore($connection);
        $store->setTagMode('any');

        // Access via the operations container
        $result = $store->anyTagOps()->prune()->execute();

        $this->assertSame(0, $result['hashes_scanned']);
    }

    /**
     * @test
     */
    public function testPruneRemovesExpiredTagsFromRegistry(): void
    {
        $connection = $this->mockConnection();
        $client = $connection->_mockClient;

        // 5 expired tags removed
        $client->shouldReceive('zRemRangeByScore')
            ->once()
            ->with('prefix:_any:tag:registry', '-inf', m::type('string'))
            ->andReturn(5);

        $client->shouldReceive('zRange')
            ->once()
            ->andReturn([]);

        $store = $this->createStore($connection);
        $store->setTagMode('any');
        $operation = new Prune($store->getContext());

        $result = $operation->execute();

        $this->assertSame(5, $result['expired_tags_removed']);
    }

    /**
     * @test
     */
    public function testPruneDoesNotRemoveNonOrphanedFields(): void
    {
        $connection = $this->mockConnection();
        $client = $connection->_mockClient;

        $client->shouldReceive('zRemRangeByScore')
            ->once()
            ->andReturn(0);

        $client->shouldReceive('zRange')
            ->once()
            ->andReturn(['users']);

        $client->shouldReceive('hScan')
            ->once()
            ->andReturnUsing(function ($tagHash, &$iterator) {
                $iterator = 0;
                return ['key1' => '1', 'key2' => '1', 'key3' => '1'];
            });

        $client->shouldReceive('pipeline')->once()->andReturn($client);
        $client->shouldReceive('exists')->times(3)->andReturn($client);
        $client->shouldReceive('exec')
            ->once()
            ->andReturn([1, 1, 1]); // All keys exist

        // Should NOT call hDel since no orphans
        $client->shouldNotReceive('hDel');

        $client->shouldReceive('hLen')
            ->once()
            ->andReturn(3);

        $store = $this->createStore($connection);
        $store->setTagMode('any');
        $operation = new Prune($store->getContext());

        $result = $operation->execute();

        $this->assertSame(1, $result['hashes_scanned']);
        $this->assertSame(3, $result['fields_checked']);
        $this->assertSame(0, $result['orphans_removed']);
        $this->assertSame(0, $result['empty_hashes_deleted']);
    }
}
