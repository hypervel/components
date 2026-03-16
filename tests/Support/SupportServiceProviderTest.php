<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support;

use Hypervel\Config\Repository as ConfigRepository;
use Hypervel\Foundation\Application;
use Hypervel\Support\ServiceProvider;
use Mockery as m;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 * @coversNothing
 */
class SupportServiceProviderTest extends TestCase
{
    protected Application $app;

    protected function setUp(): void
    {
        $this->app = $app = m::mock(Application::class)->makePartial();

        $one = new ServiceProviderForTestingOne($app);
        $one->boot();
        $two = new ServiceProviderForTestingTwo($app);
        $two->boot();
    }

    public function testPublishableServiceProviders()
    {
        $toPublish = ServiceProvider::publishableProviders();
        $expected = [
            ServiceProviderForTestingOne::class,
            ServiceProviderForTestingTwo::class,
        ];
        $this->assertEquals($expected, $toPublish, 'Publishable service providers do not return expected set of providers.');
    }

    public function testPublishableGroups()
    {
        $toPublish = ServiceProvider::publishableGroups();
        $this->assertEquals([
            'some_tag',
            'tag_one',
            'tag_two',
            'tag_three',
            'tag_four',
            'tag_five',
        ], $toPublish, 'Publishable groups do not return expected set of groups.');
    }

    public function testSimpleAssetsArePublishedCorrectly()
    {
        $toPublish = ServiceProvider::pathsToPublish(ServiceProviderForTestingOne::class);
        $this->assertArrayHasKey('source/unmarked/one', $toPublish, 'Service provider does not return expected published path key.');
        $this->assertArrayHasKey('source/tagged/one', $toPublish, 'Service provider does not return expected published path key.');
        $this->assertEquals([
            'source/unmarked/one' => 'destination/unmarked/one',
            'source/tagged/one' => 'destination/tagged/one',
            'source/tagged/multiple' => 'destination/tagged/multiple',
            'source/unmarked/two' => 'destination/unmarked/two',
            'source/tagged/three' => 'destination/tagged/three',
            'source/tagged/multiple_two' => 'destination/tagged/multiple_two',
        ], $toPublish, 'Service provider does not return expected set of published paths.');
    }

    public function testMultipleAssetsArePublishedCorrectly()
    {
        $toPublish = ServiceProvider::pathsToPublish(ServiceProviderForTestingTwo::class);
        $this->assertArrayHasKey('source/unmarked/two/a', $toPublish, 'Service provider does not return expected published path key.');
        $this->assertArrayHasKey('source/unmarked/two/b', $toPublish, 'Service provider does not return expected published path key.');
        $this->assertArrayHasKey('source/unmarked/two/c', $toPublish, 'Service provider does not return expected published path key.');
        $this->assertArrayHasKey('source/tagged/two/a', $toPublish, 'Service provider does not return expected published path key.');
        $this->assertArrayHasKey('source/tagged/two/b', $toPublish, 'Service provider does not return expected published path key.');
        $expected = [
            'source/unmarked/two/a' => 'destination/unmarked/two/a',
            'source/unmarked/two/b' => 'destination/unmarked/two/b',
            'source/unmarked/two/c' => 'destination/tagged/two/a',
            'source/tagged/two/a' => 'destination/tagged/two/a',
            'source/tagged/two/b' => 'destination/tagged/two/b',
        ];
        $this->assertEquals($expected, $toPublish, 'Service provider does not return expected set of published paths.');
    }

    public function testSimpleTaggedAssetsArePublishedCorrectly()
    {
        $toPublish = ServiceProvider::pathsToPublish(ServiceProviderForTestingOne::class, 'some_tag');
        $this->assertArrayNotHasKey('source/tagged/two/a', $toPublish, 'Service provider does return unexpected tagged path key.');
        $this->assertArrayNotHasKey('source/tagged/two/b', $toPublish, 'Service provider does return unexpected tagged path key.');
        $this->assertArrayHasKey('source/tagged/one', $toPublish, 'Service provider does not return expected tagged path key.');
        $this->assertEquals(['source/tagged/one' => 'destination/tagged/one'], $toPublish, 'Service provider does not return expected set of published tagged paths.');
    }

    public function testMultipleTaggedAssetsArePublishedCorrectly()
    {
        $toPublish = ServiceProvider::pathsToPublish(ServiceProviderForTestingTwo::class, 'some_tag');
        $this->assertArrayHasKey('source/tagged/two/a', $toPublish, 'Service provider does not return expected tagged path key.');
        $this->assertArrayHasKey('source/tagged/two/b', $toPublish, 'Service provider does not return expected tagged path key.');
        $this->assertArrayNotHasKey('source/tagged/one', $toPublish, 'Service provider does return unexpected tagged path key.');
        $this->assertArrayNotHasKey('source/unmarked/two/c', $toPublish, 'Service provider does return unexpected tagged path key.');
        $expected = [
            'source/tagged/two/a' => 'destination/tagged/two/a',
            'source/tagged/two/b' => 'destination/tagged/two/b',
        ];
        $this->assertEquals($expected, $toPublish, 'Service provider does not return expected set of published tagged paths.');
    }

