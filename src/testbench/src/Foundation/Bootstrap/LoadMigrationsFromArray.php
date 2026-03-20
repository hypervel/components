<?php

declare(strict_types=1);

namespace Hypervel\Testbench\Foundation\Bootstrap;

use Hypervel\Contracts\Console\Kernel as ConsoleKernel;
use Hypervel\Contracts\Events\Dispatcher as EventDispatcher;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Database\Events\DatabaseRefreshed;
use Hypervel\Support\Collection;
use Hypervel\Support\Env;

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
     * @param array<int, class-string>|bool|class-string $seeders
     */
    public function __construct(
        public readonly array|bool|string $migrations = [],
        public readonly array|bool|string $seeders = false,
    ) {
    }

    public function bootstrap(Application $app): void
    {
        if ($this->seeders !== false) {
            $this->bootstrapSeeders($app);
        }

        if ($this->migrations !== false) {
            $this->bootstrapMigrations($app);
        }
    }

    private function bootstrapSeeders(Application $app): void
    {
        $app->make(EventDispatcher::class)
            ->listen(DatabaseRefreshed::class, function (DatabaseRefreshed $event) use ($app): void {
                if (is_bool($this->seeders)) {
                    if ($this->seeders) {
                        $app->make(ConsoleKernel::class)->call('db:seed');
                    }

                    return;
                }

                Collection::wrap($this->seeders)
                    ->flatten()
                    ->filter(static fn (mixed $seederClass): bool => $seederClass !== null && is_string($seederClass) && class_exists($seederClass))
                    ->each(static function (string $seederClass) use ($app): void {
                        $app->make(ConsoleKernel::class)->call('db:seed', [
                            '--class' => $seederClass,
                        ]);
                    });
            });
    }

    private function bootstrapMigrations(Application $app): void
    {
        $paths = Collection::wrap(
            ! is_bool($this->migrations) ? $this->migrations : []
        )->when(
            $this->includesDefaultMigrations($app),
            static fn (Collection $migrations): Collection => $migrations->push(default_migration_path()),
        )->filter(static fn (mixed $migration): bool => is_string($migration)) /* @phpstan-ignore function.alreadyNarrowedType */
            ->transform(static fn (string $migration): ?string => transform_relative_path($migration, $app->basePath()))
            ->filter(static fn (?string $migration): bool => $migration !== null)
            ->values()
            ->all();

        load_migration_paths($app, $paths);
    }

    private function includesDefaultMigrations(Application $app): bool
    {
        return workbench()['install'] === true
            && Env::get('TESTBENCH_WITHOUT_DEFAULT_MIGRATIONS') !== true
            && rescue(static fn (): bool => is_dir(default_migration_path()), false, false);
    }
}
