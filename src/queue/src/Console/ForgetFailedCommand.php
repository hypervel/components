<?php

declare(strict_types=1);

namespace Hypervel\Queue\Console;

use Hyperf\Command\Command;
use Hypervel\Queue\Failed\FailedJobProviderInterface;
use Hypervel\Support\Traits\HasLaravelStyleCommand;

class ForgetFailedCommand extends Command
{
    use HasLaravelStyleCommand;

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
        if ($this->app->get(FailedJobProviderInterface::class)->forget($this->argument('id'))) {
            $this->info('Failed job deleted successfully.');
        } else {
            $this->error('No failed job matches the given ID.');

            return 1;
        }

        return null;
    }
}
