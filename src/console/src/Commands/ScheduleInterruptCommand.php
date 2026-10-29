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
    protected ?string $signature = 'schedule:interrupt';

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
        $this->cache->forever('hypervel:schedule:interrupt', Date::now()->getTimestampMs());

        $this->components->info('Broadcasting schedule interrupt signal.');

        return self::SUCCESS;
    }
}
