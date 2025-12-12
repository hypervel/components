<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis\Support;

use Hyperf\Redis\Pool\PoolFactory;
use Hyperf\Redis\Pool\RedisPool;
use Hypervel\Cache\Redis\Support\StoreContext;
use Hypervel\Cache\Redis\TagMode;
use Hypervel\Redis\RedisConnection;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Redis;
use RedisCluster;
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

    public function testWithConnectionGetsConnectionFromPoolAndReleasesIt(): void
    {
        $poolFactory = m::mock(PoolFactory::class);
        $pool = m::mock(RedisPool::class);
        $connection = m::mock(RedisConnection::class);

        $poolFactory->shouldReceive('getPool')
            ->once()
            ->with('default')
            ->andReturn($pool);

        $pool->shouldReceive('get')
            ->once()
            ->andReturn($connection);

        $connection->shouldReceive('release')
            ->once();

        $context = new StoreContext($poolFactory, 'default', 'prefix:', TagMode::Any);

        $result = $context->withConnection(function ($conn) use ($connection) {
            $this->assertSame($connection, $conn);
            return 'callback-result';
        });

        $this->assertSame('callback-result', $result);
    }

    public function testWithConnectionReleasesConnectionOnException(): void
    {
        $poolFactory = m::mock(PoolFactory::class);
        $pool = m::mock(RedisPool::class);
        $connection = m::mock(RedisConnection::class);

        $poolFactory->shouldReceive('getPool')
            ->once()
            ->with('default')
            ->andReturn($pool);

        $pool->shouldReceive('get')
            ->once()
            ->andReturn($connection);

        $connection->shouldReceive('release')
            ->once();

        $context = new StoreContext($poolFactory, 'default', 'prefix:', TagMode::Any);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Test exception');

        $context->withConnection(function () {
            throw new RuntimeException('Test exception');
        });
    }

    public function testIsClusterReturnsTrueForRedisCluster(): void
    {
        $poolFactory = m::mock(PoolFactory::class);
        $pool = m::mock(RedisPool::class);
        $connection = m::mock(RedisConnection::class);
        $client = m::mock(RedisCluster::class);

        $poolFactory->shouldReceive('getPool')->andReturn($pool);
        $pool->shouldReceive('get')->andReturn($connection);
        $connection->shouldReceive('client')->andReturn($client);
        $connection->shouldReceive('release');

        $context = new StoreContext($poolFactory, 'default', 'prefix:', TagMode::Any);

        $this->assertTrue($context->isCluster());
    }

    public function testIsClusterReturnsFalseForRegularRedis(): void
    {
        $poolFactory = m::mock(PoolFactory::class);
        $pool = m::mock(RedisPool::class);
        $connection = m::mock(RedisConnection::class);
        $client = m::mock(Redis::class);

        $poolFactory->shouldReceive('getPool')->andReturn($pool);
        $pool->shouldReceive('get')->andReturn($connection);
        $connection->shouldReceive('client')->andReturn($client);
        $connection->shouldReceive('release');

        $context = new StoreContext($poolFactory, 'default', 'prefix:', TagMode::Any);

        $this->assertFalse($context->isCluster());
    }

    public function testOptPrefixReturnsRedisOptionPrefix(): void
    {
        $poolFactory = m::mock(PoolFactory::class);
        $pool = m::mock(RedisPool::class);
        $connection = m::mock(RedisConnection::class);
        $client = m::mock(Redis::class);

        $poolFactory->shouldReceive('getPool')->andReturn($pool);
        $pool->shouldReceive('get')->andReturn($connection);
        $connection->shouldReceive('client')->andReturn($client);
        $connection->shouldReceive('release');
        $client->shouldReceive('getOption')
            ->with(Redis::OPT_PREFIX)
            ->andReturn('redis_prefix:');

        $context = new StoreContext($poolFactory, 'default', 'cache:', TagMode::Any);

        $this->assertSame('redis_prefix:', $context->optPrefix());
    }

    public function testOptPrefixReturnsEmptyStringWhenNotSet(): void
    {
        $poolFactory = m::mock(PoolFactory::class);
        $pool = m::mock(RedisPool::class);
        $connection = m::mock(RedisConnection::class);
        $client = m::mock(Redis::class);

        $poolFactory->shouldReceive('getPool')->andReturn($pool);
        $pool->shouldReceive('get')->andReturn($connection);
        $connection->shouldReceive('client')->andReturn($client);
        $connection->shouldReceive('release');
        $client->shouldReceive('getOption')
            ->with(Redis::OPT_PREFIX)
            ->andReturn(null);

        $context = new StoreContext($poolFactory, 'default', 'cache:', TagMode::Any);

        $this->assertSame('', $context->optPrefix());
    }

    public function testFullTagPrefixIncludesOptPrefix(): void
    {
        $poolFactory = m::mock(PoolFactory::class);
        $pool = m::mock(RedisPool::class);
        $connection = m::mock(RedisConnection::class);
        $client = m::mock(Redis::class);

        $poolFactory->shouldReceive('getPool')->andReturn($pool);
        $pool->shouldReceive('get')->andReturn($connection);
        $connection->shouldReceive('client')->andReturn($client);
        $connection->shouldReceive('release');
        $client->shouldReceive('getOption')
            ->with(Redis::OPT_PREFIX)
            ->andReturn('redis:');

        $context = new StoreContext($poolFactory, 'default', 'cache:', TagMode::Any);

        $this->assertSame('redis:cache:_any:tag:', $context->fullTagPrefix());
    }

    public function testFullReverseIndexKeyIncludesOptPrefix(): void
    {
        $poolFactory = m::mock(PoolFactory::class);
        $pool = m::mock(RedisPool::class);
        $connection = m::mock(RedisConnection::class);
        $client = m::mock(Redis::class);

        $poolFactory->shouldReceive('getPool')->andReturn($pool);
        $pool->shouldReceive('get')->andReturn($connection);
        $connection->shouldReceive('client')->andReturn($client);
        $connection->shouldReceive('release');
        $client->shouldReceive('getOption')
            ->with(Redis::OPT_PREFIX)
            ->andReturn('redis:');

        $context = new StoreContext($poolFactory, 'default', 'cache:', TagMode::Any);

        $this->assertSame('redis:cache:user:1:_any:tags', $context->fullReverseIndexKey('user:1'));
    }

    public function testFullRegistryKeyIncludesOptPrefix(): void
    {
        $poolFactory = m::mock(PoolFactory::class);
        $pool = m::mock(RedisPool::class);
        $connection = m::mock(RedisConnection::class);
        $client = m::mock(Redis::class);

        $poolFactory->shouldReceive('getPool')->andReturn($pool);
        $pool->shouldReceive('get')->andReturn($connection);
        $connection->shouldReceive('client')->andReturn($client);
        $connection->shouldReceive('release');
        $client->shouldReceive('getOption')
            ->with(Redis::OPT_PREFIX)
            ->andReturn('redis:');

        $context = new StoreContext($poolFactory, 'default', 'cache:', TagMode::Any);

        $this->assertSame('redis:cache:_any:tag:registry', $context->fullRegistryKey());
    }

    public function testConstantsHaveExpectedValues(): void
    {
        $this->assertSame(253402300799, StoreContext::MAX_EXPIRY);
        $this->assertSame('1', StoreContext::TAG_FIELD_VALUE);
    }

    private function createContext(
        string $connectionName = 'default',
        string $prefix = 'prefix:',
        TagMode $tagMode = TagMode::Any
    ): StoreContext {
        $poolFactory = m::mock(PoolFactory::class);

        return new StoreContext($poolFactory, $connectionName, $prefix, $tagMode);
    }
}
