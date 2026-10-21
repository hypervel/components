<?php

declare(strict_types=1);

namespace Hypervel\Tests\Console\Fixtures\Commands;

use Hypervel\Console\Command;

class DefaultSwooleFlagsCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): void
    {
    }

    /**
     * Get the coroutine hook flags.
     */
    public function getHookFlags(): int
    {
        return $this->hookFlags;
    }
}
