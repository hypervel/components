<?php

declare(strict_types=1);

namespace Hypervel\Workbench\Console;

use Hypervel\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'workbench:purge-skeleton', description: 'Purge skeleton folder to original state')]
class PurgeSkeletonCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected ?string $signature = 'workbench:purge-skeleton
                                {--pretend : Outputs the operations but will not execute anything}';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        return $this->call('package:purge-skeleton', [
            '--pretend' => $this->option('pretend'),
        ]);
    }
}
