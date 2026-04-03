<?php

declare(strict_types=1);

namespace Hypervel\Testbench\Bootstrap;

use Hypervel\Contracts\Config\Repository as RepositoryContract;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Foundation\Bootstrap\LoadConfiguration as BaseLoadConfiguration;
use Hypervel\Support\Collection;
use Hypervel\Testbench\Foundation\Env;

/**
 * @internal
 */
class LoadConfiguration extends BaseLoadConfiguration
{
    /**
     * Bootstrap the given application.
     */
    public function bootstrap(Application $app): void
    {
        parent::bootstrap($app);

        $config = $app->make('config');

        if ($config->get('database.connections.testing') === null) {
            $config->set('database.connections.testing', [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'foreign_key_constraints' => Env::get('DB_FOREIGN_KEYS', false),
            ]);
        }

        $this->configureDefaultDatabaseConnection($config);
    }

    /**
     * Load the configuration items from all of the files.
     */
    protected function loadConfigurationFiles(Application $app, RepositoryContract $repository): void
    {
        $files = $this->extendsLoadedConfiguration(
            (new Collection($this->getConfigurationFiles($app)))
                ->map(fn (string $path, string $key): string => $this->resolveConfigurationFile($path, $key))
        );

        $base = $app->shouldMergeFrameworkConfiguration()
            ? $this->getBaseConfiguration()
            : [];

        foreach ((new Collection($base))->diffKeys($files) as $name => $config) {
            $repository->set($name, $config);
        }

        foreach ($files as $name => $path) {
            $base = $this->loadConfigurationFile($repository, $name, $path, $base);
        }

        foreach ($base as $name => $config) {
            $repository->set($name, $config);
        }
    }

    /**
     * Resolve the configuration file.
     */
    protected function resolveConfigurationFile(string $path, string $key): string
    {
        return $path;
    }

    /**
     * Extend the loaded configuration.
     *
     * @param Collection<string, string> $configurations
     * @return Collection<string, string>
     */
    protected function extendsLoadedConfiguration(Collection $configurations): Collection
    {
        return $configurations;
    }

    /**
     * Configure the default database connection.
     */
    protected function configureDefaultDatabaseConnection(RepositoryContract $repository): void
    {
        $sqliteDatabase = $repository->get('database.connections.sqlite.database');

        if ($repository->get('database.default') === 'sqlite' && is_string($sqliteDatabase) && ! is_file($sqliteDatabase)) {
            $repository->set('database.default', 'testing');
            $this->rewriteQueueDatabaseConnection($repository, 'queue.batching.database');
            $this->rewriteQueueDatabaseConnection($repository, 'queue.failed.database');
        }
    }

    /**
     * Rewrite queue database settings when testbench swaps the default DB connection.
     */
    protected function rewriteQueueDatabaseConnection(RepositoryContract $repository, string $key): void
    {
        if ($repository->get($key) === 'sqlite') {
            $repository->set($key, 'testing');
        }
    }
}
