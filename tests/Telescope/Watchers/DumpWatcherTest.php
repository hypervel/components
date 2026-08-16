<?php

declare(strict_types=1);

namespace Hypervel\Tests\Telescope\Watchers;

use Hypervel\Contracts\Cache\Factory as CacheFactory;
use Hypervel\Contracts\Cache\Repository as CacheRepository;
use Hypervel\Foundation\Providers\FoundationServiceProvider;
use Hypervel\Telescope\EntryType;
use Hypervel\Telescope\Watchers\DumpWatcher;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Tests\Telescope\FeatureTestCase;
use Mockery as m;
use ReflectionProperty;
use RuntimeException;
use Symfony\Component\VarDumper\VarDumper;

#[WithConfig('telescope.watchers', [
    DumpWatcher::class => true,
])]
class DumpWatcherTest extends FeatureTestCase
{
    public function testActiveDumpWatcherRecordsEntryAndLabel(): void
    {
        $delegated = [];
        $this->installWatcher(
            $this->app->make(CacheFactory::class),
            previous: function (mixed $value, ?string $label = null) use (&$delegated): void {
                $delegated[] = [$value, $label];
            },
        );

        VarDumper::dump($value = 'Telescopes are better than binoculars', 'recorded-label');

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame([], $delegated);
        $this->assertSame(EntryType::DUMP, $entry->type);
        $this->assertStringContainsString($value, $entry->content['dump']);
        $this->assertStringContainsString('recorded-label', $entry->content['dump']);
    }

    public function testInactiveDumpWatcherDelegatesWithLabel(): void
    {
        cache()->forget('telescope:dump-watcher');
        $delegated = [];
        $this->installWatcher(
            $this->app->make(CacheFactory::class),
            previous: function (mixed $value, ?string $label = null) use (&$delegated): void {
                $delegated[] = [$value, $label];
            },
        );

        VarDumper::dump('delegated-value', 'delegated-label');

        $this->assertSame([['delegated-value', 'delegated-label']], $delegated);
        $this->assertCount(0, $this->loadTelescopeEntries());
    }

    public function testCacheFailureDelegatesTheDump(): void
    {
        $cache = m::mock(CacheFactory::class);
        $repository = m::mock(CacheRepository::class);
        $cache->shouldReceive('store')
            ->once()
            ->andReturn($repository);
        $repository->shouldReceive('get')
            ->once()
            ->with('telescope:dump-watcher')
            ->andThrow(new RuntimeException('Cache unavailable.'));
        $delegated = [];
        $this->installWatcher(
            $cache,
            previous: function (mixed $value, ?string $label = null) use (&$delegated): void {
                $delegated[] = [$value, $label];
            },
        );

        VarDumper::dump('cache-failure', 'failure-label');

        $this->assertSame([['cache-failure', 'failure-label']], $delegated);
        $this->assertCount(0, $this->loadTelescopeEntries());
    }

    public function testAlwaysOptionRecordsWithoutResolvingCache(): void
    {
        $cache = m::mock(CacheFactory::class);
        $cache->shouldNotReceive('store');
        $delegated = [];
        $this->installWatcher(
            $cache,
            ['always' => true],
            function (mixed $value, ?string $label = null) use (&$delegated): void {
                $delegated[] = [$value, $label];
            },
        );

        VarDumper::dump('always-recorded', 'always-label');

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame([], $delegated);
        $this->assertStringContainsString('always-recorded', $entry->content['dump']);
        $this->assertStringContainsString('always-label', $entry->content['dump']);
    }

    public function testExplicitEnvironmentOwnerIsPreserved(): void
    {
        $delegated = [];
        DumpWatcher::flushState();
        VarDumper::setHandler(function (mixed $value, ?string $label = null) use (&$delegated): void {
            $delegated[] = [$value, $label];
        });
        $_SERVER['VAR_DUMPER_FORMAT'] = 'cli';

        try {
            (new DumpWatcher($this->app->make(CacheFactory::class)))->register($this->app);

            VarDumper::dump('environment-owned', 'environment-label');

            $this->assertSame([['environment-owned', 'environment-label']], $delegated);
        } finally {
            unset($_SERVER['VAR_DUMPER_FORMAT']);
            DumpWatcher::flushState();
        }
    }

    public function testWatcherRefusesToInstallWithoutAnExistingOwner(): void
    {
        DumpWatcher::flushState();

        (new DumpWatcher($this->app->make(CacheFactory::class)))->register($this->app);

        $this->assertNull($this->varDumperHandler());
        $this->assertFalse($this->watcherInstalled());
    }

    public function testWatcherDoesNotStackHandlers(): void
    {
        $watcher = $this->installWatcher(
            $this->app->make(CacheFactory::class),
            previous: static function (): void {
            },
        );
        $handler = $this->varDumperHandler();

        $watcher->register($this->app);

        $this->assertSame($handler, $this->varDumperHandler());

        VarDumper::dump('single-handler');

        $this->assertCount(1, $this->loadTelescopeEntries());
    }

    public function testFoundationConfigurationReloadPreservesInstalledWatcher(): void
    {
        cache()->forever('telescope:dump-watcher', true);
        $handler = $this->varDumperHandler();

        $this->assertTrue($this->watcherInstalled());
        $this->assertNotNull($handler);

        config(['view.compiled' => '/tmp/reloaded-compiled-views']);
        $this->app->getProvider(FoundationServiceProvider::class)->reloadConfiguration();

        $this->assertSame($handler, $this->varDumperHandler());

        VarDumper::dump('recorded-after-reload');

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame(EntryType::DUMP, $entry->type);
        $this->assertStringContainsString('recorded-after-reload', $entry->content['dump']);
    }

    public function testFlushStateDropsThePriorApplicationHandlerAndAllowsReregistration(): void
    {
        $this->installWatcher(
            $this->app->make(CacheFactory::class),
            previous: static function (): void {
            },
        );
        $firstHandler = $this->varDumperHandler();

        DumpWatcher::flushState();

        $this->assertNull($this->varDumperHandler());
        $this->assertFalse($this->watcherInstalled());

        $this->installWatcher(
            $this->app->make(CacheFactory::class),
            previous: static function (): void {
            },
        );

        $this->assertNotSame($firstHandler, $this->varDumperHandler());
        $this->assertTrue($this->watcherInstalled());
    }

    /**
     * Install a fresh dump watcher around the given owner.
     */
    protected function installWatcher(
        CacheFactory $cache,
        array $options = [],
        ?callable $previous = null,
    ): DumpWatcher {
        DumpWatcher::flushState();
        VarDumper::setHandler($previous);

        $watcher = new DumpWatcher($cache, $options);
        $watcher->register($this->app);

        return $watcher;
    }

    /**
     * Get the current Symfony dump handler.
     */
    protected function varDumperHandler(): mixed
    {
        return (new ReflectionProperty(VarDumper::class, 'handler'))->getValue();
    }

    /**
     * Determine if Telescope owns the current dump handler.
     */
    protected function watcherInstalled(): bool
    {
        return (new ReflectionProperty(DumpWatcher::class, 'installed'))->getValue();
    }
}
