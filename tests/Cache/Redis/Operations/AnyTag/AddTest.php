<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis\Operations\AnyTag;

use Hypervel\Cache\Redis\Support\StoreContext;
use Hypervel\Tests\Cache\Redis\RedisCacheTestCase;

class AddTest extends RedisCacheTestCase
{
    /**
     * @test
     */
    public function testAddWithTagsReturnsTrueWhenKeyAdded(): void
    {
        $connection = $this->mockConnection();

        // evalWithShaCache returns true (key added)
        $connection->shouldReceive('evalWithShaCache')
            ->once()
            ->withArgs(function ($script, $keys, $args) {
                $this->assertStringContainsString('SET', $script);
                $this->assertStringContainsString('NX', $script);
                $this->assertStringContainsString("redis.call('HSETEX', tagHash, 'EX', ttl, 'FIELDS', 1, rawKey, '1')", $script);
                $this->assertCount(2, $keys);
                $this->assertSame(60, $args[1]);

                return true;
            })
            ->andReturn(true);

        $redis = $this->createStore($connection);
        $redis->setTagMode('any');
        $result = $redis->anyTagOps()->add()->execute('foo', 'bar', 60, ['users']);
        $this->assertTrue($result);
    }

    /**
     * @test
     */
    public function testAddWithTagsReturnsFalseWhenKeyExists(): void
    {
        $connection = $this->mockConnection();

        // Lua script returns false when key already exists (SET NX fails)
        $connection->shouldReceive('evalWithShaCache')
            ->once()
            ->andReturn(false);

        $redis = $this->createStore($connection);
        $redis->setTagMode('any');
        $result = $redis->anyTagOps()->add()->execute('foo', 'bar', 60, ['users']);
        $this->assertFalse($result);
    }

    public function testAddWithoutTtlIsPermanentInClusterMode(): void
    {
        [$redis, , $connection] = $this->createClusterStore(tagMode: 'any');

        $connection->shouldReceive('set')
            ->once()
            ->with('prefix:foo', serialize('bar'), ['NX'])
            ->andReturn(true);
        $connection->shouldReceive('multi')->once()->andReturnSelf();
        $connection->shouldReceive('sadd')->once()->with('prefix:foo:_any:tags', 'users')->andReturnSelf();
        $connection->shouldNotReceive('expire');
        $connection->shouldReceive('exec')->once()->andReturn([]);
        $connection->shouldReceive('hSet')
            ->once()
            ->with('prefix:_any:tag:users:entries', 'foo', StoreContext::TAG_FIELD_VALUE)
            ->andReturn(1);
        $connection->shouldNotReceive('hsetex');
        $connection->shouldReceive('zadd')
            ->once()
            ->with('prefix:_any:tag:registry', ['GT'], StoreContext::MAX_EXPIRY, 'users')
            ->andReturn(1);

        $this->assertTrue($redis->anyTagOps()->add()->execute('foo', 'bar', null, ['users']));
    }

    public function testAddWithoutTtlUsesPermanentLuaBranch(): void
    {
        $connection = $this->mockConnection();
        $connection->shouldReceive('evalWithShaCache')
            ->once()
            ->withArgs(function (string $script, array $keys, array $args): bool {
                $this->assertStringContainsString("redis.call('SET', key, val, 'NX')", $script);
                $this->assertStringContainsString('if not permanent then', $script);
                $this->assertStringContainsString((string) StoreContext::MAX_EXPIRY, $script);
                $this->assertCount(2, $keys);
                $this->assertSame(0, $args[1]);

                return true;
            })
            ->andReturn(true);

        $redis = $this->createStore($connection, tagMode: 'any');

        $this->assertTrue($redis->anyTagOps()->add()->execute('foo', 'bar', null, ['users']));
    }
}
