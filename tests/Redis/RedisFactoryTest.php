<?php

declare(strict_types=1);

namespace Hypervel\Tests\Redis;

use Hypervel\Contracts\Container\Container as ContainerContract;
use Hyperf\Contract\ConfigInterface;
use Hypervel\Redis\RedisConfig;
use Hypervel\Redis\RedisFactory;
use Hypervel\Redis\RedisProxy;
use Hypervel\Redis\Exceptions\InvalidRedisProxyException;
use Hypervel\Tests\TestCase;
use Mockery as m;
use ReflectionClass;

/**
 * Tests for RedisFactory.
 *
 * Note: The constructor uses `make()` which requires a container,
 * so we test the `get()` method by setting up proxies via reflection.
 *
 * @internal
 * @coversNothing
 */
class RedisFactoryTest extends TestCase
{
    public function testGetReturnsProxyForConfiguredPool(): void
    {
        $factory = $this->createFactoryWithProxies([
            'default' => m::mock(RedisProxy::class),
            'cache' => m::mock(RedisProxy::class),
        ]);

        $proxy = $factory->get('default');

        $this->assertInstanceOf(RedisProxy::class, $proxy);
    }

    public function testGetReturnsDifferentProxiesForDifferentPools(): void
    {
        $defaultProxy = m::mock(RedisProxy::class);
        $cacheProxy = m::mock(RedisProxy::class);

        $factory = $this->createFactoryWithProxies([
            'default' => $defaultProxy,
            'cache' => $cacheProxy,
        ]);

        $this->assertSame($defaultProxy, $factory->get('default'));
        $this->assertSame($cacheProxy, $factory->get('cache'));
    }

    public function testGetThrowsExceptionForUnconfiguredPool(): void
    {
        $factory = $this->createFactoryWithProxies([
            'default' => m::mock(RedisProxy::class),
        ]);

        $this->expectException(InvalidRedisProxyException::class);
        $this->expectExceptionMessage('Invalid Redis proxy.');

        $factory->get('nonexistent');
    }

    public function testGetReturnsSameProxyInstanceOnMultipleCalls(): void
    {
        $proxy = m::mock(RedisProxy::class);

        $factory = $this->createFactoryWithProxies([
            'default' => $proxy,
        ]);

        $first = $factory->get('default');
        $second = $factory->get('default');

        $this->assertSame($first, $second);
    }

    /**
     * Create a RedisFactory with pre-configured proxies (bypassing constructor).
     *
     * @param array<string, m\MockInterface|RedisProxy> $proxies
     */
    private function createFactoryWithProxies(array $proxies): RedisFactory
    {
        // Create factory with empty config (no pools created)
        $config = m::mock(ConfigInterface::class);
        $config->shouldReceive('get')->with('database.redis')->andReturn([]);
        $redisConfig = new RedisConfig($config);
        $container = m::mock(ContainerContract::class);

        $factory = new RedisFactory($redisConfig, $container);

        // Inject proxies via reflection
        $reflection = new ReflectionClass($factory);
        $property = $reflection->getProperty('proxies');
        $property->setAccessible(true);
        $property->setValue($factory, $proxies);

        return $factory;
    }
}
