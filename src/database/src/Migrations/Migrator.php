<?php

declare(strict_types=1);

namespace Hypervel\Database\Migrations;

use Closure;
use Hypervel\Console\OutputStyle;
use Hypervel\Console\View\Components\BulletList;
use Hypervel\Console\View\Components\Info;
use Hypervel\Console\View\Components\Task;
use Hypervel\Console\View\Components\TwoColumnDetail;
use Hypervel\Container\Container;
use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Database\Events\MigrationEvent as MigrationEventContract;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Database\Connection;
use Hypervel\Database\ConnectionResolver;
use Hypervel\Database\ConnectionResolverInterface as Resolver;
use Hypervel\Database\Events\MigrationEnded;
use Hypervel\Database\Events\MigrationsEnded;
use Hypervel\Database\Events\MigrationSkipped;
use Hypervel\Database\Events\MigrationsStarted;
use Hypervel\Database\Events\MigrationStarted;
use Hypervel\Database\Events\NoPendingMigrations;
use Hypervel\Database\Schema\Grammars\Grammar as SchemaGrammar;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Support\Arr;
use Hypervel\Support\Collection;
use Hypervel\Support\Str;
use ReflectionClass;

class Migrator
{
    /**
     * The custom connection resolver callback.
     */
    protected static ?Closure $connectionResolverCallback = null;

    /**
     * The name of the default connection.
     */
    protected ?string $connection = null;

    /**
     * The paths to all of the migration files.
     *
     * @var string[]
     */
    protected array $paths = [];

    /**
     * The paths that have already been required.
     *
     * @var array<string, null|\Hypervel\Database\Migrations\Migration>
     */
    protected static array $requiredPathCache = [];

    /**
     * The output interface implementation.
     */
    protected ?OutputStyle $output = null;

    /**
     * The pending migrations to skip.
     *
     * @var list<string>
     */
    protected static array $withoutMigrations = [];

    /**
     * Create a new migrator instance.
     */
    public function __construct(
        protected MigrationRepositoryInterface $repository,
        protected Resolver $resolver,
        protected Filesystem $files,
    ) {
    }

    /**
     * Run the pending migrations at a given path.
     *
     * @param string|string[] $paths
     * @param array<string, mixed> $options
     * @return string[]
     */
    public function run(array|string $paths = [], array $options = []): array
    {
        // Once we grab all of the migration files for the path, we will compare them
        // against the migrations that have already been run for this package then
        // run each of the outstanding migrations against a database connection.
        $files = $this->getMigrationFiles($paths);

        $this->requireFiles($migrations = $this->pendingMigrations(
            $files,
            $this->repository->getRan()
        ));

        // Once we have all these migrations that are outstanding we are ready to run
        // we will go ahead and run them "up". This will execute each migration as
        // an operation against a database. Then we'll return this list of them.
        $this->runPending($migrations, $options);

        return $migrations;
    }

    /**
     * Get the migration files that have not yet run.
     *
     * @param string[] $files
     * @param string[] $ran
     * @return string[]
     */
    protected function pendingMigrations(array $files, array $ran): array
    {
        $migrationsToSkip = $this->migrationsToSkip();

        return (new Collection($files))
            ->reject(
                fn ($file) => in_array($migrationName = $this->getMigrationName($file), $ran)
                || in_array($migrationName, $migrationsToSkip)
            )
            ->values()
            ->all();
    }

    /**
     * Get list of pending migrations to skip.
     *
     * @return list<string>
     */
    protected function migrationsToSkip(): array
    {
        return (new Collection(self::$withoutMigrations))
            ->map($this->getMigrationName(...))
            ->all();
    }

    /**
     * Run an array of migrations.
     *
     * @param string[] $migrations
     * @param array<string, mixed> $options
     */
    public function runPending(array $migrations, array $options = []): void
    {
        // First we will just make sure that there are any migrations to run. If there
        // aren't, we will just make a note of it to the developer so they're aware
        // that all of the migrations have been run against this database system.
        if (count($migrations) === 0) {
            $this->fireMigrationEvent(new NoPendingMigrations('up'));

            $this->write(Info::class, 'Nothing to migrate');

            return;
        }

        // Next, we will get the next batch number for the migrations so we can insert
        // correct batch number in the database migrations repository when we store
        // each migration's execution. We will also extract a few of the options.
        $batch = $this->repository->getNextBatchNumber();

        $pretend = $options['pretend'] ?? false;

        $step = $options['step'] ?? false;

        $this->fireMigrationEvent(new MigrationsStarted('up', $options));

        $this->write(Info::class, 'Running migrations.');

        // Once we have the array of migrations, we will spin through them and run the
        // migrations "up" so the changes are made to the databases. We'll then log
        // that the migration was run so we don't repeat it next time we execute.
        foreach ($migrations as $file) {
            $this->runUp($file, $batch, $pretend);

            if ($step) {
                ++$batch;
            }
        }

        $this->fireMigrationEvent(new MigrationsEnded('up', $options));

        $this->output?->writeln('');
    }

