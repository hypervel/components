<?php

declare(strict_types=1);

namespace Hypervel\Queue\Console;

use Hypervel\Console\Command;
use Hypervel\Console\Prohibitable;
use Hypervel\Queue\Failed\FailedJobProviderInterface;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'queue:flush')]
class FlushFailedCommand extends Command
{
    use Prohibitable;

    /**
     * The console command name.
     */
    protected ?string $signature = 'queue:flush
                            {--hours= : The number of hours to retain failed job data}
                            {--queue= : Flush all of the failed jobs for the specified queue}';

    /**
     * The console command description.
     */
    protected string $description = 'Flush the failed queue jobs';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if ($this->isProhibited()) {
            return self::FAILURE;
        }

        $hours = $this->option('hours');
        $queue = $this->option('queue');

        $this->hypervel->make(FailedJobProviderInterface::class)
            ->flush($hours ? (int) $hours : null, $queue);

        // A queue named "0" is valid, so check the queue option against null.
        if ($hours && $queue !== null) {
            $this->components->info("All jobs on the [{$queue}] queue that failed more than {$hours} hours ago have been deleted successfully.");
        } elseif ($hours) {
            $this->components->info("All jobs that failed more than {$hours} hours ago have been deleted successfully.");
        } elseif ($queue !== null) {
            $this->components->info("All failed jobs on the [{$queue}] queue have been deleted successfully.");
        } else {
            $this->components->info('All failed jobs deleted successfully.');
        }

        return self::SUCCESS;
    }
}
