<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis\Operations\AnyTag;

use Hypervel\Tests\Cache\Redis\RedisCacheTestCase;
use JMac\Testing\Matching\Argument;

/**
 * Tests for the PutMany operation (union tags).
 */
class PutManyTest extends RedisCacheTestCase
{
    public function testPutManyWithTagsStoresMultipleItems(): void
    {
        $connection = $this->mockConnection();

        // Standard mode uses pipeline() not multi()
        $connection->client()->expects('pipeline')->times(2)->returns($connection->client());

        // First pipeline for getting old tags (smembers)
        $connection->client()->expects('smembers')->times(2)->returns($connection->client());
        $connection->client()->expects('exec')->times(2)->returns([[], []], []);

        // Second pipeline for setex, reverse index updates, and tag hashes
        $connection->client()->expects('setex')->times(2)->returns($connection->client());
        $connection->client()->expects('del')->times(2)->returns($connection->client());
        $connection->client()->expects('sadd')->times(2)->returns($connection->client());
        $connection->client()->expects('expire')->times(2)->returns($connection->client());

        $connection->client()->expects('hsetex')
            ->with(
                'prefix:_any:tag:users:entries',
                ['foo' => '1', 'baz' => '1'],
                ['EX' => 60],
            )
            ->returns($connection->client());
        $connection->client()->expects('hSet')->never();
        $connection->client()->expects('hexpire')->never();

        // zadd for registry
        $connection->client()->allows('zadd')->returns($connection->client());

        $redis = $this->createStore($connection);
        $redis->setTagMode('any');
        $result = $redis->anyTagOps()->putMany()->execute([
            'foo' => 'bar',
            'baz' => 'qux',
        ], 60, ['users']);
        $this->assertTrue($result);
    }

    public function testPutManyUsesOneBatchedHsetexPerTagInClusterMode(): void
    {
        [$redis, $client] = $this->createClusterStore(tagMode: 'any');

        $client->expects('smembers')->times(2)->returns([]);
        $client->expects('setex')->times(2)->returns(true);
        $client->expects('multi')->times(2)->returns($client);
        $client->expects('del')->times(2)->returns($client);
        $client->expects('sadd')->times(2)->returns($client);
        $client->expects('expire')->times(2)->returns($client);
        $client->expects('exec')->times(2)->returns([]);
        $client->expects('hsetex')
            ->with(
                'prefix:_any:tag:users:entries',
                ['foo' => '1', 'baz' => '1'],
                ['EX' => 60],
            )
            ->returns(2);
        $client->expects('hSet')->never();
        $client->expects('hexpire')->never();
        $client->expects('zadd')->returns(1);

        $result = $redis->anyTagOps()->putMany()->execute([
            'foo' => 'bar',
            'baz' => 'qux',
        ], 60, ['users']);

        $this->assertTrue($result);
    }

    public function testPutManyNormalizesExpiringMetadata(): void
    {
        $connection = $this->mockConnection();
        $startedAt = time();

        $connection->client()->expects('pipeline')->times(2)->returns($connection->client());
        $connection->client()->expects('smembers')->with('prefix:foo:_any:tags')->returns($connection->client());
        $connection->client()->expects('exec')->times(2)->returns([[]], []);
        $connection->client()->expects('setex')->with('prefix:foo', 1, serialize('bar'))->returns($connection->client());
        $connection->client()->expects('del')->with('prefix:foo:_any:tags')->returns($connection->client());
        $connection->client()->expects('sadd')->with('prefix:foo:_any:tags', 'users')->returns($connection->client());
        $connection->client()->expects('expire')->with('prefix:foo:_any:tags', 1)->returns($connection->client());
        $connection->client()->expects('hsetex')
            ->with('prefix:_any:tag:users:entries', ['foo' => '1'], ['EX' => 1])
            ->returns($connection->client());
        $connection->client()->expects('zadd')
            ->with(Argument::all(function (string $key, array $options, int $expiresAt, string $tag) use ($startedAt): bool {
                $this->assertSame('prefix:_any:tag:registry', $key);
                $this->assertSame(['GT'], $options);
                $this->assertGreaterThan($startedAt, $expiresAt);
                $this->assertSame('users', $tag);

                return true;
            }))
            ->returns($connection->client());

        $redis = $this->createStore($connection, tagMode: 'any');

        $this->assertTrue($redis->anyTagOps()->putMany()->execute(['foo' => 'bar'], -60, ['users']));
    }
}
