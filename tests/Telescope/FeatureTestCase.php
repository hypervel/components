<?php

declare(strict_types=1);

namespace Hypervel\Tests\Telescope;

use Faker\Factory as FakerFactory;
use Faker\Generator;
use Hypervel\Contracts\Cache\Factory as CacheFactoryContract;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Database\Eloquent\Collection;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Support\Facades\Schema;
use Hypervel\Telescope\Contracts\EntriesRepository;
use Hypervel\Telescope\EntryType;
use Hypervel\Telescope\Http\Middleware\Authorize;
use Hypervel\Telescope\Storage\DatabaseEntriesRepository;
use Hypervel\Telescope\Storage\EntryModel;
use Hypervel\Telescope\Telescope;
use Hypervel\Telescope\TelescopeServiceProvider;
use Hypervel\Testbench\TestCase;

/**
 * @internal
 * @coversNothing
 */
class FeatureTestCase extends TestCase
{
    use RefreshDatabase;

    protected bool $migrateRefresh = true;

    protected ?Generator $faker = null;

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
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->singleton(
            EntriesRepository::class,
            fn ($container) => $container->make(DatabaseEntriesRepository::class)
        );
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

        parent::tearDown();
    }

    protected function migrateFreshUsing(): array
    {
        return [
            '--seed' => $this->shouldSeed(),
            '--database' => $this->getRefreshConnection(),
            '--realpath' => true,
            '--path' => dirname(__DIR__, 2) . '/src/telescope/database/migrations',
        ];
    }

    protected function createEntry(array $attributes = []): DummyEntryModel
    {
        return DummyEntryModel::create(array_merge([
            'sequence' => random_int(1, 10000),
            'uuid' => $this->getFaker()->uuid(),
            'batch_id' => $this->getFaker()->uuid(),
            'type' => $this->getFaker()->randomElement([
                EntryType::CACHE,
                EntryType::CLIENT_REQUEST,
                EntryType::COMMAND,
                EntryType::DUMP,
                EntryType::EVENT,
                EntryType::EXCEPTION,
                EntryType::JOB,
                EntryType::LOG,
                EntryType::MAIL,
                EntryType::MODEL,
                EntryType::NOTIFICATION,
                EntryType::QUERY,
                EntryType::REDIS,
                EntryType::REQUEST,
                EntryType::SCHEDULED_TASK,
            ]),
            'content' => [$this->getFaker()->word() => $this->getFaker()->word()],
        ], $attributes));
    }

    protected function getFaker(): Generator
    {
        if ($this->faker) {
            return $this->faker;
        }

        return $this->faker = FakerFactory::create();
    }

    protected function createUsersTable()
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->timestamps();
        });
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

class DummyEntryModel extends EntryModel
{
    protected array $guarded = [];
}
