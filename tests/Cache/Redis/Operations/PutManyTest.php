<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis\Operations;

use Hypervel\Redis\Exceptions\LuaScriptException;
use Hypervel\Tests\Cache\Redis\RedisCacheTestCase;

/**
 * Tests for the PutMany operation.
 *
 * @internal
 * @coversNothing
 */
class PutManyTest extends RedisCacheTestCase
{
    /**
     * @test
     */
    public function testPutManyUsesLuaScriptInStandardMode(): void
    {
        $connection = $this->mockConnection();

        // Standard mode (not cluster) uses Lua script via evalWithShaCache
        $connection->shouldReceive('evalWithShaCache')
            ->once()
            ->withArgs(function ($script, $keys, $args) {
                // Verify Lua script structure
                $this->assertStringContainsString('SETEX', $script);
                // Keys: prefix:foo, prefix:baz, prefix:bar
                $this->assertCount(3, $keys);
                $this->assertSame('prefix:foo', $keys[0]);
                $this->assertSame('prefix:baz', $keys[1]);
                $this->assertSame('prefix:bar', $keys[2]);
                // Args: [ttl, val1, val2, val3]
                $this->assertSame(60, $args[0]); // TTL

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
    public function testPutManyLuaFailureThrowsException(): void
    {
        $connection = $this->mockConnection();

        // evalWithShaCache throws LuaScriptException on failure
        $connection->shouldReceive('evalWithShaCache')
            ->once()
            ->andThrow(new LuaScriptException('Lua script execution failed'));

        $this->expectException(LuaScriptException::class);

        $redis = $this->createStore($connection);
        $redis->putMany([
            'foo' => 'bar',
            'baz' => 'qux',
        ], 60);
    }

    /**
     * @test
     */
    public function testPutManyEnforcesMinimumTtlOfOne(): void
    {
        $connection = $this->mockConnection();

        $connection->shouldReceive('evalWithShaCache')
            ->once()
            ->withArgs(function ($script, $keys, $args) {
                // TTL should be 1, not 0
                $this->assertSame(1, $args[0]); // TTL is first arg
                return true;
            })
            ->andReturn(true);

        $redis = $this->createStore($connection);
        $result = $redis->putMany(['foo' => 'bar'], 0);
        $this->assertTrue($result);
    }
}
