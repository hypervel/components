<?php

declare(strict_types=1);

namespace Hypervel\Console\Commands;

use Hypervel\Console\Command;
use Hypervel\Console\Events\SchedulePaused;
use Hypervel\Console\Scheduling\Schedule;
use Hypervel\Contracts\Cache\Repository as Cache;
use Hypervel\Contracts\Events\Dispatcher;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'schedule:pause')]
class SchedulePauseCommand extends Command
{
    /**
     * The console command description.
     */
    protected string $description = 'Pause the scheduler';

    /**
     * Execute the console command.
     */
    public function handle(Cache $cache, Dispatcher $dispatcher): int
    {
        if (! Schedule::$pausable) {
            $this->components->error('Schedule pausing is currently disabled.');

            return self::FAILURE;
        }

        $cache->forever('hypervel:schedule:paused', true);

        $dispatcher->dispatch(new SchedulePaused);

        $this->components->info('Scheduled task processing has been paused.');

        return self::SUCCESS;
    }
}
