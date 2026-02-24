<?php

declare(strict_types=1);

namespace Hypervel\Queue\Console;

use Hypervel\Console\Command;
use Hypervel\Queue\Failed\FailedJobProviderInterface;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'queue:flush')]
class FlushFailedCommand extends Command
{
    /**
     * The console command name.
     */
    protected ?string $signature = 'queue:flush {--hours= : The number of hours to retain failed job data}';

    /**
     * The console command description.
     */
    protected string $description = 'Flush all of the failed queue jobs';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $hours = $this->option('hours');

        $this->app->make(FailedJobProviderInterface::class)
            ->flush($hours ? (int) $hours : null);

        if ($this->option('hours')) {
            $this->info("All jobs that failed more than {$this->option('hours')} hours ago have been deleted successfully.");

            return;
        }

        $this->info('All failed jobs deleted successfully.');
    }
}
