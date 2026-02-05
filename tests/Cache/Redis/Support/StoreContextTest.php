<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis\Support;

use Hypervel\Cache\Redis\Support\StoreContext;
use Hypervel\Cache\Redis\TagMode;
use Hypervel\Redis\RedisConnection;
use Hypervel\Redis\RedisFactory;
use Hypervel\Redis\RedisProxy;
use Hypervel\Testbench\TestCase;
use Mockery as m;
use Redis;
use RuntimeException;

/**
 * @internal
 * @coversNothing
 */
class StoreContextTest extends TestCase
{
    public function testPrefixReturnsConfiguredPrefix(): void
    {
        $context = $this->createContext(prefix: 'myapp:');

        $this->assertSame('myapp:', $context->prefix());
    }

    public function testConnectionNameReturnsConfiguredConnectionName(): void
    {
        $context = $this->createContext(connectionName: 'cache');

        $this->assertSame('cache', $context->connectionName());
    }

    public function testTagScanPatternCombinesPrefixWithTagSegment(): void
    {
        $context = $this->createContext(prefix: 'myapp:');

        $this->assertSame('myapp:_any:tag:*:entries', $context->tagScanPattern());
    }

    public function testTagHashKeyBuildsCorrectFormat(): void
    {
        $context = $this->createContext(prefix: 'myapp:');

        $this->assertSame('myapp:_any:tag:users:entries', $context->tagHashKey('users'));
        $this->assertSame('myapp:_any:tag:posts:entries', $context->tagHashKey('posts'));
    }

    public function testTagHashSuffixReturnsConstant(): void
    {
        $context = $this->createContext();

        $this->assertSame(':entries', $context->tagHashSuffix());
    }

    public function testReverseIndexKeyBuildsCorrectFormat(): void
    {
        $context = $this->createContext(prefix: 'myapp:');

        $this->assertSame('myapp:user:1:_any:tags', $context->reverseIndexKey('user:1'));
        $this->assertSame('myapp:post:42:_any:tags', $context->reverseIndexKey('post:42'));
    }

    public function testRegistryKeyBuildsCorrectFormat(): void
    {
        $context = $this->createContext(prefix: 'myapp:');

        $this->assertSame('myapp:_any:tag:registry', $context->registryKey());
    }

    public function testWithConnectionExecutesCallbackAndReturnsResult(): void
    {
        $connection = m::mock(RedisConnection::class);
        $context = $this->createContextWithRedisFactory('default', function ($callback) use ($connection) {
            return $callback($connection);
        });

        $result = $context->withConnection(function ($conn) use ($connection) {
            $this->assertSame($connection, $conn);

            return 'callback-result';
        });

        $this->assertSame('callback-result', $result);
    }

    public function testWithConnectionPropagatesExceptions(): void
    {
        $context = $this->createContextWithRedisFactory('default', function ($callback) {
            return $callback(m::mock(RedisConnection::class));
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Test exception');

        $context->withConnection(function () {
            throw new RuntimeException('Test exception');
        });
    }

    public function testIsClusterReturnsTrueForRedisCluster(): void
    {
        $connection = m::mock(RedisConnection::class);
        $connection->shouldReceive('isCluster')->andReturn(true);

        $context = $this->createContextWithRedisFactory('default', function ($callback) use ($connection) {
            return $callback($connection);
        });

        $this->assertTrue($context->isCluster());
    }

    public function testIsClusterReturnsFalseForRegularRedis(): void
    {
        $connection = m::mock(RedisConnection::class);
        $connection->shouldReceive('isCluster')->andReturn(false);

        $context = $this->createContextWithRedisFactory('default', function ($callback) use ($connection) {
            return $callback($connection);
        });

        $this->assertFalse($context->isCluster());
    }

    public function testOptPrefixReturnsRedisOptionPrefix(): void
    {
        $connection = m::mock(RedisConnection::class);
        $connection->shouldReceive('getOption')
            ->with(Redis::OPT_PREFIX)
            ->andReturn('redis_prefix:');

        $context = $this->createContextWithRedisFactory('default', function ($callback) use ($connection) {
            return $callback($connection);
        }, 'cache:');

        $this->assertSame('redis_prefix:', $context->optPrefix());
    }

    public function testOptPrefixReturnsEmptyStringWhenNotSet(): void
    {
        $connection = m::mock(RedisConnection::class);
        $connection->shouldReceive('getOption')
            ->with(Redis::OPT_PREFIX)
            ->andReturn(null);

        $context = $this->createContextWithRedisFactory('default', function ($callback) use ($connection) {
            return $callback($connection);
        }, 'cache:');

        $this->assertSame('', $context->optPrefix());
    }

    public function testFullTagPrefixIncludesOptPrefix(): void
    {
        $connection = m::mock(RedisConnection::class);
        $connection->shouldReceive('getOption')
            ->with(Redis::OPT_PREFIX)
            ->andReturn('redis:');

        $context = $this->createContextWithRedisFactory('default', function ($callback) use ($connection) {
            return $callback($connection);
        }, 'cache:');

        $this->assertSame('redis:cache:_any:tag:', $context->fullTagPrefix());
    }

    public function testFullReverseIndexKeyIncludesOptPrefix(): void
    {
        $connection = m::mock(RedisConnection::class);
        $connection->shouldReceive('getOption')
            ->with(Redis::OPT_PREFIX)
            ->andReturn('redis:');

        $context = $this->createContextWithRedisFactory('default', function ($callback) use ($connection) {
            return $callback($connection);
        }, 'cache:');

        $this->assertSame('redis:cache:user:1:_any:tags', $context->fullReverseIndexKey('user:1'));
    }

    public function testFullRegistryKeyIncludesOptPrefix(): void
    {
        $connection = m::mock(RedisConnection::class);
        $connection->shouldReceive('getOption')
            ->with(Redis::OPT_PREFIX)
            ->andReturn('redis:');

        $context = $this->createContextWithRedisFactory('default', function ($callback) use ($connection) {
            return $callback($connection);
        }, 'cache:');

        $this->assertSame('redis:cache:_any:tag:registry', $context->fullRegistryKey());
    }

    public function testConstantsHaveExpectedValues(): void
    {
        $this->assertSame(253402300799, StoreContext::MAX_EXPIRY);
        $this->assertSame('1', StoreContext::TAG_FIELD_VALUE);
    }

    /**
     * Create a basic context for tests that don't need withConnection mocking.
     */
    private function createContext(
        string $connectionName = 'default',
        string $prefix = 'prefix:',
        TagMode $tagMode = TagMode::Any
    ): StoreContext {
        return new StoreContext($connectionName, $prefix, $tagMode);
    }

    /**
     * Create a context with RedisFactory mocked in the container.
     */
    private function createContextWithRedisFactory(
        string $expectedConnectionName,
        callable $withConnectionHandler,
        string $prefix = 'prefix:',
        ?string $contextConnectionName = null
    ): StoreContext {
        $contextConnectionName ??= $expectedConnectionName;

        $redisProxy = m::mock(RedisProxy::class);
        $redisProxy->shouldReceive('withConnection')
            ->andReturnUsing($withConnectionHandler);

        $redisFactory = m::mock(RedisFactory::class);
        $redisFactory->shouldReceive('get')
            ->with($expectedConnectionName)
            ->andReturn($redisProxy);

        // Register mock in the testbench container
        $this->instance(RedisFactory::class, $redisFactory);

        return new StoreContext($contextConnectionName, $prefix, TagMode::Any);
    }
}
