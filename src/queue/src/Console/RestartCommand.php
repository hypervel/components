<?php

declare(strict_types=1);

namespace Hypervel\Queue\Console;

use Hypervel\Console\Command;
use Hypervel\Contracts\Cache\Repository as Cache;
use Hypervel\Queue\Worker;
use Hypervel\Support\InteractsWithTime;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'queue:restart')]
class RestartCommand extends Command
{
    use InteractsWithTime;

    /**
     * The name and signature of the console command.
     */
    protected ?string $signature = 'queue:restart';

    /**
     * The console command description.
     */
    protected string $description = 'Restart queue worker daemons after their current job';

    /**
     * Create a new queue restart command.
     */
    public function __construct(
        protected Cache $cache
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $this->cache->forever(Worker::RESTART_SIGNAL_CACHE_KEY, $this->currentTime());

        $this->components->info('Broadcasting queue restart signal.');
    }
}
