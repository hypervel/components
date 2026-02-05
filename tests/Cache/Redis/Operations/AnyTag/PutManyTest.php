<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis\Operations\AnyTag;

use Hypervel\Tests\Cache\Redis\RedisCacheTestCase;

/**
 * Tests for the PutMany operation (union tags).
 *
 * @internal
 * @coversNothing
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
        $connection->shouldReceive('pipeline')->andReturn($connection);

        // First pipeline for getting old tags (smembers)
        $connection->shouldReceive('smembers')->twice()->andReturn($connection);
        $connection->shouldReceive('exec')->andReturn([[], []]); // No old tags for first pipeline

        // Second pipeline for setex, reverse index updates, and tag hashes
        $connection->shouldReceive('setex')->twice()->andReturn($connection);
        $connection->shouldReceive('del')->twice()->andReturn($connection);
        $connection->shouldReceive('sadd')->twice()->andReturn($connection);
        $connection->shouldReceive('expire')->twice()->andReturn($connection);

        // hSet and hexpire for tag hashes (batch operation)
        $connection->shouldReceive('hSet')->andReturn($connection);
        $connection->shouldReceive('hexpire')->andReturn($connection);

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
}
