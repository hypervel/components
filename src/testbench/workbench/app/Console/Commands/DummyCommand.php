<?php

declare(strict_types=1);

namespace Workbench\App\Console\Commands;

use Hypervel\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'sample:command')]
class DummyCommand extends Command
{
    protected ?string $signature = 'sample:command';

    protected string $description = 'Sample command';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('It works!');

        return 0;
    }
}
