<?php

declare(strict_types=1);

namespace Hypervel\Tests\Console\Commands;

use Hypervel\Console\Command;

class DefaultSwooleFlagsCommand extends Command
{
    public function handle(): void
    {
    }

    public function getHookFlags(): int
    {
        return $this->hookFlags;
    }
}
