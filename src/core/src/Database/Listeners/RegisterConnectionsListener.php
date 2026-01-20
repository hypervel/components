<?php

declare(strict_types=1);

namespace Hypervel\Database\Listeners;

use Hyperf\Database\Connection;
use Hyperf\Event\Contract\ListenerInterface;
use Hyperf\Framework\Event\BootApplication;
use Hypervel\Database\Connections\MySqlConnection;
use Hypervel\Database\Connections\PostgreSqlConnection;
use Hypervel\Database\Connections\SQLiteConnection;

class RegisterConnectionsListener implements ListenerInterface
{
    public function listen(): array
    {
        return [
            BootApplication::class,
        ];
    }

    /**
     * Register custom connection resolvers for all database drivers.
     */
    public function process(object $event): void
    {
        Connection::resolverFor('mysql', static function ($connection, $database, $prefix, $config) {
            return new MySqlConnection($connection, $database, $prefix, $config);
        });

        Connection::resolverFor('pgsql', static function ($connection, $database, $prefix, $config) {
            return new PostgreSqlConnection($connection, $database, $prefix, $config);
        });

        Connection::resolverFor('sqlite', static function ($connection, $database, $prefix, $config) {
            return new SQLiteConnection($connection, $database, $prefix, $config);
        });
    }
}
