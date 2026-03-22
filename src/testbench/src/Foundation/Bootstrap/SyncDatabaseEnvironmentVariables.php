<?php

declare(strict_types=1);

namespace Hypervel\Testbench\Foundation\Bootstrap;

use Hypervel\Contracts\Config\Repository;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Testbench\Foundation\Concerns\HandlesDatabaseConnections;

/**
 * @internal
 */
final class SyncDatabaseEnvironmentVariables
{
    use HandlesDatabaseConnections;

    /**
     * Bootstrap the given application.
     */
    public function bootstrap(Application $app): void
    {
        tap($app->make('config'), function (Repository $config): void {
            $this->usesDatabaseConnectionsEnvironmentVariables($config, 'mysql', 'MYSQL');
            $this->usesDatabaseConnectionsEnvironmentVariables($config, 'mariadb', 'MARIADB');
            $this->usesDatabaseConnectionsEnvironmentVariables($config, 'pgsql', 'POSTGRES');
        });
    }
}
