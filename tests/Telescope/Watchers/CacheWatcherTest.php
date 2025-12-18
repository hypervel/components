<?php

declare(strict_types=1);

namespace Hypervel\Tests\Telescope\Watchers;

use Hyperf\Contract\ConfigInterface;
use Hypervel\Cache\Contracts\Factory as FactoryContract;
use Hypervel\Telescope\EntryType;
use Hypervel\Telescope\Telescope;
use Hypervel\Telescope\Watchers\CacheWatcher;
use Hypervel\Tests\Telescope\FeatureTestCase;

/**
 * @internal
 * @coversNothing
 */
class CacheWatcherTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->get(ConfigInterface::class)
            ->set('telescope.watchers', [
                CacheWatcher::class => [
                    'enabled' => true,
                    'hidden' => [
                        'my-hidden-value-key',
                    ],
                ],
            ]);

        CacheWatcher::enableCacheEvents($this->app);

        $this->startTelescope();
    }

    public function testCacheWatcherRegistersMissedEntries()
    {
        $this->app->get(FactoryContract::class)->get('empty-key');

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame(EntryType::CACHE, $entry->type);
        $this->assertSame('missed', $entry->content['type']);
        $this->assertSame('empty-key', $entry->content['key']);
    }

    public function testCacheWatcherRegistersStoreEntries()
    {
        $this->app->get(FactoryContract::class)->put('my-key', 'laravel', 1);

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame(EntryType::CACHE, $entry->type);
        $this->assertSame('set', $entry->content['type']);
        $this->assertSame('my-key', $entry->content['key']);
        $this->assertSame('laravel', $entry->content['value']);
    }

    public function testCacheWatcherRegistersHitEntries()
    {
        $repository = $this->app->get(FactoryContract::class);

        Telescope::withoutRecording(function () use ($repository) {
            $repository->put('telescope', 'laravel', 1);
        });

        $repository->get('telescope');

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame(EntryType::CACHE, $entry->type);
        $this->assertSame('hit', $entry->content['type']);
        $this->assertSame('telescope', $entry->content['key']);
        $this->assertSame('laravel', $entry->content['value']);
    }

    public function testCacheWatcherRegistersForgetEntries()
    {
        $repository = $this->app->get(FactoryContract::class);

        Telescope::withoutRecording(function () use ($repository) {
            $repository->put('outdated', 'value', 1);
        });

        $repository->forget('outdated');

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame(EntryType::CACHE, $entry->type);
        $this->assertSame('forget', $entry->content['type']);
        $this->assertSame('outdated', $entry->content['key']);
    }

    public function testCacheWatcherHidesHiddenValuesWhenSet()
    {
        $this->app->get(FactoryContract::class)->put('my-hidden-value-key', 'laravel', 1);

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame(EntryType::CACHE, $entry->type);
        $this->assertSame('set', $entry->content['type']);
        $this->assertSame('my-hidden-value-key', $entry->content['key']);
        $this->assertSame('********', $entry->content['value']);
    }

    public function testCacheWatcherHidesHiddenValuesWhenRetrieved()
    {
        $repository = $this->app->get(FactoryContract::class);

        Telescope::withoutRecording(function () use ($repository) {
            $repository->put('my-hidden-value-key', 'laravel', 1);
        });

        $repository->get('my-hidden-value-key');

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame(EntryType::CACHE, $entry->type);
        $this->assertSame('hit', $entry->content['type']);
        $this->assertSame('my-hidden-value-key', $entry->content['key']);
        $this->assertSame('********', $entry->content['value']);
    }
}
