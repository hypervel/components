<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis\Operations\AnyTag;

use Hypervel\Tests\Cache\Redis\RedisCacheTestCase;
use JMac\Testing\Matching\Argument;

/**
 * Tests for the Put operation (union tags).
 */
class PutTest extends RedisCacheTestCase
{
    public function testPutWithTagsUsesLuaScriptInStandardMode(): void
    {
        $connection = $this->mockConnection();

        // Standard mode uses Lua script via evalWithShaCache
        $connection->expects('evalWithShaCache')
            ->with(Argument::capture($script), Argument::capture($keys), Argument::any())
            ->returns(true);

        $redis = $this->createStore($connection);
        $redis->setTagMode('any');
        $result = $redis->anyTagOps()->put()->execute('foo', 'bar', 60, ['users', 'posts']);
        $this->assertTrue($result);
        $this->assertStringContainsString('SETEX', $script);
        $this->assertStringContainsString('HSETEX', $script);
        $this->assertStringContainsString('ZADD', $script);
        $this->assertStringContainsString('SMEMBERS', $script);

        $this->assertCount(2, $keys);
    }

    public function testPutWithTagsUsesSequentialCommandsInClusterMode(): void
    {
        [$redis, $client] = $this->createClusterStore(tagMode: 'any');

        // Cluster mode expectations
        $client->expects('smembers')->returns([]);
        $client->expects('setex')->with('prefix:foo', 60, serialize('bar'))->returns(true);

        // Multi for reverse index
        $client->allows('multi')->returns($client);
        $client->allows('del')->returns($client);
        $client->allows('sadd')->returns($client);
        $client->allows('expire')->returns($client);
        $client->allows('exec')->returns([true, true, true]);

        // HSETEX for both tag hashes.
        $client->expects('hsetex')->times(2)->returns(1);

        // ZADD for the registry.
        $client->expects('zadd')->returns(2);

        $result = $redis->anyTagOps()->put()->execute('foo', 'bar', 60, ['users', 'posts']);
        $this->assertTrue($result);
    }

    public function testPutNormalizesExpiringMetadataInClusterMode(): void
    {
        [$redis, $client] = $this->createClusterStore(tagMode: 'any');
        $startedAt = time();

        $client->expects('smembers')->with('prefix:foo:_any:tags')->returns([]);
        $client->expects('setex')->with('prefix:foo', 1, serialize('bar'))->returns(true);
        $client->expects('multi')->returns($client);
        $client->expects('del')->with('prefix:foo:_any:tags')->returns($client);
        $client->expects('sadd')->with('prefix:foo:_any:tags', 'users')->returns($client);
        $client->expects('expire')->with('prefix:foo:_any:tags', 1)->returns($client);
        $client->expects('exec')->returns([]);
        $client->expects('hsetex')
            ->with('prefix:_any:tag:users:entries', ['foo' => '1'], ['EX' => 1])
            ->returns(1);
        $client->expects('zadd')
            ->with('prefix:_any:tag:registry', ['GT'], Argument::capture($expiresAt), 'users')
            ->returns(1);

        $this->assertTrue($redis->anyTagOps()->put()->execute('foo', 'bar', -60, ['users']));
        $this->assertIsInt($expiresAt);
        $this->assertGreaterThan($startedAt, $expiresAt);
    }

    public function testPutWithTagsHandlesEmptyTags(): void
    {
        $connection = $this->mockConnection();

        $connection->expects('evalWithShaCache')
            ->returns(true);

        $redis = $this->createStore($connection);
        $redis->setTagMode('any');
        $result = $redis->anyTagOps()->put()->execute('foo', 'bar', 60, []);
        $this->assertTrue($result);
    }

    public function testPutWithTagsWithNumericValue(): void
    {
        $connection = $this->mockConnection();

        $connection->expects('evalWithShaCache')
            ->with(Argument::any(), Argument::any(), Argument::capture($args))
            ->returns(true);

        $redis = $this->createStore($connection);
        $redis->setTagMode('any');
        $result = $redis->anyTagOps()->put()->execute('foo', 42, 60, ['numbers']);
        $this->assertTrue($result);
        $this->assertIsString($args[0]);
    }
}
