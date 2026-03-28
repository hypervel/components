<?php

declare(strict_types=1);

namespace Hypervel\Database;

use Faker\Factory as FakerFactory;
use Faker\Generator as FakerGenerator;
use Hypervel\Database\Connectors\ConnectionFactory;
use Hypervel\Database\Console\DbCommand;
use Hypervel\Database\Console\DumpCommand;
use Hypervel\Database\Console\Factories\FactoryMakeCommand;
use Hypervel\Database\Console\Migrations\FreshCommand;
use Hypervel\Database\Console\Migrations\InstallCommand;
use Hypervel\Database\Console\Migrations\MigrateCommand;
use Hypervel\Database\Console\Migrations\MigrateMakeCommand;
use Hypervel\Database\Console\Migrations\RefreshCommand;
use Hypervel\Database\Console\Migrations\ResetCommand;
use Hypervel\Database\Console\Migrations\RollbackCommand;
use Hypervel\Database\Console\Migrations\StatusCommand;
use Hypervel\Database\Console\MonitorCommand;
use Hypervel\Database\Console\PruneCommand;
use Hypervel\Database\Console\Seeds\SeedCommand;
use Hypervel\Database\Console\Seeds\SeederMakeCommand;
use Hypervel\Database\Console\ShowCommand;
use Hypervel\Database\Console\ShowModelCommand;
use Hypervel\Database\Console\TableCommand;
use Hypervel\Database\Console\WipeCommand;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Listeners\UnsetContextInTaskWorkerListener;
use Hypervel\Database\Migrations\DatabaseMigrationRepository;
use Hypervel\Database\Migrations\MigrationCreator;
use Hypervel\Database\Migrations\Migrator;
use Hypervel\Database\Schema\SchemaProxy;
use Hypervel\Framework\Events\BeforeWorkerStart;
use Hypervel\Support\ServiceProvider;

class DatabaseServiceProvider extends ServiceProvider
{
    /**
     * Register the service provider.
     */
    public function register(): void
    {
        $this->registerConnectionServices();
        $this->registerFakerGenerator();

        $this->app->singleton('db.resolver', fn ($app) => $app->make(ConnectionResolver::class));

        $this->app->singleton('migration.repository', function ($app) {
            $migrations = $app->make('config')->get('database.migrations', 'migrations');

            $table = is_array($migrations)
                ? ($migrations['table'] ?? 'migrations')
                : $migrations;

            return new DatabaseMigrationRepository(
                $app->make('db'),
                $table,
            );
        });

        $this->app->singleton('migrator', function ($app) {
            return new Migrator(
                $app->make('migration.repository'),
                $app->make('db'),
                $app->make('files'),
            );
        });

        $this->app->singleton('migration.creator', function ($app) {
            return new MigrationCreator($app['files'], $app->basePath('stubs'));
        });

        $this->commands([
            DbCommand::class,
            DumpCommand::class,
            FactoryMakeCommand::class,
            FreshCommand::class,
            InstallCommand::class,
            MigrateCommand::class,
            MigrateMakeCommand::class,
            MonitorCommand::class,
            PruneCommand::class,
            RefreshCommand::class,
            ResetCommand::class,
            RollbackCommand::class,
            SeedCommand::class,
            SeederMakeCommand::class,
            ShowCommand::class,
            ShowModelCommand::class,
            StatusCommand::class,
            TableCommand::class,
            WipeCommand::class,
        ]);
    }

    /**
     * Register the primary database bindings.
     */
    protected function registerConnectionServices(): void
    {
        $this->app->singleton('db', function ($app) {
            return new DatabaseManager($app, $app->make(ConnectionFactory::class));
        });

        $this->app->singleton('db.schema', function () {
            return new SchemaProxy();
        });

        $this->app->singleton('db.transactions', function () {
            return new DatabaseTransactionsManager();
        });
    }

    /**
     * Register the Faker Generator instance in the container.
     *
     * Scoped (not singleton) because Faker's Generator carries mutable state:
     * the unique() tracker accumulates generated values, and seed() calls
     * mt_srand() which is process-global. A worker-lifetime singleton would
     * bleed this state across concurrent coroutines. Scoping gives each
     * coroutine its own Generator instance — the unique tracker dies with
     * the coroutine, and no cross-request interference occurs.
     *
     * Note: seed() calls mt_srand() which is process-global and cannot be
     * scoped to a coroutine. Avoid calling $faker->seed() in production
     * code running under Swoole — it will affect randomness for all
     * concurrent requests in the worker.
     */
    protected function registerFakerGenerator(): void
    {
        if (! class_exists(FakerGenerator::class)) {
            return;
        }

        $this->app->scoped(FakerGenerator::class, function ($app, $parameters) {
            $locale = $parameters['locale'] ?? $app->make('config')->get('app.faker_locale', 'en_US');

            return FakerFactory::create($locale);
        });
    }

    /**
     * Bootstrap the service provider.
     */
    public function boot(): void
    {
        Model::setConnectionResolver($this->app->make('db'));
        Model::setEventDispatcher($this->app['events']);

        $events = $this->app->make('events');

        $events->listen(BeforeWorkerStart::class, function (BeforeWorkerStart $event) {
            $this->app->make(UnsetContextInTaskWorkerListener::class)->handle($event);
        });
    }
}
