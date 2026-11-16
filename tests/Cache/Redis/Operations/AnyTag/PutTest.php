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
            ->with(Argument::all(function ($script, $keys, $args) {
                // Verify Lua script contains expected commands
                $this->assertStringContainsString('SETEX', $script);
                $this->assertStringContainsString('HSETEX', $script);
                $this->assertStringContainsString('ZADD', $script);
                $this->assertStringContainsString('SMEMBERS', $script);
                // 2 keys: cache key + reverse index key
                $this->assertCount(2, $keys);

                return true;
            }))
            ->returns(true);

        $redis = $this->createStore($connection);
        $redis->setTagMode('any');
        $result = $redis->anyTagOps()->put()->execute('foo', 'bar', 60, ['users', 'posts']);
        $this->assertTrue($result);
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
            ->with(Argument::all(function (string $key, array $options, int $expiresAt, string $tag) use ($startedAt): bool {
                $this->assertSame('prefix:_any:tag:registry', $key);
                $this->assertSame(['GT'], $options);
                $this->assertGreaterThan($startedAt, $expiresAt);
                $this->assertSame('users', $tag);

                return true;
            }))
            ->returns(1);

        $this->assertTrue($redis->anyTagOps()->put()->execute('foo', 'bar', -60, ['users']));
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
            ->with(Argument::all(function ($script, $keys, $args) {
                // Numeric values should be passed as strings in args
                // Args array contains: value, ttl, tagPrefix, registryKey, currentTime, rawKey, tagHashSuffix, ...tags
                $this->assertIsString($args[0]); // Serialized value
                return true;
            }))
            ->returns(true);

        $redis = $this->createStore($connection);
        $redis->setTagMode('any');
        $result = $redis->anyTagOps()->put()->execute('foo', 42, 60, ['numbers']);
        $this->assertTrue($result);
    }
}
