<?php

declare(strict_types=1);

namespace Hypervel;

use Hyperf\Command\Concerns\Confirmable;
use Hyperf\Database\Commands\Migrations\BaseCommand as MigrationBaseCommand;
use Hyperf\Database\Commands\Migrations\FreshCommand;
use Hyperf\Database\Commands\Migrations\InstallCommand;
use Hyperf\Database\Commands\Migrations\MigrateCommand;
use Hyperf\Database\Commands\Migrations\RefreshCommand;
use Hyperf\Database\Commands\Migrations\ResetCommand;
use Hyperf\Database\Commands\Migrations\RollbackCommand;
use Hyperf\Database\Commands\Migrations\StatusCommand;
use Hyperf\Database\Commands\Seeders\BaseCommand as SeederBaseCommand;
use Hyperf\Database\Commands\Seeders\SeedCommand;
use Hyperf\Database\Migrations\Migration;
use Hyperf\Database\Model\Factory as HyperfDatabaseFactory;
use Hyperf\Redis\Pool\RedisPool as HyperfRedisPool;
use Hyperf\ViewEngine\Compiler\CompilerInterface;
use Hypervel\Database\Eloquent\Factories\FactoryInvoker as DatabaseFactoryInvoker;
use Hypervel\Database\Migrations\MigrationCreator;
use Hypervel\Database\Migrations\MigrationCreator as HyperfMigrationCreator;
use Hypervel\Database\TransactionListener;
use Hypervel\Redis\RedisPool;
use Hypervel\View\CompilerFactory;

class ConfigProvider
{
    public function __invoke(): array
    {
        return [
            'dependencies' => [
                Migration::class => __DIR__ . '/../class_map/Database/Migrations/Migration.php', // TODO: this will be removed in the future version
                HyperfDatabaseFactory::class => DatabaseFactoryInvoker::class,
                HyperfMigrationCreator::class => MigrationCreator::class,
                HyperfRedisPool::class => RedisPool::class,
                CompilerInterface::class => CompilerFactory::class,
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
                        SeederBaseCommand::class => __DIR__ . '/../class_map/Database/Commands/Seeders/BaseCommand.php',
                        Confirmable::class => __DIR__ . '/../class_map/Command/Concerns/Confirmable.php',
                    ],
                ],
            ],
        ];
    }
}
