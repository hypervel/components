<?php

declare(strict_types=1);

namespace Hypervel\Queue\Console;

use Hypervel\Console\Command;
use Hypervel\Contracts\Cache\Factory as CacheFactory;
use Hypervel\Support\InteractsWithTime;
use Hypervel\Support\Traits\HasLaravelStyleCommand;

class RestartCommand extends Command
{
    use HasLaravelStyleCommand;
    use InteractsWithTime;

    /**
     * The console command name.
     */
    protected ?string $name = 'queue:restart';

    /**
     * The console command description.
     */
    protected string $description = 'Restart queue worker daemons after their current job';

    /**
     * Create a new queue restart command.
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
        $this->cache->forever('illuminate:queue:restart', $this->currentTime());

        $this->info('Broadcasting queue restart signal.');
    }
}
