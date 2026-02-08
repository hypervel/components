<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Redis;

use Hypervel\Contracts\Config\Repository;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Foundation\Testing\Concerns\InteractsWithRedis;
use Hypervel\Foundation\Testing\Concerns\RunTestsInCoroutine;
use Hypervel\Redis\Exceptions\LuaScriptException;
use Hypervel\Support\Facades\Redis;
use Hypervel\Testbench\TestCase;

/**
 * Integration tests for RedisConnection::evalWithShaCache().
 *
 * These tests verify the actual Redis interaction including:
 * - Script SHA caching (evalSha success on subsequent calls)
 * - NOSCRIPT fallback (eval on first call when script not cached)
 * - Error handling for invalid scripts
 *
 * @group integration
 * @group redis-integration
 *
 * @internal
 * @coversNothing
 */
class EvalWithShaCacheIntegrationTest extends TestCase
{
    use InteractsWithRedis;
    use RunTestsInCoroutine;

    protected function defineEnvironment(ApplicationContract $app): void
    {
        $config = $app->get(Repository::class);
        $this->configureRedisForTesting($config);
    }

    public function testEvalWithShaCacheExecutesScript(): void
    {
        $result = Redis::withConnection(function ($connection) {
            return $connection->evalWithShaCache(
                'return ARGV[1]',
                [],
                ['hello']
            );
        });

        $this->assertEquals('hello', $result);
    }

    public function testEvalWithShaCachePassesKeysAndArgs(): void
    {
        // Set up a key first
        Redis::set('testkey', 'testvalue');

        $result = Redis::withConnection(function ($connection) {
            return $connection->evalWithShaCache(
                'return redis.call("GET", KEYS[1])',
                ['testkey'],
                []
            );
        });

        $this->assertEquals('testvalue', $result);
    }

    public function testEvalWithShaCacheHandlesMultipleKeysAndArgs(): void
    {
        $result = Redis::withConnection(function ($connection) {
            return $connection->evalWithShaCache(
                'return {KEYS[1], KEYS[2], ARGV[1], ARGV[2]}',
                ['key1', 'key2'],
                ['arg1', 'arg2']
            );
        });

        // Keys are prefixed by OPT_PREFIX (redisTestPrefix), args are not
        $prefix = $this->getRedisTestPrefix();
        $this->assertEquals([$prefix . 'key1', $prefix . 'key2', 'arg1', 'arg2'], $result);
    }

    public function testEvalWithShaCacheUsesScriptCaching(): void
    {
        $script = 'return "cached"';

        // First call - should use eval (script not cached)
        $result1 = Redis::withConnection(function ($connection) use ($script) {
            return $connection->evalWithShaCache($script, [], []);
        });

        // Second call - should use evalSha (script now cached)
        $result2 = Redis::withConnection(function ($connection) use ($script) {
            return $connection->evalWithShaCache($script, [], []);
        });

        $this->assertEquals('cached', $result1);
        $this->assertEquals('cached', $result2);
    }

    public function testEvalWithShaCacheFallsBackToEvalOnNoscript(): void
    {
        $script = 'return "fallback_test"';
        $sha = sha1($script);

        // Flush script cache to ensure NOSCRIPT scenario
        Redis::client()->script('flush');

        // Verify script is not cached
        $exists = Redis::client()->script('exists', $sha);
        $this->assertEquals([0], $exists, 'Script should not be cached before test');

        // Call evalWithShaCache - should handle NOSCRIPT and fall back to eval
        $result = Redis::withConnection(function ($connection) use ($script) {
            return $connection->evalWithShaCache($script, [], []);
        });

        $this->assertEquals('fallback_test', $result);

        // Verify script is now cached after successful eval
        $exists = Redis::client()->script('exists', $sha);
        $this->assertEquals([1], $exists, 'Script should be cached after eval');
    }

    public function testEvalWithShaCacheThrowsOnSyntaxError(): void
    {
        $this->expectException(LuaScriptException::class);
        $this->expectExceptionMessage('Lua script execution failed');

        Redis::withConnection(function ($connection) {
            return $connection->evalWithShaCache(
                'this is not valid lua syntax!!!',
                [],
                []
            );
        });
    }

    public function testEvalWithShaCacheThrowsOnRuntimeError(): void
    {
        $this->expectException(LuaScriptException::class);

        Redis::withConnection(function ($connection) {
            // Call a non-existent Redis command
            return $connection->evalWithShaCache(
                'return redis.call("NONEXISTENT_COMMAND")',
                [],
                []
            );
        });
    }

    public function testEvalWithShaCacheReturnsNilAsFalse(): void
    {
        $result = Redis::withConnection(function ($connection) {
            return $connection->evalWithShaCache('return nil', [], []);
        });

        $this->assertFalse($result);
    }

    public function testEvalWithShaCacheReturnsTable(): void
    {
        $result = Redis::withConnection(function ($connection) {
            return $connection->evalWithShaCache(
                'return {"a", "b", "c"}',
                [],
                []
            );
        });

        $this->assertEquals(['a', 'b', 'c'], $result);
    }

    public function testEvalWithShaCacheReturnsNumber(): void
    {
        $result = Redis::withConnection(function ($connection) {
            return $connection->evalWithShaCache('return 42', [], []);
        });

        $this->assertEquals(42, $result);
    }
}
