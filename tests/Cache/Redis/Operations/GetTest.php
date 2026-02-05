<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis\Operations;

use Hypervel\Tests\Cache\Redis\RedisCacheTestCase;

/**
 * Tests for the Get operation.
 *
 * @internal
 * @coversNothing
 */
class GetTest extends RedisCacheTestCase
{
    /**
     * @test
     */
    public function testGetReturnsNullWhenNotFound(): void
    {
        $connection = $this->mockConnection();
        $connection->shouldReceive('get')->once()->with('prefix:foo')->andReturn(null);

        $redis = $this->createStore($connection);
        $this->assertNull($redis->get('foo'));
    }

    /**
     * @test
     */
    public function testRedisValueIsReturned(): void
    {
        $connection = $this->mockConnection();
        $connection->shouldReceive('get')->once()->with('prefix:foo')->andReturn(serialize('foo'));

        $redis = $this->createStore($connection);
        $this->assertSame('foo', $redis->get('foo'));
    }

    /**
     * @test
     */
    public function testRedisValueIsReturnedForNumerics(): void
    {
        $connection = $this->mockConnection();
        $connection->shouldReceive('get')->once()->with('prefix:foo')->andReturn(1);

        $redis = $this->createStore($connection);
        $this->assertEquals(1, $redis->get('foo'));
    }

    /**
     * @test
     */
    public function testGetReturnsFalseValueAsNull(): void
    {
        // Redis returns false for non-existent keys
        $connection = $this->mockConnection();
        $connection->shouldReceive('get')->once()->with('prefix:foo')->andReturn(false);

        $redis = $this->createStore($connection);
        $this->assertNull($redis->get('foo'));
    }

    /**
     * @test
     */
    public function testGetReturnsEmptyStringCorrectly(): void
    {
        $connection = $this->mockConnection();
        $connection->shouldReceive('get')->once()->with('prefix:foo')->andReturn(serialize(''));

        $redis = $this->createStore($connection);
        $this->assertSame('', $redis->get('foo'));
    }

    /**
     * @test
     */
    public function testGetReturnsZeroCorrectly(): void
    {
        $connection = $this->mockConnection();
        $connection->shouldReceive('get')->once()->with('prefix:foo')->andReturn(0);

        $redis = $this->createStore($connection);
        $this->assertSame(0, $redis->get('foo'));
    }

    /**
     * @test
     */
    public function testGetReturnsFloatCorrectly(): void
    {
        $connection = $this->mockConnection();
        $connection->shouldReceive('get')->once()->with('prefix:foo')->andReturn(3.14);

        $redis = $this->createStore($connection);
        $this->assertSame(3.14, $redis->get('foo'));
    }

    /**
     * @test
     */
    public function testGetReturnsNegativeNumberCorrectly(): void
    {
        $connection = $this->mockConnection();
        $connection->shouldReceive('get')->once()->with('prefix:foo')->andReturn(-42);

        $redis = $this->createStore($connection);
        $this->assertSame(-42, $redis->get('foo'));
    }
}
