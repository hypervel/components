<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis\Operations\AnyTag;

use Hypervel\Cache\Redis\Operations\AnyTag\RemoveEmptyTags;
use Hypervel\Cache\Redis\Support\StoreContext;
use Hypervel\Support\ClassInvoker;
use Hypervel\Tests\Cache\Redis\RedisCacheTestCase;
use Hypervel\Tests\Redis\Fixtures\FakeRedisClient;
use Hypervel\Tests\Redis\Fixtures\FakeRedisClusterClient;
use Hypervel\Tests\Redis\Fixtures\PhpRedisClusterConnectionStub;
use JMac\Testing\Matching\Argument;
use RedisCluster;

class PruneTest extends RedisCacheTestCase
{
    public function testPruneReturnsEmptyStatsWhenNoActiveTagsRemain(): void
    {
        $connection = $this->mockConnection();
        $connection->client()->expects('zRemRangeByScore')
            ->with('prefix:_any:tag:registry', '-inf', Argument::type('string'))
            ->returns(2);
        $connection->client()->expects('zRange')
            ->with('prefix:_any:tag:registry', 0, -1)
            ->returns([]);

        $store = $this->createStore($connection);
        $store->setTagMode('any');

        $this->assertSame([
            'hashes_scanned' => 0,
            'fields_checked' => 0,
            'orphans_removed' => 0,
            'empty_hashes_deleted' => 0,
            'expired_tags_removed' => 2,
            'orphaned_tags_removed' => 0,
        ], $store->anyTagOps()->prune()->execute());
    }

    public function testPruneRemovesOrphansAtomicallyInBoundedPages(): void
    {
        $tagKey = 'prefix:_any:tag:users:entries';
        $client = new FakeRedisClient(
            hScanResults: [$tagKey => [['fields' => ['key1' => '1', 'key2' => '1', 'key3' => '1'], 'iterator' => 0]]],
            zRangeResults: ['prefix:_any:tag:registry' => ['users']],
            evalShaResults: [1, [0, 0]],
        );
        $store = $this->createStoreWithFakeClient($client, tagMode: 'any');
        $operation = $store->anyTagOps()->prune();

        $result = $operation->execute(37);

        $this->assertCount(1, $client->getZRemRangeByScoreCalls());
        $this->assertSame(1, $client->getZRangeCallCount());
        $this->assertSame(1, $result['hashes_scanned']);
        $this->assertSame(3, $result['fields_checked']);
        $this->assertSame(1, $result['orphans_removed']);
        $this->assertSame(0, $result['empty_hashes_deleted']);
        $this->assertSame(0, $result['orphaned_tags_removed']);
        $this->assertSame([
            ['key' => $tagKey, 'iterator' => null, 'pattern' => '*', 'count' => 37],
        ], $client->getHScanCalls());
        $calls = $client->getEvalShaCalls();
        $this->assertCount(2, $calls);
        $this->assertSame([$tagKey, 'prefix:key1', 'prefix:key2', 'prefix:key3', 'key1', 'key2', 'key3'], $calls[0]['args']);
        $this->assertSame(4, $calls[0]['num_keys']);
        $this->assertSame(['prefix:_any:tag:registry', $tagKey, 'users'], $calls[1]['args']);
        $this->assertSame(2, $calls[1]['num_keys']);
        $script = (new ClassInvoker($operation))->removeOrphanedFieldsScript();
        $this->assertStringContainsString("redis.call('EXISTS', KEYS[i])", $script);
        $this->assertSame(sha1($script), $calls[0]['sha']);
        $script = (new ClassInvoker(new RemoveEmptyTags($store->getContext())))->removeEmptyTagsScript();
        $this->assertStringContainsString("redis.call('ZREM', KEYS[1], ARGV[i - 1])", $script);
        $this->assertSame(sha1($script), $calls[1]['sha']);
    }

