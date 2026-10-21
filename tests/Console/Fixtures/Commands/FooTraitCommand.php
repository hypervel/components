<?php

declare(strict_types=1);

namespace Hypervel\Tests\Console\Fixtures\Commands;

use Hypervel\Console\Command;
use Hypervel\Tests\Console\Fixtures\Commands\Traits\Foo;

class FooTraitCommand extends Command
{
    use Foo;

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
    }
}
