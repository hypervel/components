<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis\Operations;

use Hypervel\Tests\Cache\Redis\RedisCacheTestCase;

/**
 * Tests for the Get operation.
 */
class GetTest extends RedisCacheTestCase
{
    public function testGetReturnsNullWhenNotFound(): void
    {
        $connection = $this->mockConnection();
        $connection->client()->expects('get')->with('prefix:foo')->returns(null);

        $redis = $this->createStore($connection);
        $this->assertNull($redis->get('foo'));
    }

    public function testRedisValueIsReturned(): void
    {
        $connection = $this->mockConnection();
        $connection->client()->expects('get')->with('prefix:foo')->returns(serialize('foo'));

        $redis = $this->createStore($connection);
        $this->assertSame('foo', $redis->get('foo'));
    }

    public function testRedisValueIsReturnedForNumerics(): void
    {
        $connection = $this->mockConnection();
        $connection->client()->expects('get')->with('prefix:foo')->returns(1);

        $redis = $this->createStore($connection);
        $this->assertEquals(1, $redis->get('foo'));
    }

    public function testGetReturnsFalseValueAsNull(): void
    {
        // Redis returns false for non-existent keys
        $connection = $this->mockConnection();
        $connection->client()->expects('get')->with('prefix:foo')->returns(false);

        $redis = $this->createStore($connection);
        $this->assertNull($redis->get('foo'));
    }

    public function testGetReturnsEmptyStringCorrectly(): void
    {
        $connection = $this->mockConnection();
        $connection->client()->expects('get')->with('prefix:foo')->returns(serialize(''));

        $redis = $this->createStore($connection);
        $this->assertSame('', $redis->get('foo'));
    }

    public function testGetReturnsZeroCorrectly(): void
    {
        $connection = $this->mockConnection();
        $connection->client()->expects('get')->with('prefix:foo')->returns(0);

        $redis = $this->createStore($connection);
        $this->assertSame(0, $redis->get('foo'));
    }

    public function testGetReturnsFloatCorrectly(): void
    {
        $connection = $this->mockConnection();
        $connection->client()->expects('get')->with('prefix:foo')->returns(3.14);

        $redis = $this->createStore($connection);
        $this->assertSame(3.14, $redis->get('foo'));
    }

    public function testGetReturnsNegativeNumberCorrectly(): void
    {
        $connection = $this->mockConnection();
        $connection->client()->expects('get')->with('prefix:foo')->returns(-42);

        $redis = $this->createStore($connection);
        $this->assertSame(-42, $redis->get('foo'));
    }
}
