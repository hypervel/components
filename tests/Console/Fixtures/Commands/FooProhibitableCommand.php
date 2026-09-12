<?php

declare(strict_types=1);

namespace Hypervel\Tests\Console\Fixtures\Commands;

use Hypervel\Console\Command;
use Hypervel\Console\Prohibitable;

class FooProhibitableCommand extends Command
{
    use Prohibitable;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if ($this->isProhibited()) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
