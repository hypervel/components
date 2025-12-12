<?php

declare(strict_types=1);

namespace Hypervel\Redis\Tests;

use Hyperf\Contract\ConfigInterface;
use Hyperf\Redis\Exception\InvalidRedisProxyException;
use Hypervel\Redis\RedisFactory;
use Hypervel\Redis\RedisProxy;
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
    /**
     * @test
     */
    public function testGetReturnsProxyForConfiguredPool(): void
    {
        $factory = $this->createFactoryWithProxies([
            'default' => m::mock(RedisProxy::class),
            'cache' => m::mock(RedisProxy::class),
        ]);

        $proxy = $factory->get('default');

        $this->assertInstanceOf(RedisProxy::class, $proxy);
    }

    /**
     * @test
     */
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

    /**
     * @test
     */
    public function testGetThrowsExceptionForUnconfiguredPool(): void
    {
        $factory = $this->createFactoryWithProxies([
            'default' => m::mock(RedisProxy::class),
        ]);

        $this->expectException(InvalidRedisProxyException::class);
        $this->expectExceptionMessage('Invalid Redis proxy.');

        $factory->get('nonexistent');
    }

    /**
     * @test
     */
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
        $config->shouldReceive('get')->with('redis')->andReturn([]);

        $factory = new RedisFactory($config);

        // Inject proxies via reflection
        $reflection = new ReflectionClass($factory);
        $property = $reflection->getProperty('proxies');
        $property->setAccessible(true);
        $property->setValue($factory, $proxies);

        return $factory;
    }
}
