<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis\Operations;

use Hypervel\Redis\Exceptions\LuaScriptException;
use Hypervel\Tests\Cache\Redis\RedisCacheTestCase;
use JMac\Testing\Matching\Argument;

/**
 * Tests for the PutMany operation.
 */
class PutManyTest extends RedisCacheTestCase
{
    public function testPutManyUsesLuaScriptInStandardMode(): void
    {
        $connection = $this->mockConnection();

        // Standard mode (not cluster) uses Lua script via evalWithShaCache
        $connection->expects('evalWithShaCache')
            ->with(
                Argument::capture($script),
                ['prefix:foo', 'prefix:baz', 'prefix:bar'],
                [60, serialize('bar'), serialize('qux'), serialize('norf')],
            )
            ->returns(true);

        $redis = $this->createStore($connection);
        $result = $redis->putMany([
            'foo' => 'bar',
            'baz' => 'qux',
            'bar' => 'norf',
        ], 60);
        $this->assertTrue($result);
        $this->assertStringContainsString('SETEX', $script);
    }

    public function testPutManyUsesSequentialCommandsInClusterMode(): void
    {
        [$redis, $client] = $this->createClusterStore();

        $client->expects('setex')->with('prefix:foo', 60, serialize('bar'))->returns(true);
        $client->expects('setex')->with('prefix:baz', 60, serialize('qux'))->returns(true);

        $result = $redis->putMany([
            'foo' => 'bar',
            'baz' => 'qux',
        ], 60);
        $this->assertTrue($result);
    }

    public function testPutManyClusterModeReturnsFalseOnFailure(): void
    {
        [$redis, $client] = $this->createClusterStore();

        $client->expects('setex')->with('prefix:foo', 60, serialize('bar'))->returns(false);
        $client->expects('setex')->with('prefix:baz', 60, serialize('qux'))->returns(true);

        $result = $redis->putMany([
            'foo' => 'bar',
            'baz' => 'qux',
        ], 60);
        $this->assertFalse($result);
    }

    public function testPutManyReturnsTrueForEmptyValues(): void
    {
        $connection = $this->mockConnection();

        $redis = $this->createStore($connection);
        $result = $redis->putMany([], 60);

        $this->assertTrue($result);
    }

    public function testPutManyLuaFailureThrowsException(): void
    {
        $connection = $this->mockConnection();

        // evalWithShaCache throws LuaScriptException on failure
        $connection->expects('evalWithShaCache')
            ->throws(new LuaScriptException('Lua script execution failed'));

        $this->expectException(LuaScriptException::class);

        $redis = $this->createStore($connection);
        $redis->putMany([
            'foo' => 'bar',
            'baz' => 'qux',
        ], 60);
    }

    public function testPutManyEnforcesMinimumTtlOfOne(): void
    {
        $connection = $this->mockConnection();

        $connection->expects('evalWithShaCache')
            ->with(Argument::any(), Argument::any(), Argument::capture($args))
            ->returns(true);

        $redis = $this->createStore($connection);
        $result = $redis->putMany(['foo' => 'bar'], 0);
        $this->assertTrue($result);
        $this->assertSame(1, $args[0]);
    }
}
