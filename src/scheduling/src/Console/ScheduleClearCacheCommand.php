<?php

declare(strict_types=1);

namespace Hypervel\Scheduling\Console;

use Hyperf\Command\Command;
use Hypervel\Scheduling\Schedule;
use Hypervel\Support\Traits\HasLaravelStyleCommand;

class ScheduleClearCacheCommand extends Command
{
    use HasLaravelStyleCommand;

    /**
     * The console command name.
     */
    protected ?string $name = 'schedule:clear-cache';

    /**
     * The console command description.
     */
    protected string $description = 'Delete the cached mutex files created by scheduler';

    /**
     * Execute the console command.
     */
    public function handle(Schedule $schedule)
    {
        $mutexCleared = false;

        foreach ($schedule->events() as $event) {
            if ($event->mutex->exists($event)) {
                $this->info(sprintf('Deleting mutex for [%s]', $event->command));

                $event->mutex->forget($event);

                $mutexCleared = true;
            }
        }

        if (! $mutexCleared) {
            $this->info('No mutex files were found.');
        }
    }
}