    /**
     * Run "up" a migration instance.
     */
    protected function runUp(string $file, int $batch, bool $pretend): void
    {
        // First we will resolve a "real" instance of the migration class from this
        // migration file name. Once we have the instances we can run the actual
        // command such as "up" or "down", or we can just simulate the action.
        $migration = $this->resolvePath($file);

        $name = $this->getMigrationName($file);

        if ($pretend) {
            $this->pretendToRun($migration, 'up');

            return;
        }

        $shouldRunMigration = $migration instanceof Migration
            ? $migration->shouldRun()
            : true;

        if (! $shouldRunMigration) {
            $this->fireMigrationEvent(new MigrationSkipped($name));

            $this->write(Task::class, $name, fn () => MigrationResult::Skipped->value);
        } else {
            $this->write(Task::class, $name, fn () => $this->runMigration($migration, 'up'));

            // Once we have run a migrations class, we will log that it was run in this
            // repository so that we don't try to run it next time we do a migration
            // in the application. A migration repository keeps the migrate order.
            $this->repository->log($name, $batch);
        }
    }

    /**
     * Rollback the last migration operation.
     *
     * @param string|string[] $paths
     * @param array<string, mixed> $options
     * @return string[]
     */
    public function rollback(array|string $paths = [], array $options = []): array
    {
        // We want to pull in the last batch of migrations that ran on the previous
        // migration operation. We'll then reverse those migrations and run each
        // of them "down" to reverse the last migration "operation" which ran.
        $migrations = $this->getMigrationsForRollback($options);

        if (count($migrations) === 0) {
            $this->fireMigrationEvent(new NoPendingMigrations('down'));

            $this->write(Info::class, 'Nothing to rollback.');

            return [];
        }

        return tap($this->rollbackMigrations($migrations, $paths, $options), function () {
            $this->output?->writeln('');
        });
    }

    /**
     * Get the migrations for a rollback operation.
     *
     * @param array<string, mixed> $options
     */
    protected function getMigrationsForRollback(array $options): array
    {
        if (($steps = $options['step'] ?? 0) > 0) {
            return $this->repository->getMigrations($steps);
        }

        if (($batch = $options['batch'] ?? 0) > 0) {
            return $this->repository->getMigrationsByBatch($batch);
        }

        return $this->repository->getLast();
    }

    /**
     * Rollback the given migrations.
     *
     * @param string|string[] $paths
     * @param array<string, mixed> $options
     * @return string[]
     */
    protected function rollbackMigrations(array $migrations, array|string $paths, array $options): array
    {
        $rolledBack = [];

        $this->requireFiles($files = $this->getMigrationFiles($paths));

        $this->fireMigrationEvent(new MigrationsStarted('down', $options));

        $this->write(Info::class, 'Rolling back migrations.');

        // Next we will run through all of the migrations and call the "down" method
        // which will reverse each migration in order. This getLast method on the
        // repository already returns these migration's names in reverse order.
        foreach ($migrations as $migration) {
            $migration = (object) $migration;

            if (! $file = Arr::get($files, $migration->migration)) {
                $this->write(TwoColumnDetail::class, $migration->migration, '<fg=yellow;options=bold>Migration not found</>');

                continue;
            }

            $rolledBack[] = $file;

            $this->runDown(
                $file,
                $migration,
                $options['pretend'] ?? false
            );
        }

        $this->fireMigrationEvent(new MigrationsEnded('down', $options));

        return $rolledBack;
    }

    /**
     * Rolls all of the currently applied migrations back.
     *
     * @param string|string[] $paths
     */
    public function reset(array|string $paths = [], bool $pretend = false): array
    {
        // Next, we will reverse the migration list so we can run them back in the
        // correct order for resetting this database. This will allow us to get
        // the database back into its "empty" state ready for the migrations.
        $migrations = array_reverse($this->repository->getRan());

        if (count($migrations) === 0) {
            $this->write(Info::class, 'Nothing to rollback.');

            return [];
        }

        return tap($this->resetMigrations($migrations, Arr::wrap($paths), $pretend), function () {
            $this->output?->writeln('');
        });
    }

