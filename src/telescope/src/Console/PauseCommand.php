<?php

declare(strict_types=1);

namespace Hypervel\Telescope\Console;

use Hypervel\Console\Command;
use Hypervel\Contracts\Cache\Repository as Cache;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'telescope:pause')]
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
    public function handle(Cache $cache)
    {
        if (! $cache->get('telescope:pause-recording')) {
            $cache->put('telescope:pause-recording', true, now()->addDays(30));
        }

        $this->info('Telescope watchers paused successfully.');
    }
}
