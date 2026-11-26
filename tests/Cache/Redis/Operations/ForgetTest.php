<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis\Operations;

use Hypervel\Tests\Cache\Redis\RedisCacheTestCase;
use LogicException;
use Redis;

/**
 * Tests for the Forget operation.
 */
class ForgetTest extends RedisCacheTestCase
{
    public function testForgetReturnsTrueWhenKeyExists(): void
    {
        $connection = $this->mockConnection();
        $connection->client()->expects('del')->with('prefix:foo')->returns(1);

        $redis = $this->createStore($connection);
        $this->assertTrue($redis->forget('foo'));
    }

    public function testForgetReturnsFalseWhenKeyDoesNotExist(): void
    {
        // Redis del() returns 0 when key doesn't exist, cast to bool = false
        $connection = $this->mockConnection();
        $connection->client()->expects('del')->with('prefix:nonexistent')->returns(0);

        $redis = $this->createStore($connection);
        $this->assertFalse($redis->forget('nonexistent'));
    }

    public function testForgetManyKeysDeletesEveryKeyInOnePipeline(): void
    {
        $connection = $this->mockConnection();
        $client = $connection->client();
        $client->expects('pipeline')->returns($client)->ordered();
        $client->expects('del')->with('prefix:foo')->returns($client)->ordered();
        $client->expects('del')->with('prefix:missing')->returns($client)->ordered();
        $client->expects('exec')->returns([1, 0])->ordered();

        // Like forget(), a key that did not exist reports false.
        $this->assertSame([true, false], $this->createStore($connection)->forgetManyKeys(['foo', 'missing']));
    }

    public function testForgetManyKeysDeletesEachKeySeparatelyInClusterMode(): void
    {
        [$store, $client] = $this->createClusterStore();
        $client->expects('del')->with('prefix:foo')->returns(1)->ordered();
        $client->expects('del')->with('prefix:fizz')->returns(1)->ordered();

        // Results are positional, as from the pipeline, whatever the caller's keys are.
        $this->assertSame([true, true], $store->forgetManyKeys([3 => 'foo', 7 => 'fizz']));
    }

    public function testAnyModeForgetRejectsAClusterTransactionBeforeQueuingCommands(): void
    {
        // Queuing SMEMBERS would hand back the client instead of the key's tags, so DEL would run without HDEL.
        [$store, $client] = $this->createClusterStore(tagMode: 'any');
        $client->expects('getMode')->returns(Redis::MULTI);

        $this->expectException(LogicException::class);

        $store->forgetManyKeys(['{topic}:tea']);
    }
}