    /**
     * Reset the given migrations.
     *
     * @param string[] $migrations
     * @param string[] $paths
     */
    protected function resetMigrations(array $migrations, array $paths, bool $pretend = false): array
    {
        // Since the getRan method that retrieves the migration name just gives us the
        // migration name, we will format the names into objects with the name as a
        // property on the objects so that we can pass it to the rollback method.
        $migrations = (new Collection($migrations))->map(fn ($m) => (object) ['migration' => $m])->all();

        return $this->rollbackMigrations(
            $migrations,
            $paths,
            compact('pretend')
        );
    }

    /**
     * Run "down" a migration instance.
     */
    protected function runDown(string $file, object $migration, bool $pretend): void
    {
        // First we will get the file name of the migration so we can resolve out an
        // instance of the migration. Once we get an instance we can either run a
        // pretend execution of the migration or we can run the real migration.
        $instance = $this->resolvePath($file);

        $name = $this->getMigrationName($file);

        if ($pretend) {
            $this->pretendToRun($instance, 'down');

            return;
        }

        $this->write(Task::class, $name, fn () => $this->runMigration($instance, 'down'));

        // Once we have successfully run the migration "down" we will remove it from
        // the migration repository so it will be considered to have not been run
        // by the application then will be able to fire by any later operation.
        $this->repository->delete($migration);
    }

    /**
     * Run a migration inside a transaction if the database supports it.
     */
    protected function runMigration(object $migration, string $method): void
    {
        $connection = $this->resolveConnection(
            $migration->getConnection()
        );

        $callback = function () use ($connection, $migration, $method) {
            if (method_exists($migration, $method)) {
                $this->fireMigrationEvent(new MigrationStarted($migration, $method));

                $this->runMethod($connection, $migration, $method);

                $this->fireMigrationEvent(new MigrationEnded($migration, $method));
            }
        };

        $this->getSchemaGrammar($connection)->supportsSchemaTransactions()
            && $migration->withinTransaction
                ? $connection->transaction($callback)
                : $callback();
    }

    /**
     * Pretend to run the migrations.
     */
    protected function pretendToRun(object $migration, string $method): void
    {
        $name = get_class($migration);

        $reflectionClass = new ReflectionClass($migration);

        if ($reflectionClass->isAnonymous()) {
            $name = $this->getMigrationName($reflectionClass->getFileName());
        }

        $this->write(TwoColumnDetail::class, $name);

        $this->write(
            BulletList::class,
            (new Collection($this->getQueries($migration, $method)))->map(fn ($query) => $query['query'])->all()
        );
    }

    /**
     * Get all of the queries that would be run for a migration.
     */
    protected function getQueries(object $migration, string $method): array
    {
        // Now that we have the connections we can resolve it and pretend to run the
        // queries against the database returning the array of raw SQL statements
        // that would get fired against the database system for this migration.
        $db = $this->resolveConnection(
            $migration->getConnection()
        );

        return $db->pretend(function () use ($db, $migration, $method) {
            if (method_exists($migration, $method)) {
                $this->runMethod($db, $migration, $method);
            }
        });
    }

    /**
     * Run a migration method on the given connection.
     *
     * Sets the coroutine Context key so Schema/DB facade calls inside the
     * migration body resolve to the correct connection. This handles both
     * the migrator's --database override and per-migration $connection
     * properties. Context is the single source of truth for the scoped
     * default — no worker-global state is mutated.
     */
    protected function runMethod(Connection $connection, object $migration, string $method): void
    {
        $previousContext = CoroutineContext::get(ConnectionResolver::DEFAULT_CONNECTION_CONTEXT_KEY);

        try {
            CoroutineContext::set(ConnectionResolver::DEFAULT_CONNECTION_CONTEXT_KEY, $connection->getName());

            $migration->{$method}();
        } finally {
            if ($previousContext === null) {
                CoroutineContext::forget(ConnectionResolver::DEFAULT_CONNECTION_CONTEXT_KEY);
            } else {
                CoroutineContext::set(ConnectionResolver::DEFAULT_CONNECTION_CONTEXT_KEY, $previousContext);
            }
        }
    }

