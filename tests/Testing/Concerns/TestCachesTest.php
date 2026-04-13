<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testing\Concerns;

use Generator;
use Hypervel\Cache\CacheManager;
use Hypervel\Config\Repository as Config;
use Hypervel\Container\Container;
use Hypervel\Support\Facades\Facade;
use Hypervel\Support\Facades\ParallelTesting as ParallelTestingFacade;
use Hypervel\Testing\Concerns\TestCaches;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use ReflectionProperty;

/**
 * @internal
 * @coversNothing
 */
class TestCachesTest extends TestCase
{
    private mixed $originalParallelTesting;

    protected function setUp(): void
    {
        $this->originalParallelTesting = $_SERVER['HYPERVEL_PARALLEL_TESTING'] ?? null;

        parent::setUp();

        Container::setInstance($container = new Container);

        Facade::setFacadeApplication($container);

        $container->singleton('config', fn () => new Config([
            'cache' => [
                'prefix' => 'myapp_cache_',
            ],
        ]));

        $container->singleton(ParallelTesting::class, fn ($app) => new ParallelTesting($app));

        $_SERVER['HYPERVEL_PARALLEL_TESTING'] = 1;
    }

    protected function tearDown(): void
    {
        Container::setInstance(null);
        ParallelTestingFacade::clearResolvedInstance();
        Facade::setFacadeApplication(null);

        if ($this->originalParallelTesting === null) {
            unset($_SERVER['HYPERVEL_PARALLEL_TESTING']);
        } else {
            $_SERVER['HYPERVEL_PARALLEL_TESTING'] = $this->originalParallelTesting;
        }

        $instance = new class {
            use TestCaches;

            public $app;

            public function __construct()
            {
                $this->app = Container::getInstance() ?? new Container;
            }
        };

        (new ReflectionProperty($instance::class, 'originalCachePrefix'))->setValue(null, null);

        parent::tearDown();
    }

    #[DataProvider('cachePrefixes')]
    public function testCachePrefixAppendsToken(string $prefix, string $token, string $expected)
    {
        Container::getInstance()['config']->set('cache.prefix', $prefix);
        Container::getInstance()->make(ParallelTesting::class)->resolveTokenUsing(fn () => $token);

        $this->assertSame($expected, $this->getParallelSafeCachePrefix());
    }

    public static function cachePrefixes(): Generator
    {
        yield 'with prefix' => ['myapp_cache_', '5', 'myapp_cache_test_5_'];
        yield 'empty prefix' => ['', '3', 'test_3_'];
    }

    public function testCachePrefixPreservesOriginalPrefix()
    {
        Container::getInstance()->make(ParallelTesting::class)->resolveTokenUsing(fn () => '1');

        $this->getParallelSafeCachePrefix();

        Container::getInstance()->make(ParallelTesting::class)->resolveTokenUsing(fn () => '2');

        $this->assertSame('myapp_cache_test_2_', $this->getParallelSafeCachePrefix());
    }

    public function testSwitchToCachePrefixUpdatesConfig()
    {
        $this->switchToCachePrefix('new_prefix_');

        $this->assertSame('new_prefix_', Container::getInstance()['config']->get('cache.prefix'));
    }

    public function testBootTestCacheRegistersSetUpTestCaseCallback()
    {
        Container::getInstance()->make(ParallelTesting::class)->resolveTokenUsing(fn () => '7');

        $instance = $this->makeTestCachesInstance();

        (new ReflectionProperty($instance::class, 'originalCachePrefix'))->setValue(null, null);

        $method = new ReflectionMethod($instance, 'bootTestCache');
        $method->invoke($instance);

        $parallelTesting = Container::getInstance()->make(ParallelTesting::class);
        $setUpCallbacks = (new ReflectionProperty($parallelTesting, 'setUpTestCaseCallbacks'))->getValue($parallelTesting);

        $this->assertCount(1, $setUpCallbacks);
    }

    public function testBootTestCacheSkipsIsolationIfOptedOut()
    {
        Container::getInstance()->make(ParallelTesting::class)->resolveTokenUsing(fn () => '7');

        $instance = $this->makeTestCachesInstance();

        (new ReflectionProperty($instance::class, 'originalCachePrefix'))->setValue(null, null);
        (new ReflectionMethod($instance, 'bootTestCache'))->invoke($instance);

        $_SERVER['HYPERVEL_PARALLEL_TESTING_WITHOUT_CACHE'] = 1;

        Container::getInstance()->make(ParallelTesting::class)->callSetUpTestCaseCallbacks(new class {});

        $this->assertSame('myapp_cache_', Container::getInstance()['config']->get('cache.prefix'));

        unset($_SERVER['HYPERVEL_PARALLEL_TESTING_WITHOUT_CACHE']);
    }

    public function testSwitchToCachePrefixDoesNotRemoveResolvedDrivers()
    {
        $container = Container::getInstance();

        $container->singleton('cache', fn ($app) => new CacheManager($app));

        $container['config']->set('cache.default', 'array');
        $container['config']->set('cache.stores.array', ['driver' => 'array']);

        $driver = $container['cache']->driver();

        $this->switchToCachePrefix('new_prefix_');

        $this->assertSame($driver, $container['cache']->driver());
    }

    protected function getParallelSafeCachePrefix(): string
    {
        $instance = $this->makeTestCachesInstance();

        (new ReflectionProperty($instance::class, 'originalCachePrefix'))->setValue(null, null);

        $method = new ReflectionMethod($instance, 'parallelSafeCachePrefix');

        return $method->invoke($instance);
    }

    protected function switchToCachePrefix(string $prefix): void
    {
        $instance = $this->makeTestCachesInstance();

        $method = new ReflectionMethod($instance, 'switchToCachePrefix');
        $method->invoke($instance, $prefix);
    }

    protected function makeTestCachesInstance(): object
    {
        return new class {
            use TestCaches;

            public $app;

            public function __construct()
            {
                $this->app = Container::getInstance();
            }
        };
    }
}
