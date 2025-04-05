<?php

declare(strict_types=1);

namespace Workbench\App\Console\Commands;

use Hypervel\Console\Command;

class DemoCommand extends Command
{
    protected ?string $signature = 'sample:command';

    protected string $description = 'Sample command';

    public function handle()
    {
        $this->info('It works!');

        return 0;
    }
}
