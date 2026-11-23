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
    protected ?string $signature = 'queue:forget {id* : The IDs of the failed jobs}';

    /**
     * The console command description.
     */
    protected string $description = 'Delete a failed queue job';

    /**
     * Execute the console command.
     */
    public function handle(): ?int
    {
        $missing = false;

        foreach ((array) $this->argument('id') as $id) {
            if ($this->hypervel->make(FailedJobProviderInterface::class)->forget($id)) {
                $this->components->info("Failed job [{$id}] deleted successfully.");
            } else {
                $this->components->error("No failed job matches the ID [{$id}].");

                $missing = true;
            }
        }

        if ($missing) {
            return self::FAILURE;
        }

        return null;
    }
}
