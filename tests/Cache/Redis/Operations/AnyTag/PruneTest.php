<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis\Operations\AnyTag;

use Hypervel\Cache\Redis\Operations\AnyTag\Prune;
use Hypervel\Cache\Redis\Support\StoreContext;
use Hypervel\Redis\PhpRedis;
use Hypervel\Tests\Cache\Redis\RedisCacheTestCase;
use Hypervel\Tests\Redis\Fixtures\FakeRedisClient;
use Mockery as m;

class PruneTest extends RedisCacheTestCase
{
    public function testPruneReturnsEmptyStatsWhenNoActiveTagsRemain(): void
    {
        $connection = $this->mockConnection();
        $connection->shouldReceive('zRemRangeByScore')
            ->once()
            ->with('prefix:_any:tag:registry', '-inf', m::type('string'))
            ->andReturn(2);
        $connection->shouldReceive('zRange')
            ->once()
            ->with('prefix:_any:tag:registry', 0, -1)
            ->andReturn([]);

        $store = $this->createStore($connection);
        $store->setTagMode('any');

        $this->assertSame([
            'hashes_scanned' => 0,
            'fields_checked' => 0,
            'orphans_removed' => 0,
            'empty_hashes_deleted' => 0,
            'expired_tags_removed' => 2,
            'orphaned_tags_removed' => 0,
        ], (new Prune($store->getContext()))->execute());
    }

    public function testPruneRemovesOrphansAtomicallyInBoundedPages(): void
    {
        $connection = $this->mockConnection();
        $connection->shouldReceive('zRemRangeByScore')->once()->andReturn(0);
        $connection->shouldReceive('zRange')->once()->andReturn(['users']);
        $connection->shouldReceive('hScan')
            ->once()
            ->with('prefix:_any:tag:users:entries', m::any(), '*', 37)
            ->andReturnUsing(function ($tagHash, &$iterator): array {
                $this->assertSame(PhpRedis::initialScanCursor(), $iterator);
                $iterator = 0;

                return ['key1' => '1', 'key2' => '1', 'key3' => '1'];
            });
        $connection->shouldReceive('evalWithShaCache')
            ->once()
            ->with(
                m::on(fn (string $script): bool => str_contains($script, "redis.call('EXISTS', KEYS[i])")),
                [
                    'prefix:_any:tag:users:entries',
                    'prefix:key1',
                    'prefix:key2',
                    'prefix:key3',
                ],
                ['key1', 'key2', 'key3'],
            )
            ->andReturn(1);
        $connection->shouldReceive('evalWithShaCache')
            ->once()
            ->with(
                m::on(fn (string $script): bool => str_contains($script, "redis.call('ZREM', KEYS[2], ARGV[1])")),
                ['prefix:_any:tag:users:entries', 'prefix:_any:tag:registry'],
                ['users'],
            )
            ->andReturn([0, 0]);

        $store = $this->createStore($connection);
        $store->setTagMode('any');
        $result = (new Prune($store->getContext()))->execute(37);

        $this->assertSame(1, $result['hashes_scanned']);
        $this->assertSame(3, $result['fields_checked']);
        $this->assertSame(1, $result['orphans_removed']);
        $this->assertSame(0, $result['empty_hashes_deleted']);
        $this->assertSame(0, $result['orphaned_tags_removed']);
    }

    public function testPruneRemovesAnEmptyHashFromTheRegistry(): void
    {
        $connection = $this->mockConnection();
        $connection->shouldReceive('zRemRangeByScore')->once()->andReturn(0);
        $connection->shouldReceive('zRange')->once()->andReturn(['forever-tag']);
        $connection->shouldReceive('hScan')
            ->once()
            ->andReturnUsing(function ($tagHash, &$iterator): array {
                $iterator = 0;

                return ['orphan' => '1'];
            });
        $connection->shouldReceive('evalWithShaCache')->once()->andReturn(1);
        $connection->shouldReceive('evalWithShaCache')
            ->once()
            ->with(
                m::type('string'),
                ['prefix:_any:tag:forever-tag:entries', 'prefix:_any:tag:registry'],
                ['forever-tag'],
            )
            ->andReturn([1, 1]);
        $connection->shouldNotReceive('del');

        $store = $this->createStore($connection);
        $store->setTagMode('any');
        $result = (new Prune($store->getContext()))->execute();

        $this->assertSame(1, $result['orphans_removed']);
        $this->assertSame(1, $result['empty_hashes_deleted']);
        $this->assertSame(1, $result['orphaned_tags_removed']);
    }

    public function testClusterPruneBatchesOrphansThatRemainRemoved(): void
    {
        [$store, , $connection] = $this->createClusterStore(tagMode: 'any');
        $connection->shouldNotReceive('pipeline');
        $connection->shouldReceive('zRemRangeByScore')->once()->andReturn(0);
        $connection->shouldReceive('zRange')->once()->andReturn(['users']);
        $connection->shouldReceive('hScan')
            ->once()
            ->andReturnUsing(function ($tagHash, &$iterator): array {
                $iterator = 0;

                return ['live' => '1', 'orphan1' => '1', 'orphan2' => '1'];
            });
        $connection->shouldReceive('exists')->once()->with('prefix:live')->andReturn(1);
        $connection->shouldReceive('exists')->twice()->with('prefix:orphan1')->andReturn(0);
        $connection->shouldReceive('exists')->twice()->with('prefix:orphan2')->andReturn(0);
        $connection->shouldReceive('hDel')
            ->once()
            ->with('prefix:_any:tag:users:entries', 'orphan1', 'orphan2')
            ->andReturn(2);
        $connection->shouldReceive('hLen')->once()->andReturn(1);

        $result = (new Prune($store->getContext()))->execute();

        $this->assertSame(3, $result['fields_checked']);
        $this->assertSame(2, $result['orphans_removed']);
    }