    public function testPruneRemovesAnEmptyHashFromTheRegistry(): void
    {
        $client = new FakeRedisClient(
            hScanResults: [
                'prefix:_any:tag:forever-tag:entries' => [['fields' => ['orphan' => '1'], 'iterator' => 0]],
            ],
            zRangeResults: ['prefix:_any:tag:registry' => ['forever-tag']],
            evalShaResults: [1, [1, 1]],
        );
        $store = $this->createStoreWithFakeClient($client, tagMode: 'any');
        $result = $store->anyTagOps()->prune()->execute();

        $this->assertCount(1, $client->getZRemRangeByScoreCalls());
        $this->assertSame(1, $client->getZRangeCallCount());
        $this->assertSame(1, $result['orphans_removed']);
        $this->assertSame(1, $result['empty_hashes_deleted']);
        $this->assertSame(1, $result['orphaned_tags_removed']);
        $this->assertCount(1, $client->getHScanCalls());
        $calls = $client->getEvalShaCalls();
        $this->assertCount(2, $calls);
        $this->assertSame(['prefix:_any:tag:registry', 'prefix:_any:tag:forever-tag:entries', 'forever-tag'], $calls[1]['args']);
        $this->assertSame(2, $calls[1]['num_keys']);
        $this->assertSame([], $client->getDelCalls());
    }

    public function testClusterPruneBatchesOrphansThatRemainRemoved(): void
    {
        $client = new PruneRedisClusterClient(
            ['live' => '1', 'orphan1' => '1', 'orphan2' => '1'],
            ['prefix:live' => [1], 'prefix:orphan1' => [0, 0], 'prefix:orphan2' => [0, 0]],
            removed: 2,
        );
        $connection = (new PhpRedisClusterConnectionStub)->setActiveConnection($client);
        $store = $this->createStore($connection, tagMode: 'any');

        $result = $store->anyTagOps()->prune()->execute();

        $this->assertSame(3, $result['fields_checked']);
        $this->assertSame(2, $result['orphans_removed']);
        $this->assertSame([
            ['zRemRangeByScore', 'prefix:_any:tag:registry'],
            ['zRange', 'prefix:_any:tag:registry'],
            ['hscan', 'prefix:_any:tag:users:entries', null, '*', 1000],
            ['exists', 'prefix:live'],
            ['exists', 'prefix:orphan1'],
            ['exists', 'prefix:orphan2'],
            ['hDel', 'prefix:_any:tag:users:entries', 'orphan1', 'orphan2'],
            ['exists', 'prefix:orphan1'],
            ['exists', 'prefix:orphan2'],
            ['hLen', 'prefix:_any:tag:users:entries'],
        ], $client->commands);
    }

    public function testClusterPruneRepairsAFieldOnlyWhenItIsStillMissing(): void
    {
        $client = new PruneRedisClusterClient(['raced' => '1'], ['prefix:raced' => [0, 1]], removed: 1);
        $connection = (new PhpRedisClusterConnectionStub)->setActiveConnection($client);
        $store = $this->createStore($connection, tagMode: 'any');

        $result = $store->anyTagOps()->prune()->execute();

        $this->assertSame(0, $result['orphans_removed']);
        $this->assertSame([
            ['zRemRangeByScore', 'prefix:_any:tag:registry'],
            ['zRange', 'prefix:_any:tag:registry'],
            ['hscan', 'prefix:_any:tag:users:entries', null, '*', 1000],
            ['exists', 'prefix:raced'],
            ['hDel', 'prefix:_any:tag:users:entries', 'raced'],
            ['exists', 'prefix:raced'],
            ['sismember', 'prefix:raced:_any:tags', 'users'],
            ['hsetnx', 'prefix:_any:tag:users:entries', 'raced', StoreContext::TAG_FIELD_VALUE],
            ['hLen', 'prefix:_any:tag:users:entries'],
        ], $client->commands);
    }

    public function testClusterPruneRepairsAFieldAfterConcurrentRemovalReturnsZero(): void
    {
        $client = new PruneRedisClusterClient(['raced' => '1'], ['prefix:raced' => [0, 1]], removed: 0);
        $connection = (new PhpRedisClusterConnectionStub)->setActiveConnection($client);
        $store = $this->createStore($connection, tagMode: 'any');

        $result = $store->anyTagOps()->prune()->execute();

        $this->assertSame(0, $result['orphans_removed']);
        $this->assertSame([
            ['zRemRangeByScore', 'prefix:_any:tag:registry'],
            ['zRange', 'prefix:_any:tag:registry'],
            ['hscan', 'prefix:_any:tag:users:entries', null, '*', 1000],
            ['exists', 'prefix:raced'],
            ['hDel', 'prefix:_any:tag:users:entries', 'raced'],
            ['exists', 'prefix:raced'],
            ['sismember', 'prefix:raced:_any:tags', 'users'],
            ['hsetnx', 'prefix:_any:tag:users:entries', 'raced', StoreContext::TAG_FIELD_VALUE],
            ['hLen', 'prefix:_any:tag:users:entries'],
        ], $client->commands);
    }

