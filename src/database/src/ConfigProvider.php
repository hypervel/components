<?php

declare(strict_types=1);

namespace Hypervel\Database;

use Hyperf\Database\Commands\Migrations\BaseCommand as MigrationBaseCommand;
use Hyperf\Database\Commands\Migrations\FreshCommand;
use Hyperf\Database\Commands\Migrations\InstallCommand;
use Hyperf\Database\Commands\Migrations\MigrateCommand;
use Hyperf\Database\Commands\Migrations\RefreshCommand;
use Hyperf\Database\Commands\Migrations\ResetCommand;
use Hyperf\Database\Commands\Migrations\RollbackCommand;
use Hyperf\Database\Commands\Migrations\StatusCommand;
use Hyperf\Database\Migrations\MigrationCreator as HyperfMigrationCreator;
use Hyperf\Database\Model\Factory as HyperfDatabaseFactory;
use Hypervel\Database\Console\SeedCommand;
use Hypervel\Database\Eloquent\Factories\LegacyFactoryInvoker as DatabaseFactoryInvoker;
use Hypervel\Database\Migrations\MigrationCreator;

class ConfigProvider
{
    public function __invoke(): array
    {
        return [
            'dependencies' => [
                HyperfDatabaseFactory::class => DatabaseFactoryInvoker::class,
                HyperfMigrationCreator::class => MigrationCreator::class,
            ],
            'listeners' => [
                TransactionListener::class,
            ],
            'commands' => [
                InstallCommand::class,
                MigrateCommand::class,
                FreshCommand::class,
                RefreshCommand::class,
                ResetCommand::class,
                RollbackCommand::class,
                StatusCommand::class,
                SeedCommand::class,
            ],
            'annotations' => [
                'scan' => [
                    'class_map' => [
                        MigrationBaseCommand::class => __DIR__ . '/../class_map/Database/Commands/Migrations/BaseCommand.php',
                    ],
                ],
            ],
        ];
    }
}
