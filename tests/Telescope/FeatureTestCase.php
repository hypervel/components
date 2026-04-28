<?php

declare(strict_types=1);

namespace Hypervel\Tests\Telescope;

use Hypervel\Contracts\Cache\Factory as CacheFactoryContract;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Database\Eloquent\Collection;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Queue\Queue;
use Hypervel\Telescope\Contracts\EntriesRepository;
use Hypervel\Telescope\Http\Middleware\Authorize;
use Hypervel\Telescope\Storage\EntryModel;
use Hypervel\Telescope\Telescope;
use Hypervel\Telescope\TelescopeServiceProvider;
use Hypervel\Testbench\Attributes\WithMigration;
use Hypervel\Testbench\TestCase;

use function Hypervel\Testbench\load_migration_paths;

#[WithMigration]
class FeatureTestCase extends TestCase
{
    use RefreshDatabase;

    protected ?ApplicationContract $app = null;

    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [
            TelescopeServiceProvider::class,
        ];
    }

    protected function defineEnvironment(ApplicationContract $app): void
    {
        $app->make('config')->set([
            'database.default' => 'testing',
            'telescope.storage.database.connection' => 'testing',
            'telescope.enabled' => true,
            'telescope.path' => 'telescope',
            'telescope.middleware' => [
                Authorize::class,
            ],
            // Tests read the DB right after store(), so Telescope must write immediately.
            'telescope.defer' => false,
            'cache.default' => 'array',
            'cache.stores.array' => [
                'driver' => 'array',
                'serialize' => false,
                'events' => true,
            ],
        ]);

        // Load Telescope's own migrations alongside the testbench defaults.
        // In Laravel this is done via testbench.yaml; Hypervel registers them explicitly.
        load_migration_paths($app, dirname(__DIR__, 2) . '/src/telescope/database/migrations');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make(CacheFactoryContract::class)
            ->forever('telescope:dump-watcher', true);
        $this->app['env'] = 'production';

        // Clear any entries recorded during bootstrap (e.g. migrations).
        Telescope::flushEntries();
        Telescope::$afterStoringHooks = [];
        EntryModel::truncate();

        // In Swoole, recording state is per-coroutine via CoroutineContext. Unlike
        // Laravel where Telescope::start() begins recording for the process lifetime,
        // Hypervel starts recording per-request via RequestReceived event listeners.
        // Tests don't fire RequestReceived, so we start recording manually.
        Telescope::startRecording();
    }

    protected function tearDown(): void
    {
        Telescope::flushEntries();
        Telescope::$afterStoringHooks = [];

        Queue::createPayloadUsing(null);

        parent::tearDown();
    }

    protected function loadTelescopeEntries(): Collection
    {
        $this->terminateTelescope();

        return EntryModel::all();
    }

    public function terminateTelescope(): void
    {
        Telescope::store(
            $this->app->make(EntriesRepository::class)
        );
    }
}
