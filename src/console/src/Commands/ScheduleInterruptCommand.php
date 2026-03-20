<?php

declare(strict_types=1);

namespace Hypervel\Console\Commands;

use Hypervel\Console\Command;
use Hypervel\Contracts\Cache\Factory as CacheFactory;
use Hypervel\Support\Facades\Date;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'schedule:interrupt')]
class ScheduleInterruptCommand extends Command
{
    /**
     * The console signature name.
     */
    protected ?string $signature = 'schedule:interrupt
        {--minutes=1 : TTL in minutes for the interrupt signal}
    ';

    /**
     * The console command description.
     */
    protected string $description = 'Interrupt the current schedule run';

    /**
     * Create a new schedule interrupt command.
     *
     * @param CacheFactory $cache the cache store implementation
     */
    public function __construct(
        protected CacheFactory $cache
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        /* @phpstan-ignore-next-line */
        $this->cache->put(
            'hypervel:schedule:interrupt',
            true,
            Date::now()->addMinutes((int) $this->option('minutes'))
        );

        $this->components->info('Broadcasting schedule interrupt signal.');
    }
}
