<?php

declare(strict_types=1);

namespace Hypervel\Database\Migrations;

use Hypervel\Database\ConnectionResolverInterface;
use Hypervel\Contracts\Container\Container;

class DatabaseMigrationRepositoryFactory
{
    public function __invoke(Container $container): DatabaseMigrationRepository
    {
        $config = $container->get('config');

        $migrations = $config->get('database.migrations', 'migrations');

        $table = is_array($migrations)
            ? ($migrations['table'] ?? 'migrations')
            : $migrations;

        return new DatabaseMigrationRepository(
            $container->get(ConnectionResolverInterface::class),
            $table
        );
    }
}
