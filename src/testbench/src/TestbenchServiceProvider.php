<?php

declare(strict_types=1);

namespace Hypervel\Testbench;

use Composer\InstalledVersions;
use Hypervel\Foundation\Console\AboutCommand;
use Hypervel\Support\ServiceProvider;
use Hypervel\Testbench\Contracts\Config as ConfigContract;
use Hypervel\Testbench\Workbench\Workbench;

class TestbenchServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->singleton(ConfigContract::class, static fn (): ConfigContract => Workbench::configuration());

        AboutCommand::add('Testbench', fn (): array => array_filter([
            'Skeleton Path' => AboutCommand::format(
                $this->app->basePath(),
                console: fn (string $value): string => transform_realpath_to_relative($value),
            ),
            'Version' => InstalledVersions::isInstalled('hypervel/testbench')
                ? InstalledVersions::getPrettyVersion('hypervel/testbench')
                : null,
        ]));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->commands([
            $this->isCollisionDependenciesInstalled()
                ? Foundation\Console\TestCommand::class
                : Foundation\Console\TestFallbackCommand::class,
            Foundation\Console\CreateSqliteDbCommand::class,
            Foundation\Console\DropSqliteDbCommand::class,
            Foundation\Console\PurgeSkeletonCommand::class,
            Foundation\Console\ServeCommand::class,
            Foundation\Console\SyncSkeletonCommand::class,
            Foundation\Console\VendorPublishCommand::class,
        ]);
    }

    /**
     * Determine whether the Collision test command dependencies are installed.
     */
    protected function isCollisionDependenciesInstalled(): bool
    {
        return InstalledVersions::isInstalled('nunomaduro/collision');
    }
}
