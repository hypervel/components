<?php

declare(strict_types=1);

namespace Hypervel\Workbench\Console;

use Hypervel\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'workbench:sync-skeleton', description: 'Sync skeleton folder to be served externally')]
class SyncSkeletonCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected ?string $signature = 'workbench:sync-skeleton';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        return $this->call('package:sync-skeleton');
    }
}
