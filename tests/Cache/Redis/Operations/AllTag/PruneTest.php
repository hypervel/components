<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis\Operations\AllTag;

use Hypervel\Cache\Redis\Operations\AllTag\Prune;
use Hypervel\Tests\Cache\Redis\RedisCacheTestCase;
use Hypervel\Tests\Redis\Fixtures\FakeRedisClient;
use Hypervel\Tests\Redis\Fixtures\FakeRedisClusterClient;
use Hypervel\Tests\Redis\Fixtures\PhpRedisClusterConnectionStub;
use RedisCluster;

class PruneTest extends RedisCacheTestCase
{
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

    public function testPruneProcessesDuplicateScanResults(): void
    {
        $fakeClient = new FakeRedisClient(
            scanResults: [
                ['keys' => ['_all:tag:users:entries', '_all:tag:posts:entries'], 'iterator' => 100],
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

        $this->assertSame(2, $fakeClient->getScanCallCount());
        $this->assertSame(4, $result['tags_scanned']);
    }

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

    public function testPrunePreservesForeverItems(): void
    {
        $fakeClient = new FakeRedisClient(
            scanResults: [
                ['keys' => ['_all:tag:users:entries'], 'iterator' => 0],
            ],
            zRemRangeByScoreResults: [
                '_all:tag:users:entries' => 0,
            ],
            zScanResults: [
                '_all:tag:users:entries' => [['members' => [], 'iterator' => 0]],
            ],
            zCardResults: [
                '_all:tag:users:entries' => 5,
            ],
        );

        $store = $this->createStoreWithFakeClient($fakeClient);
        $beforePrune = time();
        $result = (new Prune($store->getContext()))->execute();

        $expiryRemoval = $fakeClient->getZRemRangeByScoreCalls()[0];
        $this->assertSame('_all:tag:users:entries', $expiryRemoval['key']);
        $this->assertSame('0', $expiryRemoval['min']);
        $this->assertGreaterThanOrEqual($beforePrune, (int) $expiryRemoval['max']);
        $this->assertLessThanOrEqual(time(), (int) $expiryRemoval['max']);
        $this->assertSame(0, $result['stale_entries_removed']);
        $this->assertSame(0, $result['empty_sets_deleted']);
    }

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
            zCardResults: [
                '_all:tag:users:entries' => 2, // 2 remaining after orphan removal
            ],
            evalShaResults: [1],
        );

        $store = $this->createStoreWithFakeClient($fakeClient);
        $operation = new Prune($store->getContext());

        $result = $operation->execute();

        $this->assertSame(3, $result['entries_checked']);
        $this->assertSame(1, $result['orphans_removed']); // key2 was orphaned

        $evalCall = $fakeClient->getEvalShaCalls()[0];
        $this->assertSame(4, $evalCall['num_keys']);
        $this->assertSame([
            '_all:tag:users:entries',
            'prefix:key1',
            'prefix:key2',
            'prefix:key3',
            'key1',
            'key2',
            'key3',
        ], $evalCall['args']);
    }

    public function testPruneContinuesAfterAnEmptyNonterminalMemberPage(): void
    {
        $fakeClient = new FakeRedisClient(
            scanResults: [
                ['keys' => ['_all:tag:users:entries'], 'iterator' => 0],
            ],
            zRemRangeByScoreResults: [
                '_all:tag:users:entries' => 0,
            ],
            zScanResults: [
                '_all:tag:users:entries' => [
                    ['members' => [], 'iterator' => 42],
                    ['members' => ['key1' => 1234567890.0], 'iterator' => 0],
                ],
            ],
            zCardResults: [
                '_all:tag:users:entries' => 1,
            ],
            evalShaResults: [0],
        );

        $store = $this->createStoreWithFakeClient($fakeClient);
        $operation = new Prune($store->getContext());

        $result = $operation->execute();

        $this->assertSame(1, $result['entries_checked']);
        $this->assertCount(2, $fakeClient->getZScanCalls());
    }

    public function testClusterPruneBatchesOrphansThatRemainRemoved(): void
    {
        $client = new PruneRedisClusterClient(
            ['live' => 1.0, 'orphan1' => 2.0, 'orphan2' => 3.0],
            ['prefix:live' => [1], 'prefix:orphan1' => [0, 0], 'prefix:orphan2' => [0, 0]],
            removed: 2,
        );
        $connection = (new PhpRedisClusterConnectionStub)->setActiveConnection($client);
        $store = $this->createStore($connection);

        $result = (new Prune($store->getContext()))->execute();

        $this->assertSame(3, $result['entries_checked']);
        $this->assertSame(2, $result['orphans_removed']);
        $this->assertSame([
            ['zRemRangeByScore', 'prefix:_all:tag:users:entries'],
            ['zscan', 'prefix:_all:tag:users:entries', null, '*', 1000],
            ['exists', 'prefix:live'],
            ['exists', 'prefix:orphan1'],
            ['exists', 'prefix:orphan2'],
            ['zrem', 'prefix:_all:tag:users:entries', 'orphan1', 'orphan2'],
            ['exists', 'prefix:orphan1'],
            ['exists', 'prefix:orphan2'],
            ['zCard', 'prefix:_all:tag:users:entries'],
        ], $client->commands);
        $this->assertCount(1, $client->getScanCalls());
        $this->assertSame(1, $client->mastersRequested);
    }

