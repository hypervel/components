<?php

declare(strict_types=1);

namespace Hypervel\Console\Commands;

use Hypervel\Console\Command;
use Hypervel\Contracts\Cache\Repository as Cache;
use Hypervel\Support\Facades\Date;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'schedule:interrupt')]
class ScheduleInterruptCommand extends Command
{
    /**
     * The console signature name.
     */
    protected ?string $signature = 'schedule:interrupt
        {--minutes=1 : TTL in minutes for the interrupt signal (minimum 1)}
    ';

    /**
     * The console command description.
     */
    protected string $description = 'Interrupt the current schedule run';

    /**
     * Create a new schedule interrupt command.
     */
    public function __construct(
        protected Cache $cache
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $minutes = filter_var($this->option('minutes'), FILTER_VALIDATE_INT);

        if ($minutes === false || $minutes < 1) {
            $this->components->error('The --minutes option must be an integer of at least 1.');

            return self::FAILURE;
        }

        $this->cache->put(
            'hypervel:schedule:interrupt',
            true,
            Date::now()->addMinutes($minutes)
        );

        $this->components->info('Broadcasting schedule interrupt signal.');

        return self::SUCCESS;
    }
}