    public function testClusterPruneRepairsARegistryEntryWithoutOverwritingAWriterScore(): void
    {
        $client = new PruneRedisClusterClient([], [], removed: 0, lengths: [0, 1]);
        $connection = (new PhpRedisClusterConnectionStub)->setActiveConnection($client);
        $store = $this->createStore($connection, tagMode: 'any');

        $result = $store->anyTagOps()->prune()->execute();

        $this->assertSame(0, $result['empty_hashes_deleted']);
        $this->assertSame(0, $result['orphaned_tags_removed']);
        $this->assertSame([
            ['zRemRangeByScore', 'prefix:_any:tag:registry'],
            ['zRange', 'prefix:_any:tag:registry'],
            ['hscan', 'prefix:_any:tag:users:entries', null, '*', 1000],
            ['hLen', 'prefix:_any:tag:users:entries'],
            ['zrem', 'prefix:_any:tag:registry', 'users'],
            ['hLen', 'prefix:_any:tag:users:entries'],
            ['zadd', 'prefix:_any:tag:registry', ['NX'], StoreContext::MAX_EXPIRY, 'users'],
        ], $client->commands);
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
        $result = $store->anyTagOps()->prune()->execute();

        $this->assertSame(3, $fakeClient->getHScanCallCount());
        $this->assertCount(3, $fakeClient->getEvalShaCalls());
        $this->assertSame(2, $result['fields_checked']);
        $this->assertSame(1, $result['orphans_removed']);
    }
}

/**
 * Simulate concurrent hash changes while preserving HSCAN's reference parameter.
 */
class PruneRedisClusterClient extends FakeRedisClusterClient
{
    public array $commands = [];

    /**
     * Configure hash fields and successive existence and length checks.
     */
    public function __construct(private array $fields, private array $existence, private int $removed, private array $lengths = [1])
    {
        parent::__construct();
    }

    /**
     * Return the hash fields and finish scanning.
     */
    public function hscan(string $key, int|string|null &$iterator, ?string $pattern = null, int $count = 0): array|bool
    {
        $this->commands[] = ['hscan', $key, $iterator, $pattern, $count];
        $iterator = 0;

        return $this->fields;
    }

    /**
     * Return the next existence result.
     */
    public function exists(mixed $key, mixed ...$other_keys): RedisCluster|int|bool
    {
        $this->commands[] = ['exists', $key, ...$other_keys];

        return array_shift($this->existence[$key]);
    }

    /**
     * Record the batched field removal.
     */
    public function hDel(string $key, string $field, string ...$other_fields): RedisCluster|int|false
    {
        $this->commands[] = ['hDel', $key, $field, ...$other_fields];

        return $this->removed;
    }

    /**
     * Retain the raced value's tag in its reverse index.
     */
    public function sismember(string $key, mixed $value): RedisCluster|bool
    {
        $this->commands[] = ['sismember', $key, $value];

        return true;
    }

    /**
     * Record the repair without replacing the writer's field.
     */
    public function hsetnx(string $key, string $member, mixed $value): RedisCluster|bool
    {
        $this->commands[] = ['hsetnx', $key, $member, $value];

        return false;
    }

    /**
     * Return the next hash length.
     */
    public function hLen(string $key): RedisCluster|int|false
    {
        $this->commands[] = ['hLen', $key];

        return array_shift($this->lengths);
    }

    /**
     * Record tag deregistration.
     */
    public function zrem(mixed $key, mixed $member, mixed ...$other_members): RedisCluster|int|false
    {
        $this->commands[] = ['zrem', $key, $member, ...$other_members];

        return 1;
    }

    /**
     * Record registry repair without replacing the writer's score.
     */
    public function zadd(string $key, array|float $score_or_options, mixed ...$more_scores_and_mems): RedisCluster|int|float|false
    {
        $this->commands[] = ['zadd', $key, $score_or_options, ...$more_scores_and_mems];

        return 0;
    }

    /**
     * Keep the configured tag active.
     */
    public function zRemRangeByScore(string $key, string $min, string $max): RedisCluster|int|false
    {
        $this->commands[] = ['zRemRangeByScore', $key];

        return 0;
    }

    /**
     * Return the active tag.
     */
    public function zRange(string $key, mixed $start, mixed $end, array|bool|null $options = null): RedisCluster|array|bool
    {
        $this->commands[] = ['zRange', $key];

        return ['users'];
    }
}
