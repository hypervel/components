<?php

declare(strict_types=1);

namespace Hypervel\Console;

use Hypervel\Console\Commands\ScheduleClearCacheCommand;
use Hypervel\Console\Commands\ScheduleInterruptCommand;
use Hypervel\Console\Commands\ScheduleListCommand;
use Hypervel\Console\Commands\SchedulePauseCommand;
use Hypervel\Console\Commands\ScheduleResumeCommand;
use Hypervel\Console\Commands\ScheduleRunCommand;
use Hypervel\Console\Commands\ScheduleTestCommand;
use Hypervel\Console\Events\BeforeHandle;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Log\Context\Repository;
use Hypervel\Support\Env;
use Hypervel\Support\ServiceProvider;

class ConsoleServiceProvider extends ServiceProvider
{
    /**
     * Register the service provider.
     */
    public function register(): void
    {
        $this->commands([
            ScheduleClearCacheCommand::class,
            ScheduleListCommand::class,
            ScheduleRunCommand::class,
            ScheduleInterruptCommand::class,
            SchedulePauseCommand::class,
            ScheduleResumeCommand::class,
            ScheduleTestCommand::class,
        ]);
    }

    /**
     * Bootstrap the service provider.
     */
    public function boot(Dispatcher $events): void
    {
        if (! $this->app->runningInConsole()
            || ($encoded = Env::get('__HYPERVEL_CONTEXT')) === null) {
            return;
        }

        // Consume startup transport at boot so child processes cannot inherit stale context.
        Env::deleteMany(['__HYPERVEL_CONTEXT']);
        // The native environment also needs clearing when Env's putenv adapter is disabled.
        putenv('__HYPERVEL_CONTEXT');

        if (is_string($encoded)
            && is_array($context = unserialize(base64_decode($encoded, true), ['allowed_classes' => false]))) {
            $events->listen(BeforeHandle::class, static function () use (&$context): void {
                if ($context !== null) {
                    // Hydration callbacks may invoke another command, so claim the startup context first.
                    $payload = $context;
                    $context = null;

                    Repository::getInstance()->hydrate($payload);
                }
            });
        }
    }
}
