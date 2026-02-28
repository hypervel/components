<?php

declare(strict_types=1);

namespace Hypervel\Tests\Console\Command;

use Hypervel\Console\Command;
use Hypervel\Console\Prohibitable;

class FooProhibitableCommand extends Command
{
    use Prohibitable;

    public function handle(): int
    {
        if ($this->isProhibited()) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
