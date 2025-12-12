<?php

declare(strict_types=1);

namespace Hypervel\Redis\Tests;

use Hypervel\Redis\RedisConnection;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Redis;
use ReflectionClass;

/**
 * Tests for RedisConnection Laravel-style method transformations.
 *
 * When shouldTransform is enabled, these methods transform Laravel's Redis
 * API calls to phpredis-compatible calls with proper argument ordering
 * and return value transformations.
 *
 * @internal
 * @coversNothing
 */
class RedisConnectionTransformTest extends TestCase
{
    /**
     * @test
     */
    public function testShouldTransformDefaultsToFalse(): void
    {
        $client = m::mock(Redis::class);
        $connection = $this->createConnection($client);

        $this->assertFalse($connection->getShouldTransform());
    }

    /**
     * @test
     */
    public function testShouldTransformCanBeEnabled(): void
    {
        $client = m::mock(Redis::class);
        $connection = $this->createConnection($client);

        $result = $connection->shouldTransform(true);

        $this->assertTrue($connection->getShouldTransform());
        $this->assertSame($connection, $result); // Returns self for chaining
    }

    /**
     * @test
     */
    public function testReleaseResetsShouldTransform(): void
    {
        // Create a connection mock that allows us to test release behavior
        // without triggering parent's pool-related code
        $client = m::mock(Redis::class);
        $connection = m::mock(RedisConnection::class)->makePartial();

        // Set the connection property
        $reflection = new ReflectionClass(RedisConnection::class);
        $property = $reflection->getProperty('connection');
        $property->setAccessible(true);
        $property->setValue($connection, $client);

        // Set up pool mock to avoid "container not initialized" error
        $pool = m::mock(\Hyperf\Pool\Pool::class);
        $pool->shouldReceive('release')->with($connection)->once();
        $poolProperty = $reflection->getProperty('pool');
        $poolProperty->setAccessible(true);
        $poolProperty->setValue($connection, $pool);

        $connection->shouldTransform(true);
        $this->assertTrue($connection->getShouldTransform());

        $connection->release();

        $this->assertFalse($connection->getShouldTransform());
    }

    /**
     * @test
     */
    public function testGetTransformsFalseToNull(): void
    {
        $client = m::mock(Redis::class);
        $client->shouldReceive('get')->with('key')->once()->andReturn(false);

        $connection = $this->createConnection($client)->shouldTransform();

        $result = $connection->get('key');

        $this->assertNull($result);
    }

    /**
     * @test
     */
    public function testGetReturnsValueWhenExists(): void
    {
        $client = m::mock(Redis::class);
        $client->shouldReceive('get')->with('key')->once()->andReturn('value');

        $connection = $this->createConnection($client)->shouldTransform();

        $result = $connection->get('key');

        $this->assertSame('value', $result);
    }

    /**
     * @test
     */
    public function testMgetTransformsFalseValuesToNull(): void
    {
        $client = m::mock(Redis::class);
        $client->shouldReceive('mGet')
            ->with(['key1', 'key2', 'key3'])
            ->once()
            ->andReturn(['value1', false, 'value3']);

        $connection = $this->createConnection($client)->shouldTransform();

        $result = $connection->mget(['key1', 'key2', 'key3']);

        $this->assertSame(['value1', null, 'value3'], $result);
    }

    /**
     * @test
     */
    public function testSetWithoutOptions(): void
    {
        $client = m::mock(Redis::class);
        $client->shouldReceive('set')
            ->with('key', 'value', null)
            ->once()
            ->andReturn(true);

        $connection = $this->createConnection($client)->shouldTransform();

        $result = $connection->set('key', 'value');

        $this->assertTrue($result);
    }

    /**
     * @test
     */
    public function testSetWithExpirationOptions(): void
    {
        $client = m::mock(Redis::class);
        $client->shouldReceive('set')
            ->with('key', 'value', ['NX', 'EX' => 60])
            ->once()
            ->andReturn(true);

        $connection = $this->createConnection($client)->shouldTransform();

        $result = $connection->set('key', 'value', 'EX', 60, 'NX');

        $this->assertTrue($result);
    }

