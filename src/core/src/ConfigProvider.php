<?php

declare(strict_types=1);

namespace Hypervel;

use Hyperf\Command\Concerns\Confirmable;
use Hyperf\Coroutine\Coroutine;
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
use Hyperf\ViewEngine\Compiler\CompilerInterface;
use Hypervel\Database\Console\SeedCommand;
use Hypervel\Database\Eloquent\Factories\LegacyFactoryInvoker as DatabaseFactoryInvoker;
use Hypervel\Database\Migrations\MigrationCreator;
use Hypervel\Database\TransactionListener;
use Hypervel\View\CompilerFactory;

class ConfigProvider
{
    public function __invoke(): array
    {
        return [
            'dependencies' => [
                HyperfDatabaseFactory::class => DatabaseFactoryInvoker::class,
                HyperfMigrationCreator::class => MigrationCreator::class,
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
                        Confirmable::class => __DIR__ . '/../class_map/Command/Concerns/Confirmable.php',
                        Coroutine::class => __DIR__ . '/../class_map/Coroutine/Coroutine.php',
                    ],
                ],
            ],
        ];
    }
}
