<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis\Operations\AnyTag;

use Hypervel\Cache\Redis\Operations\AnyTag\Prune;
use Hypervel\Tests\Cache\Redis\RedisCacheTestCase;
use Hypervel\Tests\Redis\Stub\FakeRedisClient;
use Mockery as m;

/**
 * Tests for the AnyTag/Prune operation.
 *
 * @internal
 * @coversNothing
 */
class PruneTest extends RedisCacheTestCase
{
    /**
     * @test
     */
    public function testPruneReturnsEmptyStatsWhenNoActiveTagsInRegistry(): void
    {
        $connection = $this->mockConnection();

        // ZREMRANGEBYSCORE on registry removes expired tags
        $connection->shouldReceive('zRemRangeByScore')
            ->once()
            ->with('prefix:_any:tag:registry', '-inf', m::type('string'))
            ->andReturn(2); // 2 expired tags removed

        // ZRANGE returns empty (no active tags)
        $connection->shouldReceive('zRange')
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

        // Step 1: Remove expired tags from registry
        $connection->shouldReceive('zRemRangeByScore')
            ->once()
            ->with('prefix:_any:tag:registry', '-inf', m::type('string'))
            ->andReturn(0);

        // Step 2: Get active tags
        $connection->shouldReceive('zRange')
            ->once()
            ->with('prefix:_any:tag:registry', 0, -1)
            ->andReturn(['users']);

        // Step 3: HSCAN the tag hash
        $connection->shouldReceive('hScan')
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
        $connection->shouldReceive('pipeline')->once()->andReturn($connection);
        $connection->shouldReceive('exists')
            ->times(3)
            ->andReturn($connection);
        $connection->shouldReceive('exec')
            ->once()
            ->andReturn([1, 0, 1]); // key2 doesn't exist (orphaned)

        // HDEL orphaned key2
        $connection->shouldReceive('hDel')
            ->once()
            ->with('prefix:_any:tag:users:entries', 'key2')
            ->andReturn(1);

        // HLEN to check if hash is empty
        $connection->shouldReceive('hLen')
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

        $connection->shouldReceive('zRemRangeByScore')
            ->once()
            ->andReturn(0);

        $connection->shouldReceive('zRange')
            ->once()
            ->andReturn(['users']);

        $connection->shouldReceive('hScan')
            ->once()
            ->andReturnUsing(function ($tagHash, &$iterator, $match, $count) {
                $iterator = 0;
                return ['key1' => '1'];
            });

        $connection->shouldReceive('pipeline')->once()->andReturn($connection);
        $connection->shouldReceive('exists')->once()->andReturn($connection);
        $connection->shouldReceive('exec')
            ->once()
            ->andReturn([0]); // key1 doesn't exist (orphaned)

        $connection->shouldReceive('hDel')
            ->once()
            ->with('prefix:_any:tag:users:entries', 'key1')
            ->andReturn(1);

        // Hash is now empty
        $connection->shouldReceive('hLen')
            ->once()
            ->andReturn(0);

        // Delete empty hash
        $connection->shouldReceive('del')
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

        $connection->shouldReceive('zRemRangeByScore')
            ->once()
            ->andReturn(1); // 1 expired tag removed

        $connection->shouldReceive('zRange')
            ->once()
            ->andReturn(['users', 'posts', 'comments']);

        // First tag: users - 2 fields, 1 orphan
        $connection->shouldReceive('hScan')
            ->once()
            ->with('prefix:_any:tag:users:entries', m::any(), '*', m::any())
            ->andReturnUsing(function ($tagHash, &$iterator) {
                $iterator = 0;
                return ['u1' => '1', 'u2' => '1'];
            });
        $connection->shouldReceive('pipeline')->once()->andReturn($connection);
        $connection->shouldReceive('exists')->twice()->andReturn($connection);
        $connection->shouldReceive('exec')->once()->andReturn([1, 0]);
        $connection->shouldReceive('hDel')
            ->once()
            ->with('prefix:_any:tag:users:entries', 'u2')
            ->andReturn(1);
        $connection->shouldReceive('hLen')
            ->once()
            ->with('prefix:_any:tag:users:entries')
            ->andReturn(1);

        // Second tag: posts - 1 field, 0 orphans
        $connection->shouldReceive('hScan')
            ->once()
            ->with('prefix:_any:tag:posts:entries', m::any(), '*', m::any())
            ->andReturnUsing(function ($tagHash, &$iterator) {
                $iterator = 0;
                return ['p1' => '1'];
            });
        $connection->shouldReceive('pipeline')->once()->andReturn($connection);
        $connection->shouldReceive('exists')->once()->andReturn($connection);
        $connection->shouldReceive('exec')->once()->andReturn([1]);
        $connection->shouldReceive('hLen')
            ->once()
            ->with('prefix:_any:tag:posts:entries')
            ->andReturn(1);

        // Third tag: comments - 3 fields, all orphans (hash becomes empty)
        $connection->shouldReceive('hScan')
            ->once()
            ->with('prefix:_any:tag:comments:entries', m::any(), '*', m::any())
            ->andReturnUsing(function ($tagHash, &$iterator) {
                $iterator = 0;
                return ['c1' => '1', 'c2' => '1', 'c3' => '1'];
            });
        $connection->shouldReceive('pipeline')->once()->andReturn($connection);
        $connection->shouldReceive('exists')->times(3)->andReturn($connection);
        $connection->shouldReceive('exec')->once()->andReturn([0, 0, 0]);
        $connection->shouldReceive('hDel')
            ->once()
            ->with('prefix:_any:tag:comments:entries', 'c1', 'c2', 'c3')
            ->andReturn(3);
        $connection->shouldReceive('hLen')
            ->once()
            ->with('prefix:_any:tag:comments:entries')
            ->andReturn(0);
        $connection->shouldReceive('del')
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

        $connection->shouldReceive('zRemRangeByScore')
            ->once()
            ->with('custom:_any:tag:registry', '-inf', m::type('string'))
            ->andReturn(0);

        $connection->shouldReceive('zRange')
            ->once()
            ->with('custom:_any:tag:registry', 0, -1)
            ->andReturn(['users']);

        // Verify correct tag hash key format
        $connection->shouldReceive('hScan')
            ->once()
            ->with('custom:_any:tag:users:entries', m::any(), '*', m::any())
            ->andReturnUsing(function ($tagHash, &$iterator) {
                $iterator = 0;
                return [];
            });

        $connection->shouldReceive('hLen')
            ->once()
            ->with('custom:_any:tag:users:entries')
            ->andReturn(0);

        $connection->shouldReceive('del')
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
        [$store, , $connection] = $this->createClusterStore(tagMode: 'any');

        // Should NOT use pipeline in cluster mode
        $connection->shouldNotReceive('pipeline');

        $connection->shouldReceive('zRemRangeByScore')
            ->once()
            ->andReturn(0);

        $connection->shouldReceive('zRange')
            ->once()
            ->andReturn(['users']);

        $connection->shouldReceive('hScan')
            ->once()
            ->andReturnUsing(function ($tagHash, &$iterator) {
                $iterator = 0;
                return ['key1' => '1', 'key2' => '1'];
            });

        // Sequential EXISTS checks in cluster mode
        $connection->shouldReceive('exists')
            ->once()
            ->with('prefix:key1')
            ->andReturn(1);
        $connection->shouldReceive('exists')
            ->once()
            ->with('prefix:key2')
            ->andReturn(0);

        $connection->shouldReceive('hDel')
            ->once()
            ->with('prefix:_any:tag:users:entries', 'key2')
            ->andReturn(1);

        $connection->shouldReceive('hLen')
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

        $connection->shouldReceive('zRemRangeByScore')
            ->once()
            ->andReturn(0);

        $connection->shouldReceive('zRange')
            ->once()
            ->andReturn(['users']);

        // HSCAN returns empty (no fields in hash)
        $connection->shouldReceive('hScan')
            ->once()
            ->andReturnUsing(function ($tagHash, &$iterator) {
                $iterator = 0;
                return [];
            });

        // Should still check HLEN
        $connection->shouldReceive('hLen')
            ->once()
            ->andReturn(0);

        $connection->shouldReceive('del')
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

        $store = $this->createStoreWithFakeClient($fakeClient, tagMode: 'any');

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

        $connection->shouldReceive('zRemRangeByScore')
            ->once()
            ->andReturn(0);

        $connection->shouldReceive('zRange')
            ->once()
            ->andReturn(['users']);

        // HSCAN should use custom count
        $connection->shouldReceive('hScan')
            ->once()
            ->with(m::any(), m::any(), '*', 500)
            ->andReturnUsing(function ($tagHash, &$iterator) {
                $iterator = 0;
                return [];
            });

        $connection->shouldReceive('hLen')
            ->once()
            ->andReturn(0);

        $connection->shouldReceive('del')
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

        $connection->shouldReceive('zRemRangeByScore')
            ->once()
            ->andReturn(0);

        $connection->shouldReceive('zRange')
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

        // 5 expired tags removed
        $connection->shouldReceive('zRemRangeByScore')
            ->once()
            ->with('prefix:_any:tag:registry', '-inf', m::type('string'))
            ->andReturn(5);

        $connection->shouldReceive('zRange')
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

        $connection->shouldReceive('zRemRangeByScore')
            ->once()
            ->andReturn(0);

        $connection->shouldReceive('zRange')
            ->once()
            ->andReturn(['users']);

        $connection->shouldReceive('hScan')
            ->once()
            ->andReturnUsing(function ($tagHash, &$iterator) {
                $iterator = 0;
                return ['key1' => '1', 'key2' => '1', 'key3' => '1'];
            });

        $connection->shouldReceive('pipeline')->once()->andReturn($connection);
        $connection->shouldReceive('exists')->times(3)->andReturn($connection);
        $connection->shouldReceive('exec')
            ->once()
            ->andReturn([1, 1, 1]); // All keys exist

        // Should NOT call hDel since no orphans
        $connection->shouldNotReceive('hDel');

        $connection->shouldReceive('hLen')
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
