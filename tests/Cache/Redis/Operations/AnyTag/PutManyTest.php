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
        $client = $connection->_mockClient;

        // Standard mode uses pipeline() not multi()
        $client->shouldReceive('pipeline')->andReturn($client);

        // First pipeline for getting old tags (smembers)
        $client->shouldReceive('smembers')->twice()->andReturn($client);
        $client->shouldReceive('exec')->andReturn([[], []]); // No old tags for first pipeline

        // Second pipeline for setex, reverse index updates, and tag hashes
        $client->shouldReceive('setex')->twice()->andReturn($client);
        $client->shouldReceive('del')->twice()->andReturn($client);
        $client->shouldReceive('sadd')->twice()->andReturn($client);
        $client->shouldReceive('expire')->twice()->andReturn($client);

        // hSet and hexpire for tag hashes (batch operation)
        $client->shouldReceive('hSet')->andReturn($client);
        $client->shouldReceive('hexpire')->andReturn($client);

        // zadd for registry
        $client->shouldReceive('zadd')->andReturn($client);

        $redis = $this->createStore($connection);
        $redis->setTagMode('any');
        $result = $redis->anyTagOps()->putMany()->execute([
            'foo' => 'bar',
            'baz' => 'qux',
        ], 60, ['users']);
        $this->assertTrue($result);
    }
}
