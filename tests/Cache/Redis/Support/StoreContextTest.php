<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis\Support;

use Hypervel\Cache\Redis\Support\StoreContext;
use Hypervel\Cache\TagMode;
use Hypervel\Contracts\Redis\Factory as RedisFactory;
use Hypervel\Redis\PhpRedisConnection;
use Hypervel\Redis\RedisProxy;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Tests\Redis\Fixtures\PhpRedisConnectionStub;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use Redis;
use RuntimeException;

class StoreContextTest extends TestCase
{
    use VerifiesDoubles;

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

    public function testExpirationScoreNeverPrecedesRequestedLifetime(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestampUTC('1000.900000'));

        $this->assertSame(1002, $this->createContext()->expirationScore(1));
    }

    public function testWithConnectionExecutesCallbackAndReturnsResult(): void
    {
        $connection = Double::for(PhpRedisConnection::class);
        $context = $this->createContextWithRedisFactory('default', function ($callback) use ($connection) {
            return $callback($connection);
        });

        $result = $context->withConnection(function ($actualConnection) use ($connection) {
            $this->assertSame($connection, $actualConnection);

            return 'callback-result';
        });

        $this->assertSame('callback-result', $result);
    }

    public function testWithConnectionCanEnableLaravelStyleTransforms(): void
    {
        $connection = Double::for(PhpRedisConnection::class);
        $transform = null;
        $context = $this->createContextWithRedisFactory(
            'default',
            function ($callback, bool $shouldTransform) use ($connection, &$transform) {
                $transform = $shouldTransform;

                return $callback($connection);
            },
        );

        $result = $context->withConnection(fn () => 'callback-result', transform: true);

        $this->assertTrue($transform);
        $this->assertSame('callback-result', $result);
    }

    public function testWithConnectionPropagatesExceptions(): void
    {
        $context = $this->createContextWithRedisFactory('default', function ($callback) {
            return $callback(Double::for(PhpRedisConnection::class));
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('Test exception');

        $context->withConnection(function () {
            throw new RuntimeException('Test exception');
        });
    }

    public function testIsClusterReturnsTrueForRedisCluster(): void
    {
        $context = $this->createContextWithRedisFactory('default', function ($callback) {
            return $callback(Double::for(PhpRedisConnection::class));
        }, isCluster: true);

        $this->assertTrue($context->isCluster());
    }

    public function testIsClusterReturnsFalseForRegularRedis(): void
    {
        $context = $this->createContextWithRedisFactory('default', function ($callback) {
            return $callback(Double::for(PhpRedisConnection::class));
        }, isCluster: false);

        $this->assertFalse($context->isCluster());
    }

    public function testOptPrefixReturnsRedisOptionPrefix(): void
    {
        $client = Double::for(Redis::class)->strict();
        $connection = (new PhpRedisConnectionStub)->setActiveConnection($client)->shouldTransform(false);
        $client->allows('getOption')
            ->with(Redis::OPT_PREFIX)
            ->returns('redis_prefix:');

        $context = $this->createContext(prefix: 'cache:');

        $this->assertSame('redis_prefix:', $context->optPrefix($connection));
    }

    public function testOptPrefixReturnsEmptyStringWhenNotSet(): void
    {
        $client = Double::for(Redis::class)->strict();
        $connection = (new PhpRedisConnectionStub)->setActiveConnection($client)->shouldTransform(false);
        $client->allows('getOption')
            ->with(Redis::OPT_PREFIX)
            ->returns(null);

        $context = $this->createContext(prefix: 'cache:');

        $this->assertSame('', $context->optPrefix($connection));
    }

    public function testFullTagPrefixIncludesOptPrefix(): void
    {
        $client = Double::for(Redis::class)->strict();
        $connection = (new PhpRedisConnectionStub)->setActiveConnection($client)->shouldTransform(false);
        $client->allows('getOption')
            ->with(Redis::OPT_PREFIX)
            ->returns('redis:');

        $context = $this->createContext(prefix: 'cache:');

        $this->assertSame('redis:cache:_any:tag:', $context->fullTagPrefix($connection));
    }

    public function testFullRegistryKeyIncludesOptPrefix(): void
    {
        $client = Double::for(Redis::class)->strict();
        $connection = (new PhpRedisConnectionStub)->setActiveConnection($client)->shouldTransform(false);
        $client->allows('getOption')
            ->with(Redis::OPT_PREFIX)
            ->returns('redis:');

        $context = $this->createContext(prefix: 'cache:');

        $this->assertSame('redis:cache:_any:tag:registry', $context->fullRegistryKey($connection));
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
        return new StoreContext(Double::for(RedisFactory::class)->strict(), $connectionName, $prefix, $tagMode);
    }

    /**
     * Create a context with a mocked Redis factory.
     */
    private function createContextWithRedisFactory(
        string $expectedConnectionName,
        callable $withConnectionHandler,
        string $prefix = 'prefix:',
        ?string $contextConnectionName = null,
        bool $isCluster = false,
    ): StoreContext {
        $contextConnectionName ??= $expectedConnectionName;

        $redisProxy = Double::for(RedisProxy::class)->strict();
        $redisProxy->allows('withConnection')
            ->resolves($withConnectionHandler);
        $redisProxy->allows('isCluster')
            ->returns($isCluster);

        $redisFactory = Double::for(RedisFactory::class)->strict();
        $redisFactory->allows('connection')
            ->with($expectedConnectionName)
            ->returns($redisProxy);

        return new StoreContext($redisFactory, $contextConnectionName, $prefix, TagMode::Any);
    }
}