    public function testClusterPruneRepairsMembershipWithoutOverwritingAWriterScore(): void
    {
        $client = new PruneRedisClusterClient(['raced' => 1.0], ['prefix:raced' => [0, 1]], removed: 1);
        $connection = (new PhpRedisClusterConnectionStub)->setActiveConnection($client);
        $store = $this->createStore($connection);

        $result = (new Prune($store->getContext()))->execute();

        $this->assertSame(0, $result['orphans_removed']);
        $this->assertSame([
            ['zRemRangeByScore', 'prefix:_all:tag:users:entries'],
            ['zscan', 'prefix:_all:tag:users:entries', null, '*', 1000],
            ['exists', 'prefix:raced'],
            ['zrem', 'prefix:_all:tag:users:entries', 'raced'],
            ['exists', 'prefix:raced'],
            ['zadd', 'prefix:_all:tag:users:entries', ['NX'], -1, 'raced'],
            ['zCard', 'prefix:_all:tag:users:entries'],
        ], $client->commands);
        $this->assertCount(1, $client->getScanCalls());
        $this->assertSame(1, $client->mastersRequested);
    }

    public function testClusterPruneRepairsMembershipAfterConcurrentRemovalReturnsZero(): void
    {
        $client = new PruneRedisClusterClient(['raced' => 1.0], ['prefix:raced' => [0, 1]], removed: 0);
        $connection = (new PhpRedisClusterConnectionStub)->setActiveConnection($client);
        $store = $this->createStore($connection);

        $result = (new Prune($store->getContext()))->execute();

        $this->assertSame(0, $result['orphans_removed']);
        $this->assertSame([
            ['zRemRangeByScore', 'prefix:_all:tag:users:entries'],
            ['zscan', 'prefix:_all:tag:users:entries', null, '*', 1000],
            ['exists', 'prefix:raced'],
            ['zrem', 'prefix:_all:tag:users:entries', 'raced'],
            ['exists', 'prefix:raced'],
            ['zadd', 'prefix:_all:tag:users:entries', ['NX'], -1, 'raced'],
            ['zCard', 'prefix:_all:tag:users:entries'],
        ], $client->commands);
        $this->assertCount(1, $client->getScanCalls());
        $this->assertSame(1, $client->mastersRequested);
    }

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
}

/**
 * Simulate changing key existence while preserving native scan cursor updates.
 */
class PruneRedisClusterClient extends FakeRedisClusterClient
{
    public array $commands = [];

    /**
     * Configure the members and successive existence checks for one tag.
     */
    public function __construct(private array $members, private array $existence, private int $removed)
    {
        parent::__construct(
            masters: [['127.0.0.1', 7000]],
            scanResults: ['127.0.0.1:7000' => [['keys' => ['prefix:_all:tag:users:entries'], 'iterator' => 0]]],
        );
    }

    /**
     * Return the tag members and finish the scan.
     */
    public function zscan(string $key, int|string|null &$iterator, ?string $pattern = null, int $count = 0): RedisCluster|array|false
    {
        $this->commands[] = ['zscan', $key, $iterator, $pattern, $count];
        $iterator = 0;

        return $this->members;
    }

    /**
     * Return the next existence result for the key.
     */
    public function exists(mixed $key, mixed ...$other_keys): RedisCluster|int|bool
    {
        $this->commands[] = ['exists', $key, ...$other_keys];

        return array_shift($this->existence[$key]);
    }

    /**
     * Record the batched removal and return its configured result.
     */
    public function zrem(mixed $key, mixed $member, mixed ...$other_members): RedisCluster|int|false
    {
        $this->commands[] = ['zrem', $key, $member, ...$other_members];

        return $this->removed;
    }

    /**
     * Record the membership repair without replacing the writer's score.
     */
    public function zadd(string $key, array|float $score_or_options, mixed ...$more_scores_and_mems): RedisCluster|int|float|false
    {
        $this->commands[] = ['zadd', $key, $score_or_options, ...$more_scores_and_mems];

        return 0;
    }

    /**
     * Keep the tag set nonempty after pruning.
     */
    public function zCard(string $key): RedisCluster|int|false
    {
        $this->commands[] = ['zCard', $key];

        return 1;
    }

    /**
     * Keep the configured members available for orphan checks.
     */
    public function zRemRangeByScore(string $key, string $min, string $max): RedisCluster|int|false
    {
        $this->commands[] = ['zRemRangeByScore', $key];

        return 0;
    }
}
