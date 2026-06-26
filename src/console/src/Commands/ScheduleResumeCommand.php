<?php

declare(strict_types=1);

namespace Hypervel\Console\Commands;

use Hypervel\Console\Command;
use Hypervel\Console\Events\ScheduleResumed;
use Hypervel\Contracts\Cache\Repository as Cache;
use Hypervel\Contracts\Events\Dispatcher;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'schedule:resume', aliases: ['schedule:continue'])]
class ScheduleResumeCommand extends Command
{
    /**
     * The console command description.
     */
    protected string $description = 'Resume the schedule';

    /**
     * The console command name aliases.
     *
     * @var list<string>
     */
    protected array $aliases = ['schedule:continue'];

    /**
     * Execute the console command.
     */
    public function handle(Cache $cache, Dispatcher $dispatcher): int
    {
        $cache->forget('hypervel:schedule:paused');

        $dispatcher->dispatch(new ScheduleResumed);

        $this->components->info('Scheduled task processing has resumed.');

        return self::SUCCESS;
    }
}