    /**
     * @test
     */
    public function testSetnxReturnsCastedInt(): void
    {
        $client = m::mock(Redis::class);
        $client->shouldReceive('setNx')->with('key', 'value')->once()->andReturn(true);

        $connection = $this->createConnection($client)->shouldTransform();

        $result = $connection->setnx('key', 'value');

        $this->assertSame(1, $result);
    }

    /**
     * @test
     */
    public function testSetnxReturnsZeroOnFailure(): void
    {
        $client = m::mock(Redis::class);
        $client->shouldReceive('setNx')->with('key', 'value')->once()->andReturn(false);

        $connection = $this->createConnection($client)->shouldTransform();

        $result = $connection->setnx('key', 'value');

        $this->assertSame(0, $result);
    }

    /**
     * @test
     */
    public function testHmgetWithSingleArrayArgument(): void
    {
        $client = m::mock(Redis::class);
        $client->shouldReceive('hMGet')
            ->with('hash', ['field1', 'field2'])
            ->once()
            ->andReturn(['field1' => 'value1', 'field2' => 'value2']);

        $connection = $this->createConnection($client)->shouldTransform();

        $result = $connection->hmget('hash', ['field1', 'field2']);

        // Returns array_values, not associative
        $this->assertSame(['value1', 'value2'], $result);
    }

    /**
     * @test
     */
    public function testHmgetWithVariadicArguments(): void
    {
        $client = m::mock(Redis::class);
        $client->shouldReceive('hMGet')
            ->with('hash', ['field1', 'field2'])
            ->once()
            ->andReturn(['field1' => 'value1', 'field2' => 'value2']);

        $connection = $this->createConnection($client)->shouldTransform();

        $result = $connection->hmget('hash', 'field1', 'field2');

        $this->assertSame(['value1', 'value2'], $result);
    }

    /**
     * @test
     */
    public function testHmsetWithSingleArrayArgument(): void
    {
        $client = m::mock(Redis::class);
        $client->shouldReceive('hMSet')
            ->with('hash', ['field1' => 'value1', 'field2' => 'value2'])
            ->once()
            ->andReturn(true);

        $connection = $this->createConnection($client)->shouldTransform();

        $result = $connection->hmset('hash', ['field1' => 'value1', 'field2' => 'value2']);

        $this->assertTrue($result);
    }

    /**
     * @test
     */
    public function testHmsetWithAlternatingKeyValuePairs(): void
    {
        $client = m::mock(Redis::class);
        $client->shouldReceive('hMSet')
            ->with('hash', ['field1' => 'value1', 'field2' => 'value2'])
            ->once()
            ->andReturn(true);

        $connection = $this->createConnection($client)->shouldTransform();

        // Laravel style: key, value, key, value
        $result = $connection->hmset('hash', 'field1', 'value1', 'field2', 'value2');

        $this->assertTrue($result);
    }

    /**
     * @test
     */
    public function testHsetnxReturnsCastedInt(): void
    {
        $client = m::mock(Redis::class);
        $client->shouldReceive('hSetNx')
            ->with('hash', 'field', 'value')
            ->once()
            ->andReturn(true);

        $connection = $this->createConnection($client)->shouldTransform();

        $result = $connection->hsetnx('hash', 'field', 'value');

        $this->assertSame(1, $result);
    }

    /**
     * @test
     */
    public function testLremSwapsCountAndValueArguments(): void
    {
        $client = m::mock(Redis::class);
        // phpredis: lRem(key, value, count)
        // Laravel: lrem(key, count, value)
        $client->shouldReceive('lRem')
            ->with('list', 'element', 2)
            ->once()
            ->andReturn(2);

        $connection = $this->createConnection($client)->shouldTransform();

        // Laravel style: count first, then value
        $result = $connection->lrem('list', 2, 'element');

        $this->assertSame(2, $result);
    }