    /**
     * Resolve a migration instance from a file.
     */
    public function resolve(string $file): object
    {
        $class = $this->getMigrationClass($file);

        return new $class;
    }

    /**
     * Resolve a migration instance from a migration path.
     */
    protected function resolvePath(string $path): object
    {
        $class = $this->getMigrationClass($this->getMigrationName($path));

        if (class_exists($class) && realpath($path) == (new ReflectionClass($class))->getFileName()) {
            return new $class;
        }

        $migration = static::$requiredPathCache[$path] ??= $this->files->getRequire($path);

        if (is_object($migration)) {
            return method_exists($migration, '__construct')
                ? $this->files->getRequire($path)
                : clone $migration;
        }

        return new $class;
    }

    /**
     * Generate a migration class name based on the migration file name.
     */
    protected function getMigrationClass(string $migrationName): string
    {
        return Str::studly(implode('_', array_slice(explode('_', $migrationName), 4)));
    }

    /**
     * Get all of the migration files in a given path.
     *
     * @return array<string, string>
     */
    public function getMigrationFiles(array|string $paths): array
    {
        return (new Collection($paths))
            ->flatMap(fn ($path) => str_ends_with($path, '.php') ? [$path] : $this->files->glob($path . '/*_*.php'))
            ->filter()
            ->values()
            ->keyBy(fn ($file) => $this->getMigrationName($file))
            ->sortBy(fn ($file, $key) => $key)
            ->all();
    }

    /**
     * Require in all the migration files in a given path.
     *
     * @param string[] $files
     */
    public function requireFiles(array $files): void
    {
        foreach ($files as $file) {
            $this->files->requireOnce($file);
        }
    }

    /**
     * Get the name of the migration.
     */
    public function getMigrationName(string $path): string
    {
        return str_replace('.php', '', basename($path));
    }

    /**
     * Register a custom migration path.
     */
    public function path(string $path): void
    {
        $this->paths = array_unique(array_merge($this->paths, [$path]));
    }

    /**
     * Get all of the custom migration paths.
     *
     * @return string[]
     */
    public function paths(): array
    {
        return $this->paths;
    }

    /**
     * Set the pending migrations to skip.
     *
     * @param list<string> $migrations
     */
    public static function withoutMigrations(array $migrations): void
    {
        static::$withoutMigrations = $migrations;
    }

    /**
     * Get the default connection name.
     */
    public function getConnection(): ?string
    {
        return $this->connection;
    }

    /**
     * Resolve a connection name through the "migrations_connection" config key.
     *
     * Allows pooled connections (PgBouncer, pgdog, Neon, Supabase, etc.) to declare
     * an unpooled sibling that migration operations should route through. Session
     * state required by migrations — advisory locks, LOCK TABLE, temp tables — is
     * incompatible with transaction-pooling mode.
     *
     * When $name is null, falls back to the "effective default connection" —
     * the current coroutine's Context override first, then the configured
     * default (database.default). This mirrors DatabaseManager::getDefaultConnection()
     * so programmatic flows that wrap migrations in DB::usingConnection() or
     * DatabaseManager::setDefaultConnection() route through the scoped default.
     *
     * Defensively passes the name through when the container has no "config"
     * binding so unit tests that construct Migrator without a booted framework
     * still work.
     */
    public static function resolveMigrationConnectionName(?string $name): ?string
    {
        $container = Container::getInstance();

        if (! $container->bound('config')) {
            return $name;
        }

        $config = $container->make('config');

        if ($name === null) {
            $name = CoroutineContext::get(ConnectionResolver::DEFAULT_CONNECTION_CONTEXT_KEY)
                ?? $config->get('database.default');

            if ($name === null) {
                return null;
            }
        }

        return $config->get(
            "database.connections.{$name}.migrations_connection",
            $name,
        );
    }

    /**
     * Execute the given callback using the given connection as the default connection.
     *
     * Snapshots the prior coroutine Context value and the stored migrator
     * connection on entry, then restores them directly in finally without
     * routing back through setConnection() — otherwise the restoration would
     * apply migrations_connection to the saved alias and leave the wrong
     * default in place.
     */
    public function usingConnection(?string $name, callable $callback): mixed
    {
        $previousStored = $this->connection;
        $previousContext = CoroutineContext::get(ConnectionResolver::DEFAULT_CONNECTION_CONTEXT_KEY);

        $this->setConnection($name);

        try {
            return $callback();
        } finally {
            $this->connection = $previousStored;
            $this->repository->setSource($previousStored);

            if ($previousContext === null) {
                CoroutineContext::forget(ConnectionResolver::DEFAULT_CONNECTION_CONTEXT_KEY);
            } else {
                CoroutineContext::set(ConnectionResolver::DEFAULT_CONNECTION_CONTEXT_KEY, $previousContext);
            }
        }
    }

