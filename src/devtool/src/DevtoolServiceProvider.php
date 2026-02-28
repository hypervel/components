<?php

declare(strict_types=1);

namespace Hypervel\Devtool;

use Hypervel\Devtool\Commands\EventListCommand;
use Hypervel\Devtool\Commands\WatchCommand;
use Hypervel\Devtool\Generator\BatchesTableCommand;
use Hypervel\Devtool\Generator\CacheLocksTableCommand;
use Hypervel\Devtool\Generator\CacheTableCommand;
use Hypervel\Devtool\Generator\ChannelCommand;
use Hypervel\Devtool\Generator\ComponentCommand;
use Hypervel\Devtool\Generator\ConsoleCommand;
use Hypervel\Devtool\Generator\ControllerCommand;
use Hypervel\Devtool\Generator\EventCommand;
use Hypervel\Devtool\Generator\ExceptionCommand;
use Hypervel\Devtool\Generator\FactoryCommand;
use Hypervel\Devtool\Generator\JobCommand;
use Hypervel\Devtool\Generator\ListenerCommand;
use Hypervel\Devtool\Generator\MailCommand;
use Hypervel\Devtool\Generator\MiddlewareCommand;
use Hypervel\Devtool\Generator\ModelCommand;
use Hypervel\Devtool\Generator\NotificationCommand;
use Hypervel\Devtool\Generator\NotificationTableCommand;
use Hypervel\Devtool\Generator\ObserverCommand;
use Hypervel\Devtool\Generator\PolicyCommand;
use Hypervel\Devtool\Generator\ProviderCommand;
use Hypervel\Devtool\Generator\QueueFailedTableCommand;
use Hypervel\Devtool\Generator\QueueTableCommand;
use Hypervel\Devtool\Generator\RequestCommand;
use Hypervel\Devtool\Generator\ResourceCommand;
use Hypervel\Devtool\Generator\RuleCommand;
use Hypervel\Devtool\Generator\SeederCommand;
use Hypervel\Devtool\Generator\SessionTableCommand;
use Hypervel\Devtool\Generator\TestCommand;
use Hypervel\Support\ServiceProvider;

class DevtoolServiceProvider extends ServiceProvider
{
    /**
     * Register the service provider.
     */
    public function register(): void
    {
        $this->commands([
            BatchesTableCommand::class,
            CacheLocksTableCommand::class,
            CacheTableCommand::class,
            ChannelCommand::class,
            ComponentCommand::class,
            ConsoleCommand::class,
            ControllerCommand::class,
            EventCommand::class,
            EventListCommand::class,
            ExceptionCommand::class,
            FactoryCommand::class,
            JobCommand::class,
            ListenerCommand::class,
            MailCommand::class,
            MiddlewareCommand::class,
            ModelCommand::class,
            NotificationCommand::class,
            NotificationTableCommand::class,
            ObserverCommand::class,
            PolicyCommand::class,
            ProviderCommand::class,
            QueueFailedTableCommand::class,
            QueueTableCommand::class,
            RequestCommand::class,
            ResourceCommand::class,
            RuleCommand::class,
            SeederCommand::class,
            SessionTableCommand::class,
            TestCommand::class,
            WatchCommand::class,
        ]);
    }
}
