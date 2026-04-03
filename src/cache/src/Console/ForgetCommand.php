<?php

declare(strict_types=1);

namespace Hypervel\Cache\Console;

use Hypervel\Cache\CacheManager;
use Hypervel\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'cache:forget')]
class ForgetCommand extends Command
{
    /**
     * The console command signature.
     */
    protected ?string $signature = 'cache:forget {key : The key to remove} {store? : The store to remove the key from}';

    /**
     * The console command description.
     */
    protected string $description = 'Remove an item from the cache';

    /**
     * Create a new cache forget command instance.
     */
    public function __construct(
        protected CacheManager $cache,
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $this->cache->store($this->argument('store'))->forget(
            $this->argument('key')
        );

        $this->components->info('The [' . $this->argument('key') . '] key has been removed from the cache.');
    }
}
