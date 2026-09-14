<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache;

use Closure;
use Hypervel\Cache\CacheManager;
use Hypervel\Config\Repository as ConfigRepository;
use Hypervel\Container\Container;
use Hypervel\Support\Facades\Cache;
use Hypervel\Support\Facades\Facade;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Mockery\LegacyMockInterface;

class CacheSpyMemoTest extends TestCase
{
    /**
     * Set up the cache facade with a plain container.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // CacheManager accepts the generic container contract and must not depend on Application aliases.
        $container = new Container;

        $container->instance('config', new ConfigRepository([
            'cache' => [
                'default' => 'array',
                'stores' => [
                    'array' => [
                        'driver' => 'array',
                    ],
                ],
            ],
        ]));

        $container->instance('cache', new CacheManager($container));

        Facade::setFacadeApplication($container);
    }

    /**
     * Release the test's facade application.
     */
    protected function tearDown(): void
    {
        Facade::setFacadeApplication(null);

        parent::tearDown();
    }

    public function testCacheSpyWorksWithMemoizedCache(): void
    {
        $cache = Cache::spy();

        Cache::memo()->remember('key', 60, fn (): string => 'bar');

        $cache->shouldHaveReceived('memo')->once();
    }

    public function testCacheSpyTracksRememberOnMemoizedCacheAsDescribedInIssue(): void
    {
        Cache::spy();

        $memoizedCache = Cache::memo();
        $value = $memoizedCache->remember('key', 60, fn (): string => 'bar');

        $this->assertSame('bar', $value);

        $memoizedCache->shouldHaveReceived('remember')->once()->with('key', 60, m::type(Closure::class));
    }

    public function testCacheSpyTracksRememberCallsOnMemoizedCache(): void
    {
        Cache::spy();

        $memoizedCache = Cache::memo();
        $memoizedCache->remember('key', 60, fn (): string => 'bar');

        $memoizedCache->shouldHaveReceived('remember')->once()->with('key', 60, m::type(Closure::class));
    }

    public function testCacheSpyMemoReturnsSpiedRepository(): void
    {
        Cache::spy();

        $memoizedCache = Cache::memo();

        $this->assertInstanceOf(LegacyMockInterface::class, $memoizedCache);

        $memoizedCache->remember('key', 60, fn (): string => 'bar');

        $memoizedCache->shouldHaveReceived('remember')->once();
    }
}