    /**
     * @test
     */
    public function testBlpopTransformsEmptyToNull(): void
    {
        $client = m::mock(Redis::class);
        $client->shouldReceive('blPop')
            ->with('list', 5)
            ->once()
            ->andReturn([]);

        $connection = $this->createConnection($client)->shouldTransform();

        $result = $connection->blpop('list', 5);

        $this->assertNull($result);
    }

    /**
     * @test
     */
    public function testBlpopReturnsResultWhenNotEmpty(): void
    {
        $client = m::mock(Redis::class);
        $client->shouldReceive('blPop')
            ->with('list', 5)
            ->once()
            ->andReturn(['list', 'value']);

        $connection = $this->createConnection($client)->shouldTransform();

        $result = $connection->blpop('list', 5);

        $this->assertSame(['list', 'value'], $result);
    }

    /**
     * @test
     */
    public function testBrpopTransformsEmptyToNull(): void
    {
        $client = m::mock(Redis::class);
        $client->shouldReceive('brPop')
            ->with('list', 5)
            ->once()
            ->andReturn([]);

        $connection = $this->createConnection($client)->shouldTransform();

        $result = $connection->brpop('list', 5);

        $this->assertNull($result);
    }

    /**
     * @test
     */
    public function testZaddWithScoreMemberPairs(): void
    {
        $client = m::mock(Redis::class);
        $client->shouldReceive('zAdd')
            ->with('zset', [], 1.0, 'member1', 2.0, 'member2')
            ->once()
            ->andReturn(2);

        $connection = $this->createConnection($client)->shouldTransform();

        $result = $connection->zadd('zset', 1.0, 'member1', 2.0, 'member2');

        $this->assertSame(2, $result);
    }

    /**
     * @test
     */
    public function testZaddWithArrayDictionary(): void
    {
        $client = m::mock(Redis::class);
        $client->shouldReceive('zAdd')
            ->with('zset', [], 1.0, 'member1', 2.0, 'member2')
            ->once()
            ->andReturn(2);

        $connection = $this->createConnection($client)->shouldTransform();

        // Laravel style: final array argument is member => score dictionary
        $result = $connection->zadd('zset', ['member1' => 1.0, 'member2' => 2.0]);

        $this->assertSame(2, $result);
    }

    /**
     * @test
     */
    public function testZaddWithOptions(): void
    {
        $client = m::mock(Redis::class);
        $client->shouldReceive('zAdd')
            ->with('zset', ['NX', 'CH'], 1.0, 'member')
            ->once()
            ->andReturn(1);

        $connection = $this->createConnection($client)->shouldTransform();

        // Options come before score/member pairs
        $result = $connection->zadd('zset', 'NX', 'CH', 1.0, 'member');

        $this->assertSame(1, $result);
    }

    /**
     * @test
     */
    public function testZrangebyscoreWithLimitOption(): void
    {
        $client = m::mock(Redis::class);
        $client->shouldReceive('zRangeByScore')
            ->with('zset', '-inf', '+inf', ['limit' => [0, 10]])
            ->once()
            ->andReturn(['member1', 'member2']);

        $connection = $this->createConnection($client)->shouldTransform();

        // Laravel style: limit as associative array with offset/count keys
        $result = $connection->zrangebyscore('zset', '-inf', '+inf', [
            'limit' => ['offset' => 0, 'count' => 10],
        ]);

        $this->assertSame(['member1', 'member2'], $result);
    }

    /**
     * @test
     */
    public function testZrangebyscoreWithListLimitPassesThrough(): void
    {
        $client = m::mock(Redis::class);
        $client->shouldReceive('zRangeByScore')
            ->with('zset', '-inf', '+inf', ['limit' => [5, 20]])
            ->once()
            ->andReturn(['member1']);

        $connection = $this->createConnection($client)->shouldTransform();

        // Already in list format - passes through
        $result = $connection->zrangebyscore('zset', '-inf', '+inf', [
            'limit' => [5, 20],
        ]);

        $this->assertSame(['member1'], $result);
    }

