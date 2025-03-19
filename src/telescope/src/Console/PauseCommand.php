<?php

declare(strict_types=1);

namespace Hypervel\Telescope\Console;

use Hypervel\Cache\Contracts\Factory as CacheFactory;
use Hypervel\Foundation\Console\Command;

class PauseCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected ?string $signature = 'telescope:pause';

    /**
     * The console command description.
     */
    protected string $description = 'Pause all Telescope watchers';

    /**
     * Execute the console command.
     */
    public function handle(CacheFactory $cache)
    {
        /* @phpstan-ignore-next-line */
        if (! $cache->get('telescope:pause-recording')) {
            /* @phpstan-ignore-next-line */
            $cache->put('telescope:pause-recording', true, now()->addDays(30));
        }

        $this->info('Telescope watchers paused successfully.');
    }
}
