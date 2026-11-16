<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis\Operations\AnyTag;

use Hypervel\Cache\Redis\Support\StoreContext;
use Hypervel\Tests\Cache\Redis\RedisCacheTestCase;
use JMac\Testing\Matching\Argument;

class AddTest extends RedisCacheTestCase
{
    public function testAddWithTagsReturnsTrueWhenKeyAdded(): void
    {
        $connection = $this->mockConnection();

        // evalWithShaCache returns true (key added)
        $connection->expects('evalWithShaCache')
            ->with(Argument::all(function ($script, $keys, $args) {
                $this->assertStringContainsString('SET', $script);
                $this->assertStringContainsString('NX', $script);
                $this->assertStringContainsString("redis.call('HSETEX', tagHash, 'EX', ttl, 'FIELDS', 1, rawKey, '1')", $script);
                $this->assertCount(2, $keys);
                $this->assertSame(60, $args[1]);

                return true;
            }))
            ->returns(true);

        $redis = $this->createStore($connection);
        $redis->setTagMode('any');
        $result = $redis->anyTagOps()->add()->execute('foo', 'bar', 60, ['users']);
        $this->assertTrue($result);
    }

    public function testAddWithTagsReturnsFalseWhenKeyExists(): void
    {
        $connection = $this->mockConnection();

        // Lua script returns false when key already exists (SET NX fails)
        $connection->expects('evalWithShaCache')
            ->returns(false);

        $redis = $this->createStore($connection);
        $redis->setTagMode('any');
        $result = $redis->anyTagOps()->add()->execute('foo', 'bar', 60, ['users']);
        $this->assertFalse($result);
    }

    public function testAddWithoutTtlIsPermanentInClusterMode(): void
    {
        [$redis, $client] = $this->createClusterStore(tagMode: 'any');

        $client->expects('set')
            ->with('prefix:foo', serialize('bar'), ['NX'])
            ->returns(true);
        $client->expects('multi')->returns($client);
        $client->expects('sadd')->with('prefix:foo:_any:tags', 'users')->returns($client);
        $client->expects('expire')->never();
        $client->expects('exec')->returns([]);
        $client->expects('hSet')
            ->with('prefix:_any:tag:users:entries', 'foo', StoreContext::TAG_FIELD_VALUE)
            ->returns(1);
        $client->expects('hsetex')->never();
        $client->expects('zadd')
            ->with('prefix:_any:tag:registry', ['GT'], StoreContext::MAX_EXPIRY, 'users')
            ->returns(1);

        $this->assertTrue($redis->anyTagOps()->add()->execute('foo', 'bar', null, ['users']));
    }

    public function testAddNormalizesExpiringMetadataInClusterMode(): void
    {
        [$redis, $client] = $this->createClusterStore(tagMode: 'any');
        $startedAt = time();

        $client->expects('set')
            ->with('prefix:foo', serialize('bar'), ['EX' => 1, 'NX'])
            ->returns(true);
        $client->expects('multi')->returns($client);
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

        $this->assertTrue($redis->anyTagOps()->add()->execute('foo', 'bar', -60, ['users']));
    }

    public function testAddWithoutTtlUsesPermanentLuaBranch(): void
    {
        $connection = $this->mockConnection();
        $connection->expects('evalWithShaCache')
            ->with(Argument::all(function (string $script, array $keys, array $args): bool {
                $this->assertStringContainsString("redis.call('SET', key, val, 'NX')", $script);
                $this->assertStringContainsString('if not permanent then', $script);
                $this->assertStringContainsString((string) StoreContext::MAX_EXPIRY, $script);
                $this->assertCount(2, $keys);
                $this->assertSame(0, $args[1]);

                return true;
            }))
            ->returns(true);

        $redis = $this->createStore($connection, tagMode: 'any');

        $this->assertTrue($redis->anyTagOps()->add()->execute('foo', 'bar', null, ['users']));
    }
}
