<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis\Operations;

use Hypervel\Tests\Cache\Redis\RedisCacheTestCase;
use Redis;
use RedisException;

/**
 * Tests for the Forever operation.
 */
class ForeverTest extends RedisCacheTestCase
{
    public function testStoreItemForeverProperlyCallsRedis(): void
    {
        $connection = $this->mockConnection();
        $connection->client()->expects('set')
            ->with('prefix:foo', serialize('foo'))
            ->returns(true);

        $redis = $this->createStore($connection);
        $result = $redis->forever('foo', 'foo');
        $this->assertTrue($result);
    }

    public function testForeverWithNumericValue(): void
    {
        $connection = $this->mockConnection();
        $connection->client()->expects('set')
            ->with('prefix:foo', 99)
            ->returns(true);

        $redis = $this->createStore($connection);
        $this->assertTrue($redis->forever('foo', 99));
    }

    public function testForeverManyWritesEveryValueInOnePipeline(): void
    {
        $connection = $this->mockConnection();
        $client = $connection->client();
        $client->expects('pipeline')->returns($client)->ordered();
        $client->expects('set')->with('prefix:foo', serialize('bar'))->returns($client)->ordered();
        $client->expects('set')->with('prefix:number', 5)->returns($client)->ordered();
        $client->expects('exec')->returns([true, true])->ordered();

        $this->assertSame(['foo' => true, 'number' => true], $this->createStore($connection)->foreverMany(['foo' => 'bar', 'number' => 5]));
    }

    public function testForeverManyReportsAFailedWrite(): void
    {
        $connection = $this->mockConnection();
        $client = $connection->client();
        $client->expects('pipeline')->returns($client);
        $client->expects('set')->times(2)->returns($client);
        $client->expects('exec')->returns([true, false]);

        $this->assertSame(['foo' => true, 'fizz' => false], $this->createStore($connection)->foreverMany(['foo' => 'bar', 'fizz' => 'buzz']));
    }

    public function testForeverManyJoinsAQueueTheCallerOwns(): void
    {
        $connection = $this->mockConnection();
        $client = $connection->client();
        $client->expects('getMode')->returns(Redis::PIPELINE);
        $client->expects('set')->times(2)->returns($client);
        $client->expects('pipeline')->never();
        $client->expects('exec')->never();

        $this->assertSame(['foo' => true, 'fizz' => true], $this->createStore($connection)->foreverMany(['foo' => 'bar', 'fizz' => 'buzz']));
    }

    public function testForeverManyInvalidatesTheConnectionWhenItsPipelineFails(): void
    {
        $connection = $this->mockConnection();
        $client = $connection->client();
        $client->expects('pipeline')->returns($client);
        $client->expects('set')->times(2)->returns($client);
        $client->expects('exec')->throws($exception = new RedisException('Connection lost'));
        $connection->expects('invalidate');

        try {
            $this->createStore($connection)->foreverMany(['foo' => 'bar', 'fizz' => 'buzz']);
            $this->fail('The pipeline failure was not thrown.');
        } catch (RedisException $caught) {
            $this->assertSame($exception, $caught);
        }
    }
}
