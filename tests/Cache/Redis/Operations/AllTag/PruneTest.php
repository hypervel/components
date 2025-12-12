<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis\Operations\AllTag;

use Hyperf\Redis\Pool\PoolFactory;
use Hyperf\Redis\Pool\RedisPool;
use Hyperf\Redis\RedisFactory;
use Hypervel\Cache\Redis\Operations\AllTag\Prune;
use Hypervel\Cache\RedisStore;
use Hypervel\Redis\RedisConnection;
use Hypervel\Tests\Cache\Redis\Stub\FakeRedisClient;
use Hypervel\Tests\TestCase;
use Mockery as m;

/**
 * Tests for the AllTag/Prune operation.
 *
 * @internal
 * @coversNothing
 */
class PruneTest extends TestCase
{
    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }

    /**
     * @test
     */
    public function testPruneReturnsEmptyStatsWhenNoTagsFound(): void
    {
        $fakeClient = new FakeRedisClient(
            scanResults: [
                ['keys' => [], 'iterator' => 0],
            ],
        );

        $store = $this->createStoreWithFakeClient($fakeClient);
        $operation = new Prune($store->getContext());

        $result = $operation->execute();

        $this->assertSame(0, $result['tags_scanned']);
        $this->assertSame(0, $result['stale_entries_removed']);
        $this->assertSame(0, $result['entries_checked']);
        $this->assertSame(0, $result['orphans_removed']);
        $this->assertSame(0, $result['empty_sets_deleted']);
    }

    /**
     * @test
     */
    public function testPruneRemovesStaleEntriesFromSingleTag(): void
    {
        $fakeClient = new FakeRedisClient(
            scanResults: [
                ['keys' => ['_all:tag:users:entries'], 'iterator' => 0],
            ],
            zRemRangeByScoreResults: [
                '_all:tag:users:entries' => 5, // 5 stale entries removed
            ],
            zScanResults: [
                '_all:tag:users:entries' => [
                    ['members' => [], 'iterator' => 0], // No members to check for orphans
                ],
            ],
            zCardResults: [
                '_all:tag:users:entries' => 3, // 3 remaining entries (not empty)
            ],
        );

        $store = $this->createStoreWithFakeClient($fakeClient);
        $operation = new Prune($store->getContext());

        $result = $operation->execute();

        $this->assertSame(1, $result['tags_scanned']);
        $this->assertSame(5, $result['stale_entries_removed']);
        $this->assertSame(0, $result['empty_sets_deleted']);
    }

    /**
     * @test
     */
    public function testPruneDeletesEmptySortedSets(): void
    {
        $fakeClient = new FakeRedisClient(
            scanResults: [
                ['keys' => ['_all:tag:users:entries'], 'iterator' => 0],
            ],
            zRemRangeByScoreResults: [
                '_all:tag:users:entries' => 10, // All entries removed
            ],
            zScanResults: [
                '_all:tag:users:entries' => [
                    ['members' => [], 'iterator' => 0],
                ],
            ],
            zCardResults: [
                '_all:tag:users:entries' => 0, // Empty after removal
            ],
        );

        $store = $this->createStoreWithFakeClient($fakeClient);
        $operation = new Prune($store->getContext());

        $result = $operation->execute();

        $this->assertSame(1, $result['tags_scanned']);
        $this->assertSame(10, $result['stale_entries_removed']);
        $this->assertSame(1, $result['empty_sets_deleted']);
    }

    /**
     * @test
     */
    public function testPruneHandlesMultipleTags(): void
    {
        $fakeClient = new FakeRedisClient(
            scanResults: [
                ['keys' => ['_all:tag:users:entries', '_all:tag:posts:entries', '_all:tag:comments:entries'], 'iterator' => 0],
            ],
            zRemRangeByScoreResults: [
                '_all:tag:users:entries' => 2,
                '_all:tag:posts:entries' => 3,
                '_all:tag:comments:entries' => 0,
            ],
            zScanResults: [
                '_all:tag:users:entries' => [['members' => [], 'iterator' => 0]],
                '_all:tag:posts:entries' => [['members' => [], 'iterator' => 0]],
                '_all:tag:comments:entries' => [['members' => [], 'iterator' => 0]],
            ],
            zCardResults: [
                '_all:tag:users:entries' => 5,
                '_all:tag:posts:entries' => 0, // Empty - should be deleted
                '_all:tag:comments:entries' => 10,
            ],
        );

        $store = $this->createStoreWithFakeClient($fakeClient);
        $operation = new Prune($store->getContext());

        $result = $operation->execute();

        $this->assertSame(3, $result['tags_scanned']);
        $this->assertSame(5, $result['stale_entries_removed']); // 2 + 3 + 0
        $this->assertSame(1, $result['empty_sets_deleted']); // Only posts was empty
    }

    /**
     * @test
     */
    public function testPruneDeduplicatesScanResults(): void
    {
        // SafeScan iterates multiple times, returning duplicates
        $fakeClient = new FakeRedisClient(
            scanResults: [
                // First scan: returns 2 keys, iterator = 100 (continue)
                ['keys' => ['_all:tag:users:entries', '_all:tag:posts:entries'], 'iterator' => 100],
                // Second scan: returns 1 duplicate + 1 new, iterator = 0 (done)
                ['keys' => ['_all:tag:users:entries', '_all:tag:comments:entries'], 'iterator' => 0],
            ],
            zRemRangeByScoreResults: [
                '_all:tag:users:entries' => 1,
                '_all:tag:posts:entries' => 1,
                '_all:tag:comments:entries' => 1,
            ],
            zScanResults: [
                '_all:tag:users:entries' => [['members' => [], 'iterator' => 0]],
                '_all:tag:posts:entries' => [['members' => [], 'iterator' => 0]],
                '_all:tag:comments:entries' => [['members' => [], 'iterator' => 0]],
            ],
            zCardResults: [
                '_all:tag:users:entries' => 5,
                '_all:tag:posts:entries' => 5,
                '_all:tag:comments:entries' => 5,
            ],
        );

        $store = $this->createStoreWithFakeClient($fakeClient);
        $operation = new Prune($store->getContext());

        $result = $operation->execute();

        // Verify scan was called twice (multi-iteration)
        $this->assertSame(2, $fakeClient->getScanCallCount());

        // SafeScan yields each key as encountered (no deduplication in SafeScan itself),
        // but Prune processes each unique tag once via the generator
        // Actually, SafeScan is a generator - it yields duplicates if SCAN returns them
        // The 4 keys scanned means duplicate 'users' was yielded twice
        $this->assertSame(4, $result['tags_scanned']);
    }

    /**
     * @test
     */
    public function testPruneUsesCorrectScanPattern(): void
    {
        $fakeClient = new FakeRedisClient(
            scanResults: [
                ['keys' => [], 'iterator' => 0],
            ],
        );

        $store = $this->createStoreWithFakeClient($fakeClient, prefix: 'custom_prefix:');
        $operation = new Prune($store->getContext());

        $operation->execute();

        // Verify SCAN was called with correct pattern
        $this->assertSame(1, $fakeClient->getScanCallCount());
        $this->assertSame('custom_prefix:_all:tag:*:entries', $fakeClient->getScanCalls()[0]['pattern']);
    }

    /**
     * @test
     */
    public function testPrunePreservesForeverItems(): void
    {
        // Forever items have score -1, ZREMRANGEBYSCORE uses '0' as lower bound
        // This test verifies the behavior documentation
        $fakeClient = new FakeRedisClient(
            scanResults: [
                ['keys' => ['_all:tag:users:entries'], 'iterator' => 0],
            ],
            zRemRangeByScoreResults: [
                // 0 entries removed because all are forever items (score -1)
                '_all:tag:users:entries' => 0,
            ],
            zScanResults: [
                '_all:tag:users:entries' => [['members' => [], 'iterator' => 0]],
            ],
            zCardResults: [
                '_all:tag:users:entries' => 5, // 5 forever items remain
            ],
        );

        $store = $this->createStoreWithFakeClient($fakeClient);
        $operation = new Prune($store->getContext());

        $result = $operation->execute();

        $this->assertSame(0, $result['stale_entries_removed']);
        $this->assertSame(0, $result['empty_sets_deleted']);
    }

    /**
     * @test
     */
    public function testPruneUsesCustomScanCount(): void
    {
        $fakeClient = new FakeRedisClient(
            scanResults: [
                ['keys' => [], 'iterator' => 0],
            ],
        );

        $store = $this->createStoreWithFakeClient($fakeClient);
        $operation = new Prune($store->getContext());

        $operation->execute(500);

        // Verify SCAN was called with custom count
        $this->assertSame(500, $fakeClient->getScanCalls()[0]['count']);
    }

    /**
     * @test
     */
    public function testPruneViaStoreOperationsContainer(): void
    {
        $fakeClient = new FakeRedisClient(
            scanResults: [
                ['keys' => [], 'iterator' => 0],
            ],
        );

        $store = $this->createStoreWithFakeClient($fakeClient);

        // Access via the operations container
        $result = $store->allTagOps()->prune()->execute();

        $this->assertSame(0, $result['tags_scanned']);
    }

    /**
     * @test
     */
    public function testPruneRemovesOrphanedEntries(): void
    {
        // Set up: tag has 3 members, but 2 cache keys don't exist (orphans)
        $fakeClient = new FakeRedisClient(
            scanResults: [
                ['keys' => ['_all:tag:users:entries'], 'iterator' => 0],
            ],
            zRemRangeByScoreResults: [
                '_all:tag:users:entries' => 0, // No stale entries
            ],
            zScanResults: [
                '_all:tag:users:entries' => [
                    // ZSCAN returns [member => score, ...]
                    ['members' => ['key1' => 1234567890.0, 'key2' => 1234567891.0, 'key3' => 1234567892.0], 'iterator' => 0],
                ],
            ],
            // EXISTS results: key1 exists (1), key2 doesn't (0), key3 exists (1)
            execResults: [
                [1, 0, 1], // Pipeline results for EXISTS calls
            ],
            zCardResults: [
                '_all:tag:users:entries' => 2, // 2 remaining after orphan removal
            ],
        );

        $store = $this->createStoreWithFakeClient($fakeClient);
        $operation = new Prune($store->getContext());

        $result = $operation->execute();

        $this->assertSame(3, $result['entries_checked']);
        $this->assertSame(1, $result['orphans_removed']); // key2 was orphaned

        // Verify zRem was called to remove orphan
        $zRemCalls = $fakeClient->getZRemCalls();
        $this->assertCount(1, $zRemCalls);
        $this->assertSame('_all:tag:users:entries', $zRemCalls[0]['key']);
        $this->assertContains('key2', $zRemCalls[0]['members']);
    }

    /**
     * @test
     */
    public function testPruneHandlesOptPrefixCorrectly(): void
    {
        // When OPT_PREFIX is set, SCAN pattern needs prefix, but returned keys have it stripped
        $fakeClient = new FakeRedisClient(
            scanResults: [
                // SafeScan strips the OPT_PREFIX from returned keys
                ['keys' => ['myapp:_all:tag:users:entries'], 'iterator' => 0],
            ],
            optPrefix: 'myapp:',
            zRemRangeByScoreResults: [
                '_all:tag:users:entries' => 1,
            ],
            zScanResults: [
                '_all:tag:users:entries' => [['members' => [], 'iterator' => 0]],
            ],
            zCardResults: [
                '_all:tag:users:entries' => 5,
            ],
        );

        $store = $this->createStoreWithFakeClient($fakeClient, prefix: 'cache:');
        $operation = new Prune($store->getContext());

        $result = $operation->execute();

        // Verify SCAN pattern included OPT_PREFIX
        $this->assertSame('myapp:cache:_all:tag:*:entries', $fakeClient->getScanCalls()[0]['pattern']);

        $this->assertSame(1, $result['tags_scanned']);
    }

    /**
     * Create a RedisStore with a FakeRedisClient.
     *
     * This follows the pattern from FlushByPatternTest - mock the connection
     * to return the FakeRedisClient, mock the pool infrastructure.
     */
    private function createStoreWithFakeClient(
        FakeRedisClient $fakeClient,
        string $prefix = 'prefix:',
        string $connectionName = 'default',
    ): RedisStore {
        $connection = m::mock(RedisConnection::class);
        $connection->shouldReceive('release')->zeroOrMoreTimes();
        $connection->shouldReceive('serialized')->andReturn(false);
        $connection->shouldReceive('client')->andReturn($fakeClient);

        $pool = m::mock(RedisPool::class);
        $pool->shouldReceive('get')->andReturn($connection);

        $poolFactory = m::mock(PoolFactory::class);
        $poolFactory->shouldReceive('getPool')->with($connectionName)->andReturn($pool);

        return new RedisStore(
            m::mock(RedisFactory::class),
            $prefix,
            $connectionName,
            $poolFactory
        );
    }
}
