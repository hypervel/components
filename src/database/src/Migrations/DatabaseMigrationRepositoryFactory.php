<?php

declare(strict_types=1);

namespace Hypervel\Database\Migrations;

use Hypervel\Contracts\Config\Repository;
use Hypervel\Database\ConnectionResolverInterface;
use Psr\Container\ContainerInterface;

class DatabaseMigrationRepositoryFactory
{
    public function __invoke(ContainerInterface $container): DatabaseMigrationRepository
    {
        $config = $container->get(Repository::class);

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
