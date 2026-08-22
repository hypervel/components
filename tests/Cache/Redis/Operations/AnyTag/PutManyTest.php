<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis\Operations\AnyTag;

use Hypervel\Tests\Cache\Redis\RedisCacheTestCase;

/**
 * Tests for the PutMany operation (union tags).
 */
class PutManyTest extends RedisCacheTestCase
{
    /**
     * @test
     */
    public function testPutManyWithTagsStoresMultipleItems(): void
    {
        $connection = $this->mockConnection();

        // Standard mode uses pipeline() not multi()
        $connection->shouldReceive('pipeline')->twice()->andReturn($connection);

        // First pipeline for getting old tags (smembers)
        $connection->shouldReceive('smembers')->twice()->andReturn($connection);
        $connection->shouldReceive('exec')->twice()->andReturn([[], []], []);

        // Second pipeline for setex, reverse index updates, and tag hashes
        $connection->shouldReceive('setex')->twice()->andReturn($connection);
        $connection->shouldReceive('del')->twice()->andReturn($connection);
        $connection->shouldReceive('sadd')->twice()->andReturn($connection);
        $connection->shouldReceive('expire')->twice()->andReturn($connection);

        $connection->shouldReceive('hsetex')
            ->once()
            ->with(
                'prefix:_any:tag:users:entries',
                ['foo' => '1', 'baz' => '1'],
                ['EX' => 60],
            )
            ->andReturn($connection);
        $connection->shouldNotReceive('hSet');
        $connection->shouldNotReceive('hexpire');

        // zadd for registry
        $connection->shouldReceive('zadd')->andReturn($connection);

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
        [$redis, , $connection] = $this->createClusterStore(tagMode: 'any');

        $connection->shouldReceive('smembers')->twice()->andReturn([]);
        $connection->shouldReceive('setex')->twice()->andReturnTrue();
        $connection->shouldReceive('multi')->twice()->andReturn($connection);
        $connection->shouldReceive('del')->twice()->andReturn($connection);
        $connection->shouldReceive('sadd')->twice()->andReturn($connection);
        $connection->shouldReceive('expire')->twice()->andReturn($connection);
        $connection->shouldReceive('exec')->twice()->andReturn([]);
        $connection->shouldReceive('hsetex')
            ->once()
            ->with(
                'prefix:_any:tag:users:entries',
                ['foo' => '1', 'baz' => '1'],
                ['EX' => 60],
            )
            ->andReturnTrue();
        $connection->shouldNotReceive('hSet');
        $connection->shouldNotReceive('hexpire');
        $connection->shouldReceive('zadd')->once()->andReturn(1);

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

        $connection->shouldReceive('pipeline')->twice()->andReturn($connection);
        $connection->shouldReceive('smembers')->once()->with('prefix:foo:_any:tags')->andReturn($connection);
        $connection->shouldReceive('exec')->twice()->andReturn([[]], []);
        $connection->shouldReceive('setex')->once()->with('prefix:foo', 1, serialize('bar'))->andReturn($connection);
        $connection->shouldReceive('del')->once()->with('prefix:foo:_any:tags')->andReturn($connection);
        $connection->shouldReceive('sadd')->once()->with('prefix:foo:_any:tags', 'users')->andReturn($connection);
        $connection->shouldReceive('expire')->once()->with('prefix:foo:_any:tags', 1)->andReturn($connection);
        $connection->shouldReceive('hsetex')
            ->once()
            ->with('prefix:_any:tag:users:entries', ['foo' => '1'], ['EX' => 1])
            ->andReturn($connection);
        $connection->shouldReceive('zadd')
            ->once()
            ->withArgs(function (string $key, array $options, int $expiresAt, string $tag) use ($startedAt): bool {
                $this->assertSame('prefix:_any:tag:registry', $key);
                $this->assertSame(['GT'], $options);
                $this->assertGreaterThan($startedAt, $expiresAt);
                $this->assertSame('users', $tag);

                return true;
            })
            ->andReturn($connection);

        $redis = $this->createStore($connection, tagMode: 'any');

        $this->assertTrue($redis->anyTagOps()->putMany()->execute(['foo' => 'bar'], -60, ['users']));
    }
}
