<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Fixtures\Config;

use Hypervel\Contracts\Config\Repository;
use Hypervel\Support\ServiceProvider;

use function config_path;
use function dirname;

class ConfigFixtureServiceProvider extends ServiceProvider
{
    /**
     * The config key of the package file.
     */
    private const string PACKAGE_KEY = 'package';

    /**
     * The config key of a merged file, held in a static property as Sentry's provider holds its key.
     */
    public static string $propertyKey = 'property';

    /**
     * Register the fixture configuration.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/config/package.php', self::PACKAGE_KEY);
        $this->mergeConfigFrom(__DIR__ . '/config/merged.php', 'merged');
        $this->replaceConfigRecursivelyFrom(__DIR__ . '/config/recursive.php', 'recursive');
        $this->mergeConfigFrom(__DIR__ . '/config/overlap.php', 'overlap');
        $this->mergeConfigFrom(__DIR__ . '/config/partial.php', 'partial');
        $this->mergeConfigFrom(__DIR__ . '/config/open.php', 'open');
        $this->replaceConfigRecursivelyFrom(__DIR__ . '/config/open-recursive.php', 'open-recursive');
        $this->mergeConfigFrom(__DIR__ . '/config/listed.php', 'listed');
        $this->replaceConfigRecursivelyFrom(__DIR__ . '/config/replaced.php', 'replaced');
        $this->registerPropertyConfig();

        // Only the tracker sees this write: the static pass does not follow locals.
        $config = $this->app->make('config');
        $config->set('package.direct', 'value');

        $this->configureUsing(static function (Repository $config): void {
            $config->set('package.written', $config->string('package.key'));
        });

        $this->configureUsing(static function (): void {
            app('config')->set('package.helper', 'value');
        });

        $this->publishes([__DIR__ . '/config/published.php' => config_path('published.php')]);

        if ($this->app->runningInConsole()) {
            $this->mergeConfigFrom(__DIR__ . '/config/conditional.php', 'conditional');
        }

        // The analysis boot runs in the console, so only the static pass sees this write.
        if (! $this->app->runningInConsole()) {
            $this->app->make('config')->set('package.web', 'value');

            return;
        }

        $this->mergeConfigFrom(__DIR__ . '/config/conditional.php', 'after-return');
    }

    /**
     * Write through a repository parameter, as the gRPC provider appends its server.
     */
    protected function writeThroughParameter(Repository $config): void
    {
        $config->set('package.parameter', 'value');
    }

    /**
     * Write a named entry with a dynamic name, as packages register disks.
     */
    protected function registerDisk(string $name): void
    {
        config(['merged.disks.' . $name => ['driver' => 'local']]);
    }

    /**
     * Write a key whose last segment is only partly constant.
     */
    protected function registerSuffixed(string $suffix): void
    {
        config(["partial.disks{$suffix}" => true]);
    }

    /**
     * Merge the config file keyed by the static property.
     */
    protected function registerPropertyConfig(): void
    {
        $this->mergeConfigFrom(dirname(__DIR__) . '/Config/config/property.php', static::$propertyKey);
    }

    /**
     * Get the package configuration's named-entry options.
     */
    protected function mergeableOptions(string $name): array
    {
        return $name === self::PACKAGE_KEY ? ['stores'] : [];
    }
}
