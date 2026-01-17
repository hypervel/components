<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis\Operations;

use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Cache\Redis\Concerns\MocksRedisConnections;

/**
 * Tests for the PutMany operation.
 *
 * @internal
 * @coversNothing
 */
class PutManyTest extends TestCase
{
    use MocksRedisConnections;

    /**
     * @test
     */
    public function testPutManyUsesLuaScriptInStandardMode(): void
    {
        $connection = $this->mockConnection();
        $client = $connection->_mockClient;

        // Standard mode (not cluster) uses Lua script with evalSha
        $client->shouldReceive('evalSha')
            ->once()
            ->andReturn(false); // Script not cached
        $client->shouldReceive('eval')
            ->once()
            ->withArgs(function ($script, $args, $numKeys) {
                // Verify Lua script structure
                $this->assertStringContainsString('SETEX', $script);
                // Keys: prefix:foo, prefix:baz, prefix:bar
                $this->assertSame(3, $numKeys);
                // Args: [key1, key2, key3, ttl, val1, val2, val3]
                $this->assertSame('prefix:foo', $args[0]);
                $this->assertSame('prefix:baz', $args[1]);
                $this->assertSame('prefix:bar', $args[2]);
                $this->assertSame(60, $args[3]); // TTL

                return true;
            })
            ->andReturn(true);

        $redis = $this->createStore($connection);
        $result = $redis->putMany([
            'foo' => 'bar',
            'baz' => 'qux',
            'bar' => 'norf',
        ], 60);
        $this->assertTrue($result);
    }

    /**
     * @test
     */
    public function testPutManyUsesMultiInClusterMode(): void
    {
        [$redis, $clusterClient] = $this->createClusterStore();

        // RedisCluster::multi() returns $this (fluent interface)
        $clusterClient->shouldReceive('multi')->once()->andReturn($clusterClient);
        $clusterClient->shouldReceive('setex')->once()->with('prefix:foo', 60, serialize('bar'))->andReturn($clusterClient);
        $clusterClient->shouldReceive('setex')->once()->with('prefix:baz', 60, serialize('qux'))->andReturn($clusterClient);
        $clusterClient->shouldReceive('exec')->once()->andReturn([true, true]);

        $result = $redis->putMany([
            'foo' => 'bar',
            'baz' => 'qux',
        ], 60);
        $this->assertTrue($result);
    }

    /**
     * @test
     */
    public function testPutManyClusterModeReturnsFalseOnFailure(): void
    {
        [$redis, $clusterClient] = $this->createClusterStore();

        // RedisCluster::multi() returns $this (fluent interface)
        $clusterClient->shouldReceive('multi')->once()->andReturn($clusterClient);
        $clusterClient->shouldReceive('setex')->twice()->andReturn($clusterClient);
        $clusterClient->shouldReceive('exec')->once()->andReturn([true, false]); // One failed

        $result = $redis->putMany([
            'foo' => 'bar',
            'baz' => 'qux',
        ], 60);
        $this->assertFalse($result);
    }

    /**
     * @test
     */
    public function testPutManyReturnsTrueForEmptyValues(): void
    {
        $connection = $this->mockConnection();

        $redis = $this->createStore($connection);
        $result = $redis->putMany([], 60);

        $this->assertTrue($result);
    }

    /**
     * @test
     */
    public function testPutManyLuaFailureReturnsFalse(): void
    {
        $connection = $this->mockConnection();
        $client = $connection->_mockClient;

        // In standard mode (Lua), if both evalSha and eval fail, return false
        $client->shouldReceive('evalSha')
            ->once()
            ->andReturn(false);
        $client->shouldReceive('eval')
            ->once()
            ->andReturn(false); // Lua script failed

        $redis = $this->createStore($connection);
        $result = $redis->putMany([
            'foo' => 'bar',
            'baz' => 'qux',
        ], 60);
        $this->assertFalse($result);
    }

    /**
     * @test
     */
    public function testPutManyEnforcesMinimumTtlOfOne(): void
    {
        $connection = $this->mockConnection();
        $client = $connection->_mockClient;

        $client->shouldReceive('evalSha')->once()->andReturn(false);
        $client->shouldReceive('eval')
            ->once()
            ->withArgs(function ($script, $args, $numKeys) {
                // TTL should be 1, not 0
                $this->assertSame(1, $args[$numKeys]); // TTL is at args[numKeys]
                return true;
            })
            ->andReturn(true);

        $redis = $this->createStore($connection);
        $result = $redis->putMany(['foo' => 'bar'], 0);
        $this->assertTrue($result);
    }
}
