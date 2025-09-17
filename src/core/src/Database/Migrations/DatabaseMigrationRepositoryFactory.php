<?php

declare(strict_types=1);

namespace Hypervel\Database\Migrations;

use Hyperf\Contract\ConfigInterface;
use Hyperf\Database\ConnectionResolverInterface;
use Psr\Container\ContainerInterface;

use function Hyperf\Support\make;

class DatabaseMigrationRepositoryFactory
{
    public function __invoke(ContainerInterface $container): DatabaseMigrationRepository
    {
        $resolver = $container->get(ConnectionResolverInterface::class);
        $config = $container->get(ConfigInterface::class);
        $table = $config->get('database.migrations', 'migrations');

        return make(DatabaseMigrationRepository::class, [
            'resolver' => $resolver,
            'table' => $table,
        ]);
    }
}