    public function testMultipleTaggedAssetsAreMergedCorrectly()
    {
        $toPublish = ServiceProvider::pathsToPublish(null, 'some_tag');
        $this->assertArrayHasKey('source/tagged/two/a', $toPublish, 'Service provider does not return expected tagged path key.');
        $this->assertArrayHasKey('source/tagged/two/b', $toPublish, 'Service provider does not return expected tagged path key.');
        $this->assertArrayHasKey('source/tagged/one', $toPublish, 'Service provider does not return expected tagged path key.');
        $this->assertArrayNotHasKey('source/unmarked/two/c', $toPublish, 'Service provider does return unexpected tagged path key.');
        $expected = [
            'source/tagged/one' => 'destination/tagged/one',
            'source/tagged/two/a' => 'destination/tagged/two/a',
            'source/tagged/two/b' => 'destination/tagged/two/b',
        ];
        $this->assertEquals($expected, $toPublish, 'Service provider does not return expected set of published tagged paths.');
    }

    public function testPublishesMigrations()
    {
        $serviceProvider = new ServiceProviderForTestingOne($this->app);

        (fn () => $this->publishesMigrations(['source/tagged/four' => 'destination/tagged/four'], 'tag_four'))
            ->call($serviceProvider);

        $this->assertContains('source/tagged/four', ServiceProvider::publishableMigrationPaths());
    }

    public function testAllPathsAreReturnedWhenNoFilterIsSpecified()
    {
        $allPaths = ServiceProvider::pathsToPublish();

        // Should contain paths from both providers
        $this->assertArrayHasKey('source/unmarked/one', $allPaths);
        $this->assertArrayHasKey('source/tagged/one', $allPaths);
        $this->assertArrayHasKey('source/unmarked/two/a', $allPaths);
        $this->assertArrayHasKey('source/tagged/two/a', $allPaths);

        // Should have all 11 paths from both providers
        $this->assertCount(11, $allPaths);
    }

    public function testEmptyArrayIsReturnedWhenProviderNotFound()
    {
        $paths = ServiceProvider::pathsToPublish('NonExistent\Provider');

        $this->assertIsArray($paths);
        $this->assertEmpty($paths);
    }

    public function testEmptyArrayIsReturnedWhenGroupNotFound()
    {
        $paths = ServiceProvider::pathsToPublish(null, 'nonexistent_group');

        $this->assertIsArray($paths);
        $this->assertEmpty($paths);
    }

    public function testMergeConfigFromWithFlatConfig()
    {
        $config = new ConfigRepository();
        $this->app->shouldReceive('make')->with('config')->andReturn($config);

        $provider = new ServiceProviderForTestingFlat($this->app);
        $provider->register();

        $this->assertSame('array', $config->get('flat.default'));
        $this->assertSame('package-prefix', $config->get('flat.prefix'));
    }

    public function testMergeConfigFromAppOverridesPackageDefaults()
    {
        $config = new ConfigRepository([
            'flat' => ['default' => 'redis'],
        ]);
        $this->app->shouldReceive('make')->with('config')->andReturn($config);

        $provider = new ServiceProviderForTestingFlat($this->app);
        $provider->register();

        $this->assertSame('redis', $config->get('flat.default'));
        $this->assertSame('package-prefix', $config->get('flat.prefix'));
    }

    public function testMergeConfigFromWithoutMergeableOptionsReplacesNestedArrays()
    {
        $config = new ConfigRepository([
            'flat_stores' => [
                'default' => 'redis',
                'stores' => [
                    'redis' => ['driver' => 'redis', 'connection' => 'cache'],
                    's3' => ['driver' => 's3', 'bucket' => 'my-bucket'],
                ],
            ],
        ]);
        $this->app->shouldReceive('make')->with('config')->andReturn($config);

        $provider = new ServiceProviderForTestingFlatWithStores($this->app);
        $provider->register();

        // App's stores should fully replace package's stores (no mergeableOptions)
        $stores = $config->get('flat_stores.stores');
        $this->assertArrayHasKey('redis', $stores);
        $this->assertArrayHasKey('s3', $stores);
        $this->assertArrayNotHasKey('array', $stores);
        $this->assertArrayNotHasKey('file', $stores);

        // App's redis config fully replaced package's
        $this->assertSame('cache', $stores['redis']['connection']);
        $this->assertArrayNotHasKey('lock_connection', $stores['redis']);
    }

