<?php

declare(strict_types=1);

namespace Hypervel\Doctor;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Doctor\Console\DiagnosticMakeCommand;
use Hypervel\Doctor\Console\DoctorCommand;
use Hypervel\Doctor\Diagnostics\ApplicationKeyIsSet;
use Hypervel\Doctor\Diagnostics\BootstrapCacheMatchesEnvironment;
use Hypervel\Doctor\Diagnostics\CacheStoreIsReachable;
use Hypervel\Doctor\Diagnostics\ComposerAuditPasses;
use Hypervel\Doctor\Diagnostics\ComposerAutoloadIsValid;
use Hypervel\Doctor\Diagnostics\ComposerLockIsFresh;
use Hypervel\Doctor\Diagnostics\ConfigurationCanBeCached;
use Hypervel\Doctor\Diagnostics\ConfigurationFilesCanBeLoaded;
use Hypervel\Doctor\Diagnostics\DatabaseConnectionIsReachable;
use Hypervel\Doctor\Diagnostics\DebugModeMatchesEnvironment;
use Hypervel\Doctor\Diagnostics\EnvironmentFileExists;
use Hypervel\Doctor\Diagnostics\EnvironmentFileIsGitIgnored;
use Hypervel\Doctor\Diagnostics\FilesystemDisksAreReachable;
use Hypervel\Doctor\Diagnostics\MigrationsAreUpToDate;
use Hypervel\Doctor\Diagnostics\PhpVersionSatisfiesComposerRequirement;
use Hypervel\Doctor\Diagnostics\PublicStorageLinkExists;
use Hypervel\Doctor\Diagnostics\QueueConnectionIsAsynchronous;
use Hypervel\Doctor\Diagnostics\QueueConnectionIsReachable;
use Hypervel\Doctor\Diagnostics\RecommendedPhpExtensionsAreLoaded;
use Hypervel\Doctor\Diagnostics\RedisConnectionsAreReachable;
use Hypervel\Doctor\Diagnostics\RedisSupportsConfiguredFeatures;
use Hypervel\Doctor\Diagnostics\RequiredConfigurationValuesAreSet;
use Hypervel\Doctor\Diagnostics\RequiredPhpExtensionsAreLoaded;
use Hypervel\Doctor\Diagnostics\ScheduledTasksRequireScheduler;
use Hypervel\Doctor\Diagnostics\SessionDriverIsReachable;
use Hypervel\Doctor\Diagnostics\SqliteDatabaseExists;
use Hypervel\Doctor\Diagnostics\StorageIsWritable;
use Hypervel\Support\ServiceProvider;

class DoctorServiceProvider extends ServiceProvider
{
    /**
     * Register the package services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/doctor.php', 'doctor');

        $this->app->singleton(Doctor::class, function (Application $app): Doctor {
            return (new Doctor($app))->diagnostics([
                EnvironmentFileExists::class,
                ApplicationKeyIsSet::class,
                PhpVersionSatisfiesComposerRequirement::class,
                RequiredPhpExtensionsAreLoaded::class,
                RecommendedPhpExtensionsAreLoaded::class,
                // ApplicationTimezoneIsValid is omitted: Hypervel rejects invalid timezones during boot.
                ComposerAutoloadIsValid::class,
                ComposerLockIsFresh::class,
                ConfigurationFilesCanBeLoaded::class,
                ConfigurationCanBeCached::class,
                RequiredConfigurationValuesAreSet::class,
                BootstrapCacheMatchesEnvironment::class,
                DatabaseConnectionIsReachable::class,
                SqliteDatabaseExists::class,
                MigrationsAreUpToDate::class,
                CacheStoreIsReachable::class,
                RedisConnectionsAreReachable::class,
                RedisSupportsConfiguredFeatures::class,
                QueueConnectionIsReachable::class,
                QueueConnectionIsAsynchronous::class,
                SessionDriverIsReachable::class,
                ScheduledTasksRequireScheduler::class,
                PublicStorageLinkExists::class,
                FilesystemDisksAreReachable::class,
                StorageIsWritable::class,
                DebugModeMatchesEnvironment::class,
                EnvironmentFileIsGitIgnored::class,
                ComposerAuditPasses::class,
            ]);
        });
    }

    /**
     * Bootstrap the package services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                DiagnosticMakeCommand::class,
                DoctorCommand::class,
            ]);

            $this->publishes([
                __DIR__ . '/../config/doctor.php' => config_path('doctor.php'),
            ], 'doctor-config');
        }
    }
}
