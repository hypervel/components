<?php

declare(strict_types=1);

namespace Hypervel\Devtool;

use Hyperf\Devtool\Generator\GeneratorCommand;
use Hypervel\Devtool\Commands\EventListCommand;
use Hypervel\Devtool\Commands\WatchCommand;
use Hypervel\Devtool\Generator\BatchesTableCommand;
use Hypervel\Devtool\Generator\ChannelCommand;
use Hypervel\Devtool\Generator\ComponentCommand;
use Hypervel\Devtool\Generator\ConsoleCommand;
use Hypervel\Devtool\Generator\EventCommand;
use Hypervel\Devtool\Generator\FactoryCommand;
use Hypervel\Devtool\Generator\JobCommand;
use Hypervel\Devtool\Generator\ListenerCommand;
use Hypervel\Devtool\Generator\MailCommand;
use Hypervel\Devtool\Generator\ModelCommand;
use Hypervel\Devtool\Generator\NotificationCommand;
use Hypervel\Devtool\Generator\NotificationTableCommand;
use Hypervel\Devtool\Generator\ObserverCommand;
use Hypervel\Devtool\Generator\PolicyCommand;
use Hypervel\Devtool\Generator\ProviderCommand;
use Hypervel\Devtool\Generator\QueueFailedTableCommand;
use Hypervel\Devtool\Generator\QueueTableCommand;
use Hypervel\Devtool\Generator\RequestCommand;
use Hypervel\Devtool\Generator\RuleCommand;
use Hypervel\Devtool\Generator\SeederCommand;
use Hypervel\Devtool\Generator\SessionTableCommand;
use Hypervel\Devtool\Generator\TestCommand;

class ConfigProvider
{
    public function __invoke(): array
    {
        if (! class_exists(GeneratorCommand::class)) {
            return [];
        }

        return [
            'commands' => [
                WatchCommand::class,
                ProviderCommand::class,
                EventCommand::class,
                ListenerCommand::class,
                ComponentCommand::class,
                TestCommand::class,
                SessionTableCommand::class,
                RuleCommand::class,
                ConsoleCommand::class,
                ModelCommand::class,
                FactoryCommand::class,
                SeederCommand::class,
                EventListCommand::class,
                RequestCommand::class,
                NotificationTableCommand::class,
                BatchesTableCommand::class,
                QueueTableCommand::class,
                QueueFailedTableCommand::class,
                JobCommand::class,
                ChannelCommand::class,
                ObserverCommand::class,
                NotificationCommand::class,
                MailCommand::class,
                PolicyCommand::class,
            ],
        ];
    }
}