    public function testClusterPruneRepairsAFieldOnlyWhenItIsStillMissing(): void
    {
        [$store, , $connection] = $this->createClusterStore(tagMode: 'any');
        $connection->shouldReceive('zRemRangeByScore')->once()->andReturn(0);
        $connection->shouldReceive('zRange')->once()->andReturn(['users']);
        $connection->shouldReceive('hScan')
            ->once()
            ->andReturnUsing(function ($tagHash, &$iterator): array {
                $iterator = 0;

                return ['raced' => '1'];
            });
        $connection->shouldReceive('exists')
            ->twice()
            ->with('prefix:raced')
            ->andReturn(0, 1);
        $connection->shouldReceive('hDel')->once()->andReturn(1);
        $connection->shouldReceive('sismember')
            ->once()
            ->with('prefix:raced:_any:tags', 'users')
            ->andReturn(true);
        $connection->shouldReceive('hsetnx')
            ->once()
            ->with('prefix:_any:tag:users:entries', 'raced', StoreContext::TAG_FIELD_VALUE)
            ->andReturn(false);
        $connection->shouldReceive('hLen')->once()->andReturn(1);

        $result = (new Prune($store->getContext()))->execute();

        $this->assertSame(0, $result['orphans_removed']);
    }

    public function testClusterPruneRepairsAFieldAfterConcurrentRemovalReturnsZero(): void
    {
        [$store, , $connection] = $this->createClusterStore(tagMode: 'any');
        $connection->shouldReceive('zRemRangeByScore')->once()->andReturn(0);
        $connection->shouldReceive('zRange')->once()->andReturn(['users']);
        $connection->shouldReceive('hScan')
            ->once()
            ->andReturnUsing(function ($tagHash, &$iterator): array {
                $iterator = 0;

                return ['raced' => '1'];
            });
        $connection->shouldReceive('exists')
            ->twice()
            ->with('prefix:raced')
            ->andReturn(0, 1);
        $connection->shouldReceive('hDel')->once()->andReturn(0);
        $connection->shouldReceive('sismember')
            ->once()
            ->with('prefix:raced:_any:tags', 'users')
            ->andReturn(true);
        $connection->shouldReceive('hsetnx')
            ->once()
            ->with('prefix:_any:tag:users:entries', 'raced', StoreContext::TAG_FIELD_VALUE)
            ->andReturn(false);
        $connection->shouldReceive('hLen')->once()->andReturn(1);

        $result = (new Prune($store->getContext()))->execute();

        $this->assertSame(0, $result['orphans_removed']);
    }

    public function testClusterPruneRepairsARegistryEntryWithoutOverwritingAWriterScore(): void
    {
        [$store, , $connection] = $this->createClusterStore(tagMode: 'any');
        $connection->shouldReceive('zRemRangeByScore')->once()->andReturn(0);
        $connection->shouldReceive('zRange')->once()->andReturn(['users']);
        $connection->shouldReceive('hScan')
            ->once()
            ->andReturnUsing(function ($tagHash, &$iterator): array {
                $iterator = 0;

                return [];
            });
        $connection->shouldReceive('hLen')->twice()->andReturn(0, 1);
        $connection->shouldReceive('zrem')
            ->once()
            ->with('prefix:_any:tag:registry', 'users')
            ->andReturn(1);
        $connection->shouldReceive('zadd')
            ->once()
            ->with('prefix:_any:tag:registry', ['NX'], StoreContext::MAX_EXPIRY, 'users')
            ->andReturn(0);

        $result = (new Prune($store->getContext()))->execute();

        $this->assertSame(0, $result['empty_hashes_deleted']);
        $this->assertSame(0, $result['orphaned_tags_removed']);
    }

    public function testPruneContinuesAfterEmptyAndNonterminalHashPages(): void
    {
        $registryKey = 'prefix:_any:tag:registry';
        $tagHashKey = 'prefix:_any:tag:users:entries';
        $fakeClient = new FakeRedisClient(
            hScanResults: [
                $tagHashKey => [
                    ['fields' => [], 'iterator' => 100],
                    ['fields' => ['key1' => '1'], 'iterator' => 50],
                    ['fields' => ['key2' => '1'], 'iterator' => 0],
                ],
            ],
            zRangeResults: [
                $registryKey => ['users'],
            ],
            evalShaResults: [1, 0, [0, 0]],
        );

        $store = $this->createStoreWithFakeClient($fakeClient, tagMode: 'any');
        $result = (new Prune($store->getContext()))->execute();

        $this->assertSame(3, $fakeClient->getHScanCallCount());
        $this->assertCount(3, $fakeClient->getEvalShaCalls());
        $this->assertSame(2, $result['fields_checked']);
        $this->assertSame(1, $result['orphans_removed']);
    }
}
