<?php

declare(strict_types=1);

namespace Hypervel\Queue\Console;

use Hyperf\Command\Command;
use Hypervel\Queue\Failed\FailedJobProviderInterface;
use Hypervel\Support\Traits\HasLaravelStyleCommand;

class FlushFailedCommand extends Command
{
    use HasLaravelStyleCommand;

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
        $this->app->get(FailedJobProviderInterface::class)
            ->flush($this->option('hours'));

        if ($this->option('hours')) {
            $this->info("All jobs that failed more than {$this->option('hours')} hours ago have been deleted successfully.");

            return;
        }

        $this->info('All failed jobs deleted successfully.');
    }
}
