<?php

declare(strict_types=1);

namespace Hypervel\Sanctum\Console\Commands;

use Hypervel\Console\Command;
use Hypervel\Sanctum\Sanctum;

class PruneExpired extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected ?string $signature = 'sanctum:prune-expired {--hours=24 : The number of hours to retain expired Sanctum tokens}';

    /**
     * The console command description.
     */
    protected string $description = 'Prune tokens expired for more than specified number of hours';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $model = Sanctum::$personalAccessTokenModel;

        /** @var int $hours */
        $hours = (int) $this->option('hours');

        $this->task(
            'Pruning tokens with expired expires_at timestamps',
            fn () => $model::where('expires_at', '<', now()->subHours($hours))->delete()
        );

        if ($expiration = config('sanctum.expiration')) {
            $this->task(
                'Pruning tokens with expired expiration value based on configuration file',
                fn () => $model::where('created_at', '<', now()->subMinutes($expiration + ($hours * 60)))->delete()
            );
        } else {
            $this->warn('Expiration value not specified in configuration file.');
        }

        $this->info("Tokens expired for more than [{$hours} hours] pruned successfully.");

        return 0;
    }
}