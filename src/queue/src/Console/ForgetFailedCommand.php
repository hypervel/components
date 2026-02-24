<?php

declare(strict_types=1);

namespace Hypervel\Queue\Console;

use Hypervel\Console\Command;
use Hypervel\Queue\Failed\FailedJobProviderInterface;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'queue:forget')]
class ForgetFailedCommand extends Command
{
    /**
     * The console command signature.
     */
    protected ?string $signature = 'queue:forget {id : The ID of the failed job}';

    /**
     * The console command description.
     */
    protected string $description = 'Delete a failed queue job';

    /**
     * Execute the console command.
     */
    public function handle(): ?int
    {
        if ($this->app->make(FailedJobProviderInterface::class)->forget($this->argument('id'))) {
            $this->info('Failed job deleted successfully.');
        } else {
            $this->error('No failed job matches the given ID.');

            return 1;
        }

        return null;
    }
}
