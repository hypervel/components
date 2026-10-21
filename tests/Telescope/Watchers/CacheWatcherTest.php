<?php

declare(strict_types=1);

namespace Hypervel\Tests\Telescope\Watchers;

use Hypervel\Contracts\Cache\Factory;
use Hypervel\Contracts\Cache\Repository;
use Hypervel\Telescope\EntryType;
use Hypervel\Telescope\Telescope;
use Hypervel\Telescope\Watchers\CacheWatcher;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Tests\Telescope\FeatureTestCase;

#[WithConfig('telescope.watchers', [
    CacheWatcher::class => [
        'enabled' => true,
        'hidden' => [
            'my-hidden-value-key',
        ],
        'ignore' => [
            'laravel:pulse:*',
            'ignored-key',
        ],
    ],
])]
class CacheWatcherTest extends FeatureTestCase
{
    public function testCacheWatcherRegistersMissedEntries(): void
    {
        $this->app->make(Repository::class)->get('empty-key');

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame(EntryType::CACHE, $entry->type);
        $this->assertSame('missed', $entry->content['type']);
        $this->assertSame('empty-key', $entry->content['key']);
    }

    public function testCacheWatcherRegistersStoreEntries(): void
    {
        $this->app->make(Repository::class)->put('my-key', 'laravel', 1);

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame(EntryType::CACHE, $entry->type);
        $this->assertSame('set', $entry->content['type']);
        $this->assertSame('my-key', $entry->content['key']);
        $this->assertSame('laravel', $entry->content['value']);
    }

    public function testCacheWatcherRegistersHitEntries(): void
    {
        $repository = $this->app->make(Repository::class);

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

    public function testCacheWatcherRegistersForgetEntries(): void
    {
        $repository = $this->app->make(Repository::class);

        Telescope::withoutRecording(function () use ($repository) {
            $repository->put('outdated', 'value', 1);
        });

        $repository->forget('outdated');

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame(EntryType::CACHE, $entry->type);
        $this->assertSame('forget', $entry->content['type']);
        $this->assertSame('outdated', $entry->content['key']);
    }

    public function testCacheWatcherHidesHiddenValuesWhenSet(): void
    {
        $this->app->make(Repository::class)->put('my-hidden-value-key', 'laravel', 1);

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame(EntryType::CACHE, $entry->type);
        $this->assertSame('set', $entry->content['type']);
        $this->assertSame('my-hidden-value-key', $entry->content['key']);
        $this->assertSame('********', $entry->content['value']);
    }

    public function testCacheWatcherHidesHiddenValuesWhenRetrieved(): void
    {
        $repository = $this->app->make(Repository::class);

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

    public function testCacheWatcherSkipsRecordingIgnoredCacheKeys(): void
    {
        $this->app->make(Repository::class)->put('ignored-key', 'laravel');
        $this->app->make(Repository::class)->put('laravel:pulse:restart', 'laravel');
        $this->app->make(Repository::class)->put('my-key', 'laravel');

        $count = $this->loadTelescopeEntries()->count();
        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame(1, $count);

        $this->assertSame(EntryType::CACHE, $entry->type);
        $this->assertSame('set', $entry->content['type']);
        $this->assertSame('my-key', $entry->content['key']);
        $this->assertSame('laravel', $entry->content['value']);
    }

    #[WithConfig('cache.stores.failover', ['driver' => 'failover', 'stores' => ['array']])]
    public function testCacheWatcherRecordsFailoverStoreOperationsOnce(): void
    {
        // The failover repository leaves its events to the store it uses.
        $this->app->make(Factory::class)->store('failover')->get('failover-key');

        $entries = $this->loadTelescopeEntries();

        $this->assertCount(1, $entries);
        $this->assertSame('missed', $entries->first()->content['type']);
        $this->assertSame('failover-key', $entries->first()->content['key']);
    }

    #[WithConfig('cache.stores.quiet', ['driver' => 'array', 'events' => false])]
    public function testCacheWatcherLeavesStoresWithoutEventsUnrecorded(): void
    {
        $this->app->make(Factory::class)->store('quiet')->put('quiet-key', 'laravel', 1);

        $this->assertCount(0, $this->loadTelescopeEntries());
    }
}
