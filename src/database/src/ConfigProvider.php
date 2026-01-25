<?php

declare(strict_types=1);

namespace Hypervel\Database;

use Hypervel\Database\Console\Migrations\FreshCommand;
use Hypervel\Database\Console\Migrations\InstallCommand;
use Hypervel\Database\Console\Migrations\MakeMigrationCommand;
use Hypervel\Database\Console\Migrations\MigrateCommand;
use Hypervel\Database\Console\Migrations\RefreshCommand;
use Hypervel\Database\Console\Migrations\ResetCommand;
use Hypervel\Database\Console\Migrations\RollbackCommand;
use Hypervel\Database\Console\Migrations\StatusCommand;
use Hypervel\Database\Console\SeedCommand;
use Hypervel\Database\Console\WipeCommand;
use Hypervel\Database\Listeners\RegisterConnectionResolverListener;
use Hypervel\Database\Listeners\RegisterSQLiteConnectionListener;
use Hypervel\Database\Listeners\UnsetContextInTaskWorkerListener;
use Hypervel\Database\Migrations\DatabaseMigrationRepositoryFactory;
use Hypervel\Database\Migrations\MigrationRepositoryInterface;

class ConfigProvider
{
    public function __invoke(): array
    {
        return [
            'dependencies' => [
                ConnectionResolverInterface::class => ConnectionResolver::class,
                MigrationRepositoryInterface::class => DatabaseMigrationRepositoryFactory::class,
            ],
            'listeners' => [
                RegisterConnectionResolverListener::class,
                RegisterSQLiteConnectionListener::class,
                UnsetContextInTaskWorkerListener::class,
            ],
            'commands' => [
                FreshCommand::class,
                InstallCommand::class,
                MakeMigrationCommand::class,
                MigrateCommand::class,
                RefreshCommand::class,
                ResetCommand::class,
                RollbackCommand::class,
                SeedCommand::class,
                StatusCommand::class,
                WipeCommand::class,
            ],
        ];
    }
}
