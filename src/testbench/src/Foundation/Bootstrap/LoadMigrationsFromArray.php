<?php

declare(strict_types=1);

namespace Hypervel\Testbench\Foundation\Bootstrap;

use Hypervel\Console\Command;
use Hypervel\Contracts\Console\Kernel as ConsoleKernel;
use Hypervel\Contracts\Events\Dispatcher as EventDispatcher;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Database\Events\DatabaseRefreshed;
use Hypervel\Support\Collection;
use Hypervel\Support\Env;
use InvalidArgumentException;
use RuntimeException;

use function Hypervel\Testbench\default_migration_path;
use function Hypervel\Testbench\load_migration_paths;
use function Hypervel\Testbench\transform_relative_path;
use function Hypervel\Testbench\workbench;

/**
 * @internal
 */
final class LoadMigrationsFromArray
{
    /**
     * @param array<int, string>|bool|string $migrations
     * @param array<int, mixed>|bool|string $seeders
     */
    public function __construct(
        public readonly array|bool|string $migrations = [],
        public readonly array|bool|string $seeders = false,
    ) {
    }

    /**
     * Bootstrap the given application.
     */
    public function bootstrap(Application $app): void
    {
        if ($this->seeders !== false) {
            $this->bootstrapSeeders($app);
        }

        if ($this->migrations !== false) {
            $this->bootstrapMigrations($app);
        }
    }

    /**
     * Bootstrap seeders.
     */
    private function bootstrapSeeders(Application $app): void
    {
        $app->make(EventDispatcher::class)
            ->listen(DatabaseRefreshed::class, function (DatabaseRefreshed $event) use ($app): void {
                if ($this->seeders === true) {
                    if ($app->make(ConsoleKernel::class)->call('db:seed') !== Command::SUCCESS) {
                        throw new RuntimeException('Default seeder failed.');
                    }

                    return;
                }

                foreach (Collection::wrap($this->seeders)->flatten() as $seederClass) {
                    if (! is_string($seederClass)) {
                        throw new InvalidArgumentException('Testbench seeders must be existing class strings.');
                    }

                    if (! class_exists($seederClass)) {
                        throw new InvalidArgumentException("Seeder class [{$seederClass}] does not exist.");
                    }

                    if ($app->make(ConsoleKernel::class)->call('db:seed', ['--class' => $seederClass]) !== Command::SUCCESS) {
                        throw new RuntimeException("Seeder [{$seederClass}] failed.");
                    }
                }
            });
    }

    /**
     * Bootstrap migrations.
     */
    private function bootstrapMigrations(Application $app): void
    {
        $paths = Collection::wrap(
            ! is_bool($this->migrations) ? $this->migrations : []
        )->when(
            $this->includesDefaultMigrations($app),
            static fn (Collection $migrations): Collection => $migrations->push(default_migration_path()),
        )->filter(static fn (mixed $migration): bool => is_string($migration))
            ->transform(static fn (string $migration): ?string => transform_relative_path($migration, $app->basePath()))
            ->filter(static fn (?string $migration): bool => $migration !== null)
            ->values()
            ->all();

        load_migration_paths($app, $paths);
    }

    /**
     * Determine whether default migrations should be included.
     */
    private function includesDefaultMigrations(Application $app): bool
    {
        return workbench()['install'] === true
            && Env::get('TESTBENCH_WITHOUT_DEFAULT_MIGRATIONS') !== true
            && rescue(static fn (): bool => is_dir(default_migration_path()), false, false);
    }
}
