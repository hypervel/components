<?php

declare(strict_types=1);

namespace Hypervel\Database;

use Hyperf\Database\Model\Factory as HyperfDatabaseFactory;
use Hypervel\Database\Console\Migrations\FreshCommand;
use Hypervel\Database\Console\Migrations\InstallCommand;
use Hypervel\Database\Console\Migrations\MakeMigrationCommand;
use Hypervel\Database\Console\Migrations\MigrateCommand;
use Hypervel\Database\Console\Migrations\RefreshCommand;
use Hypervel\Database\Console\Migrations\ResetCommand;
use Hypervel\Database\Console\Migrations\RollbackCommand;
use Hypervel\Database\Console\Migrations\StatusCommand;
use Hypervel\Database\Console\SeedCommand;
use Hypervel\Database\Eloquent\Factories\LegacyFactoryInvoker as DatabaseFactoryInvoker;
use Hypervel\Database\Eloquent\ModelListener;
use Hypervel\Database\Listeners\RegisterConnectionResolverListener;
use Hypervel\Database\Migrations\DatabaseMigrationRepositoryFactory;
use Hypervel\Database\Migrations\MigrationRepositoryInterface;

class ConfigProvider
{
    public function __invoke(): array
    {
        return [
            'dependencies' => [
                ConnectionResolverInterface::class => ConnectionResolver::class,
                DatabaseTransactionsManager::class => DatabaseTransactionsManager::class,
                HyperfDatabaseFactory::class => DatabaseFactoryInvoker::class,
                MigrationRepositoryInterface::class => DatabaseMigrationRepositoryFactory::class,
                ModelListener::class => ModelListener::class,
            ],
            'listeners' => [
                RegisterConnectionResolverListener::class,
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
            ],
        ];
    }
}
