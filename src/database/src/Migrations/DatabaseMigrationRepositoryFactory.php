<?php

declare(strict_types=1);

namespace Hypervel\Database\Migrations;

use Hypervel\Contracts\Container\Container;
use Hypervel\Database\ConnectionResolverInterface;

class DatabaseMigrationRepositoryFactory
{
    public function __invoke(Container $container): DatabaseMigrationRepository
    {
        $config = $container->make('config');

        $migrations = $config->get('database.migrations', 'migrations');

        $table = is_array($migrations)
            ? ($migrations['table'] ?? 'migrations')
            : $migrations;

        return new DatabaseMigrationRepository(
            $container->make(ConnectionResolverInterface::class),
            $table
        );
    }
}
