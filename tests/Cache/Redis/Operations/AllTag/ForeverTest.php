<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis\Operations\AllTag;

use Hypervel\Tests\Cache\Redis\RedisCacheTestCase;

/**
 * Tests for the Forever operation (intersection tags).
 */
class ForeverTest extends RedisCacheTestCase
{
    public function testForeverStoresValueWithTagsInPipelineMode(): void
    {
        $connection = $this->mockConnection();

        $connection->shouldReceive('pipeline')->once()->andReturn($connection);

        $connection->shouldReceive('set')
            ->once()
            ->with('prefix:mykey', serialize('myvalue'))
            ->andReturn($connection)
            ->ordered();

        $connection->shouldReceive('zadd')
            ->once()
            ->with('prefix:_all:tag:users:entries', -1, 'mykey')
            ->andReturn($connection)
            ->ordered();

        $connection->shouldReceive('exec')
            ->once()
            ->andReturn([true, 1]);

        $store = $this->createStore($connection);
        $result = $store->allTagOps()->forever()->execute(
            'mykey',
            'myvalue',
            ['_all:tag:users:entries']
        );

        $this->assertTrue($result);
    }

    public function testForeverWithMultipleTags(): void
    {
        $connection = $this->mockConnection();

        $connection->shouldReceive('pipeline')->once()->andReturn($connection);

        // ZADD for each tag with score -1
        $connection->shouldReceive('zadd')
            ->once()
            ->with('prefix:_all:tag:users:entries', -1, 'mykey')
            ->andReturn($connection);
        $connection->shouldReceive('zadd')
            ->once()
            ->with('prefix:_all:tag:posts:entries', -1, 'mykey')
            ->andReturn($connection);

        // SET for cache value
        $connection->shouldReceive('set')
            ->once()
            ->with('prefix:mykey', serialize('myvalue'))
            ->andReturn($connection);

        $connection->shouldReceive('exec')
            ->once()
            ->andReturn([true, 1, 1]);

        $store = $this->createStore($connection);
        $result = $store->allTagOps()->forever()->execute(
            'mykey',
            'myvalue',
            ['_all:tag:users:entries', '_all:tag:posts:entries']
        );

        $this->assertTrue($result);
    }

    public function testForeverWithEmptyTags(): void
    {
        $connection = $this->mockConnection();

        $connection->shouldReceive('pipeline')->once()->andReturn($connection);

        // SET for cache value only
        $connection->shouldReceive('set')
            ->once()
            ->with('prefix:mykey', serialize('myvalue'))
            ->andReturn($connection);

        $connection->shouldReceive('exec')
            ->once()
            ->andReturn([true]);

        $store = $this->createStore($connection);
        $result = $store->allTagOps()->forever()->execute(
            'mykey',
            'myvalue',
            []
        );

        $this->assertTrue($result);
    }

    public function testForeverInClusterModeUsesSequentialCommands(): void
    {
        [$store, , $connection] = $this->createClusterStore();

        // Should NOT use pipeline in cluster mode
        $connection->shouldNotReceive('pipeline');

        $connection->shouldReceive('set')
            ->once()
            ->with('prefix:mykey', serialize('myvalue'))
            ->andReturn(true)
            ->ordered();

        $connection->shouldReceive('zadd')
            ->once()
            ->with('prefix:_all:tag:users:entries', -1, 'mykey')
            ->andReturn(1)
            ->ordered();

        $result = $store->allTagOps()->forever()->execute(
            'mykey',
            'myvalue',
            ['_all:tag:users:entries']
        );

        $this->assertTrue($result);
    }

    public function testForeverReturnsFalseOnFailure(): void
    {
        $connection = $this->mockConnection();

        $connection->shouldReceive('pipeline')->once()->andReturn($connection);

        $connection->shouldReceive('zadd')->andReturn($connection);
        $connection->shouldReceive('set')->andReturn($connection);

        // SET returns false (failure)
        $connection->shouldReceive('exec')
            ->once()
            ->andReturn([false, 1]);

        $store = $this->createStore($connection);
        $result = $store->allTagOps()->forever()->execute(
            'mykey',
            'myvalue',
            ['_all:tag:users:entries']
        );

        $this->assertFalse($result);
    }

    public function testForeverUsesCorrectPrefix(): void
    {
        $connection = $this->mockConnection();

        $connection->shouldReceive('pipeline')->once()->andReturn($connection);

        $connection->shouldReceive('zadd')
            ->once()
            ->with('custom:_all:tag:users:entries', -1, 'mykey')
            ->andReturn($connection);

        $connection->shouldReceive('set')
            ->once()
            ->with('custom:mykey', serialize('myvalue'))
            ->andReturn($connection);

        $connection->shouldReceive('exec')
            ->once()
            ->andReturn([true, 1]);

        $store = $this->createStore($connection, 'custom:');
        $result = $store->allTagOps()->forever()->execute(
            'mykey',
            'myvalue',
            ['_all:tag:users:entries']
        );

        $this->assertTrue($result);
    }

    public function testForeverWithNumericValue(): void
    {
        $connection = $this->mockConnection();

        $connection->shouldReceive('pipeline')->once()->andReturn($connection);

        $connection->shouldReceive('zadd')->andReturn($connection);

        // Numeric values are NOT serialized (optimization)
        $connection->shouldReceive('set')
            ->once()
            ->with('prefix:mykey', 42)
            ->andReturn($connection);

        $connection->shouldReceive('exec')
            ->once()
            ->andReturn([true, 1]);

        $store = $this->createStore($connection);
        $result = $store->allTagOps()->forever()->execute(
            'mykey',
            42,
            ['_all:tag:users:entries']
        );

        $this->assertTrue($result);
    }
}
