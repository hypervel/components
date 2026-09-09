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
    protected ?string $signature = 'queue:flush {--hours= : The number of hours to retain failed job data}';

    /**
     * The console command description.
     */
    protected string $description = 'Flush all of the failed queue jobs';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if ($this->isProhibited()) {
            return self::FAILURE;
        }

        $hours = $this->option('hours');

        $this->hypervel->make(FailedJobProviderInterface::class)
            ->flush($hours ? (int) $hours : null);

        if ($this->option('hours')) {
            $this->components->info("All jobs that failed more than {$this->option('hours')} hours ago have been deleted successfully.");

            return self::SUCCESS;
        }

        $this->components->info('All failed jobs deleted successfully.');

        return self::SUCCESS;
    }
}
