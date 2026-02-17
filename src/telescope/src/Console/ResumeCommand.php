<?php

declare(strict_types=1);

namespace Hypervel\Telescope\Console;

use Hypervel\Console\Command;
use Hypervel\Contracts\Cache\Factory as CacheFactory;

class ResumeCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected ?string $signature = 'telescope:resume';

    /**
     * The console command description.
     */
    protected string $description = 'Unpause all Telescope watchers';

    /**
     * Execute the console command.
     */
    public function handle(CacheFactory $cache)
    {
        /* @phpstan-ignore-next-line */
        if ($cache->get('telescope:pause-recording')) {
            /* @phpstan-ignore-next-line */
            $cache->forget('telescope:pause-recording');
        }

        $this->info('Telescope watchers resumed successfully.');
    }
}