    /**
     * Set the default connection name.
     *
     * Honors the target connection's "migrations_connection" config key. The
     * swapped name is propagated via coroutine Context (so Schema/DB facade
     * calls during migration resolve correctly), via the repository source
     * (so the migrations table lands on the same target), and via the stored
     * connection (so subsequent resolveConnection() calls use it). Uses
     * CoroutineContext rather than $this->resolver->setDefaultConnection() so
     * no worker-global state is mutated — concurrent coroutines are unaffected.
     */
    public function setConnection(?string $name): void
    {
        $name = static::resolveMigrationConnectionName($name);

        if ($name === null) {
            CoroutineContext::forget(ConnectionResolver::DEFAULT_CONNECTION_CONTEXT_KEY);
        } else {
            CoroutineContext::set(ConnectionResolver::DEFAULT_CONNECTION_CONTEXT_KEY, $name);
        }

        $this->repository->setSource($name);

        $this->connection = $name;
    }

    /**
     * Resolve the database connection instance.
     *
     * Applies the "migrations_connection" swap so per-migration connection
     * overrides (via Migration::getConnection()) are also routed to the
     * unpooled sibling when the named connection opts in.
     */
    public function resolveConnection(?string $connection): Connection
    {
        $connection = static::resolveMigrationConnectionName(
            $connection ?: $this->connection
        );

        if (static::$connectionResolverCallback) {
            return call_user_func(
                static::$connectionResolverCallback,
                $this->resolver,
                $connection
            );
        }
        // @phpstan-ignore return.type (resolver returns ConnectionInterface but concrete Connection in practice)
        return $this->resolver->connection($connection);
    }

    /**
     * Set a connection resolver callback.
     */
    public static function resolveConnectionsUsing(Closure $callback): void
    {
        static::$connectionResolverCallback = $callback;
    }

    /**
     * Get the schema grammar out of a migration connection.
     */
    protected function getSchemaGrammar(Connection $connection): SchemaGrammar
    {
        if (is_null($grammar = $connection->getSchemaGrammar())) {
            $connection->useDefaultSchemaGrammar();

            $grammar = $connection->getSchemaGrammar();
        }

        return $grammar;
    }

    /**
     * Get the migration repository instance.
     */
    public function getRepository(): MigrationRepositoryInterface
    {
        return $this->repository;
    }

    /**
     * Determine if the migration repository exists.
     */
    public function repositoryExists(): bool
    {
        return $this->repository->repositoryExists();
    }

    /**
     * Determine if any migrations have been run.
     */
    public function hasRunAnyMigrations(): bool
    {
        return $this->repositoryExists() && count($this->repository->getRan()) > 0;
    }

    /**
     * Delete the migration repository data store.
     */
    public function deleteRepository(): void
    {
        $this->repository->deleteRepository();
    }

    /**
     * Get the file system instance.
     */
    public function getFilesystem(): Filesystem
    {
        return $this->files;
    }

    /**
     * Set the output implementation that should be used by the console.
     */
    public function setOutput(OutputStyle $output): static
    {
        $this->output = $output;

        return $this;
    }

    /**
     * Write to the console's output.
     *
     * @param class-string $component
     */
    protected function write(string $component, mixed ...$arguments): void
    {
        if ($this->output) {
            (new $component($this->output))->render(...$arguments);
        } else {
            // Still execute callbacks when there's no output (e.g., running programmatically)
            foreach ($arguments as $argument) {
                if (is_callable($argument)) {
                    $argument();
                }
            }
        }
    }

    /**
     * Fire the given event for the migration.
     *
     * Fetches the dispatcher from the container each time to ensure Event::fake()
     * and other runtime swaps are respected (the Migrator may be constructed
     * before fakes are set up).
     */
    public function fireMigrationEvent(MigrationEventContract $event): void
    {
        $container = Container::getInstance();

        if ($container->bound(Dispatcher::class)) {
            $container[Dispatcher::class]->dispatch($event);
        }
    }
}
