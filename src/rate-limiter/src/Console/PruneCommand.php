<?php

declare(strict_types=1);

namespace Hypervel\RateLimiter\Console;

use Hypervel\Console\Command;
use Hypervel\RateLimiter\Contracts\PrunableStore;
use Hypervel\RateLimiter\RateLimiter;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'rate-limiter:prune')]
class PruneCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected ?string $signature = 'rate-limiter:prune
                                {store? : The rate limiter store to prune}
                                {--chunk=1000 : The number of expired entries to delete per batch}';

    /**
     * The console command description.
     */
    protected string $description = 'Prune expired rate limiter state';

    /**
     * Execute the console command.
     */
    public function handle(RateLimiter $rateLimiter): int
    {
        $name = $this->argument('store');
        $name = is_string($name) ? $name : null;
        $store = $rateLimiter->store($name)->getStore();

        if (! $store instanceof PrunableStore) {
            $name ??= $rateLimiter->getDefaultInstance();
            $this->components->error("Rate limiter store [{$name}] does not support pruning.");

            return self::FAILURE;
        }

        $pruned = $store->pruneExpired((int) $this->option('chunk'));
        $this->components->info("Pruned {$pruned} expired rate limiter entries.");

        return self::SUCCESS;
    }
}
