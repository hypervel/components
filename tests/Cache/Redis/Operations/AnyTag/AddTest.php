<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis\Operations\AnyTag;

use Hypervel\Tests\Cache\Redis\RedisCacheTestCase;

/**
 * Tests for the Add operation (union tags).
 *
 * @internal
 * @coversNothing
 */
class AddTest extends RedisCacheTestCase
{
    /**
     * @test
     */
    public function testAddWithTagsReturnsTrueWhenKeyAdded(): void
    {
        $connection = $this->mockConnection();
        $client = $connection->_mockClient;

        // evalSha returns false (script not cached), eval returns true (key added)
        $client->shouldReceive('evalSha')
            ->once()
            ->andReturn(false);
        $client->shouldReceive('eval')
            ->once()
            ->withArgs(function ($script, $args, $numKeys) {
                $this->assertStringContainsString('SET', $script);
                $this->assertStringContainsString('NX', $script);
                $this->assertStringContainsString('HSETEX', $script);
                $this->assertSame(2, $numKeys);

                return true;
            })
            ->andReturn(true);

        $redis = $this->createStore($connection);
        $redis->setTagMode('any');
        $result = $redis->anyTagOps()->add()->execute('foo', 'bar', 60, ['users']);
        $this->assertTrue($result);
    }

    /**
     * @test
     */
    public function testAddWithTagsReturnsFalseWhenKeyExists(): void
    {
        $connection = $this->mockConnection();
        $client = $connection->_mockClient;

        // Lua script returns false when key already exists (SET NX fails)
        $client->shouldReceive('evalSha')
            ->once()
            ->andReturn(false);
        $client->shouldReceive('eval')
            ->once()
            ->andReturn(false); // Key exists

        $redis = $this->createStore($connection);
        $redis->setTagMode('any');
        $result = $redis->anyTagOps()->add()->execute('foo', 'bar', 60, ['users']);
        $this->assertFalse($result);
    }
}