    public function testMergeConfigFromWithMergeableOptionsCombinesNestedArrays()
    {
        $config = new ConfigRepository([
            'mergeable_stores' => [
                'default' => 'redis',
                'stores' => [
                    'redis' => ['driver' => 'redis', 'connection' => 'cache'],
                    's3' => ['driver' => 's3', 'bucket' => 'my-bucket'],
                ],
            ],
        ]);
        $this->app->shouldReceive('make')->with('config')->andReturn($config);

        $provider = new ServiceProviderForTestingMergeableStores($this->app);
        $provider->register();

        $stores = $config->get('mergeable_stores.stores');

        // App's stores are combined with package's stores
        $this->assertArrayHasKey('array', $stores);
        $this->assertArrayHasKey('file', $stores);
        $this->assertArrayHasKey('redis', $stores);
        $this->assertArrayHasKey('s3', $stores);

        // App's redis fully replaces package's redis (no deep merge into individual entries)
        $this->assertSame('cache', $stores['redis']['connection']);
        $this->assertArrayNotHasKey('lock_connection', $stores['redis']);

        // Package defaults preserved for untouched stores
        $this->assertSame('array', $stores['array']['driver']);
        $this->assertSame('file', $stores['file']['driver']);

        // Top-level app override still wins
        $this->assertSame('redis', $config->get('mergeable_stores.default'));

        // Top-level package default preserved when app doesn't override
        $this->assertSame('package-prefix', $config->get('mergeable_stores.prefix'));
    }

    public function testMergeConfigFromWithMergeableOptionsWhenAppHasNoStores()
    {
        $config = new ConfigRepository([
            'mergeable_stores' => [
                'default' => 'redis',
            ],
        ]);
        $this->app->shouldReceive('make')->with('config')->andReturn($config);

        $provider = new ServiceProviderForTestingMergeableStores($this->app);
        $provider->register();

        // All package stores should be present since app didn't define any
        $stores = $config->get('mergeable_stores.stores');
        $this->assertArrayHasKey('array', $stores);
        $this->assertArrayHasKey('file', $stores);
        $this->assertArrayHasKey('redis', $stores);
        $this->assertCount(3, $stores);
    }

    public function testMergeConfigFromWithMergeableOptionsWhenNoExistingConfig()
    {
        $config = new ConfigRepository();
        $this->app->shouldReceive('make')->with('config')->andReturn($config);

        $provider = new ServiceProviderForTestingMergeableStores($this->app);
        $provider->register();

        // All package defaults should be present
        $this->assertSame('array', $config->get('mergeable_stores.default'));
        $this->assertSame('package-prefix', $config->get('mergeable_stores.prefix'));
        $stores = $config->get('mergeable_stores.stores');
        $this->assertArrayHasKey('array', $stores);
        $this->assertArrayHasKey('file', $stores);
        $this->assertArrayHasKey('redis', $stores);
    }

    public function testMergeableOptionsDefaultsToEmptyArray()
    {
        $provider = new ServiceProviderForTestingFlat($this->app);

        $result = (fn () => $this->mergeableOptions('anything'))->call($provider);

        $this->assertSame([], $result);
    }
}

class ServiceProviderForTestingOne extends ServiceProvider
{
    public function register(): void
    {
    }

    public function boot()
    {
        $this->publishes(['source/unmarked/one' => 'destination/unmarked/one']);
        $this->publishes(['source/tagged/one' => 'destination/tagged/one'], 'some_tag');
        $this->publishes(['source/tagged/multiple' => 'destination/tagged/multiple'], ['tag_one', 'tag_two']);

        $this->publishesMigrations(['source/unmarked/two' => 'destination/unmarked/two']);
        $this->publishesMigrations(['source/tagged/three' => 'destination/tagged/three'], 'tag_three');
        $this->publishesMigrations(['source/tagged/multiple_two' => 'destination/tagged/multiple_two'], ['tag_four', 'tag_five']);
    }
}

class ServiceProviderForTestingTwo extends ServiceProvider
{
    public function register(): void
    {
    }

    public function boot()
    {
        $this->publishes(['source/unmarked/two/a' => 'destination/unmarked/two/a']);
        $this->publishes(['source/unmarked/two/b' => 'destination/unmarked/two/b']);
        $this->publishes(['source/unmarked/two/c' => 'destination/tagged/two/a']);
        $this->publishes(['source/tagged/two/a' => 'destination/tagged/two/a'], 'some_tag');
        $this->publishes(['source/tagged/two/b' => 'destination/tagged/two/b'], 'some_tag');
    }
}

class ServiceProviderForTestingFlat extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/Fixtures/config/package_flat.php', 'flat');
    }
}

class ServiceProviderForTestingFlatWithStores extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/Fixtures/config/package_with_stores.php', 'flat_stores');
    }
}

class ServiceProviderForTestingMergeableStores extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/Fixtures/config/package_with_stores.php', 'mergeable_stores');
    }

    protected function mergeableOptions(string $name): array
    {
        return match ($name) {
            'mergeable_stores' => ['stores'],
            default => [],
        };
    }
}