    /**
     * @test
     */
    public function testZrevrangebyscoreWithLimitOption(): void
    {
        $client = m::mock(Redis::class);
        $client->shouldReceive('zRevRangeByScore')
            ->with('zset', '+inf', '-inf', ['limit' => [0, 5]])
            ->once()
            ->andReturn(['member2', 'member1']);

        $connection = $this->createConnection($client)->shouldTransform();

        $result = $connection->zrevrangebyscore('zset', '+inf', '-inf', [
            'limit' => ['offset' => 0, 'count' => 5],
        ]);

        $this->assertSame(['member2', 'member1'], $result);
    }

    /**
     * @test
     */
    public function testZinterstoreExtractsOptions(): void
    {
        $client = m::mock(Redis::class);
        $client->shouldReceive('zinterstore')
            ->with('output', ['set1', 'set2'], [1.0, 2.0], 'max')
            ->once()
            ->andReturn(3);

        $connection = $this->createConnection($client)->shouldTransform();

        $result = $connection->zinterstore('output', ['set1', 'set2'], [
            'weights' => [1.0, 2.0],
            'aggregate' => 'max',
        ]);

        $this->assertSame(3, $result);
    }

    /**
     * @test
     */
    public function testZinterstoreDefaultsAggregate(): void
    {
        $client = m::mock(Redis::class);
        $client->shouldReceive('zinterstore')
            ->with('output', ['set1', 'set2'], null, 'sum')
            ->once()
            ->andReturn(2);

        $connection = $this->createConnection($client)->shouldTransform();

        $result = $connection->zinterstore('output', ['set1', 'set2']);

        $this->assertSame(2, $result);
    }

    /**
     * @test
     */
    public function testZunionstoreExtractsOptions(): void
    {
        $client = m::mock(Redis::class);
        $client->shouldReceive('zunionstore')
            ->with('output', ['set1', 'set2'], [2.0, 3.0], 'min')
            ->once()
            ->andReturn(5);

        $connection = $this->createConnection($client)->shouldTransform();

        $result = $connection->zunionstore('output', ['set1', 'set2'], [
            'weights' => [2.0, 3.0],
            'aggregate' => 'min',
        ]);

        $this->assertSame(5, $result);
    }

    /**
     * @test
     */
    public function testFlushdbWithAsync(): void
    {
        $client = m::mock(Redis::class);
        $client->shouldReceive('flushdb')
            ->with(true)
            ->once()
            ->andReturn(true);

        $connection = $this->createConnection($client)->shouldTransform();

        $result = $connection->flushdb('ASYNC');

        $this->assertTrue($result);
    }

    /**
     * @test
     */
    public function testFlushdbWithoutAsync(): void
    {
        $client = m::mock(Redis::class);
        $client->shouldReceive('flushdb')
            ->withNoArgs()
            ->once()
            ->andReturn(true);

        $connection = $this->createConnection($client)->shouldTransform();

        $result = $connection->flushdb();

        $this->assertTrue($result);
    }

    /**
     * @test
     */
    public function testExecuteRawPassesToRawCommand(): void
    {
        $client = m::mock(Redis::class);
        $client->shouldReceive('rawCommand')
            ->with('GET', 'key')
            ->once()
            ->andReturn('value');

        $connection = $this->createConnection($client)->shouldTransform();

        $result = $connection->executeRaw(['GET', 'key']);

        $this->assertSame('value', $result);
    }

    /**
     * @test
     */
    public function testCallWithoutTransformPassesDirectly(): void
    {
        $client = m::mock(Redis::class);
        // Without transform, get() returns false (not null)
        $client->shouldReceive('get')->with('key')->once()->andReturn(false);

        $connection = $this->createConnection($client);
        // shouldTransform is false by default

        $result = $connection->get('key');

        $this->assertFalse($result);
    }

    /**
     * Create a RedisConnection with the given client.
     */
    private function createConnection(m\MockInterface $client): RedisConnection
    {
        $connection = m::mock(RedisConnection::class)->makePartial();

        $reflection = new ReflectionClass(RedisConnection::class);
        $property = $reflection->getProperty('connection');
        $property->setAccessible(true);
        $property->setValue($connection, $client);

        return $connection;
    }
}
