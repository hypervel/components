<?php

declare(strict_types=1);

namespace Hypervel\Tests\Console\Fixtures\Commands;

use Hypervel\Console\Command;

class SwooleFlagsCommand extends Command
{
    protected int $hookFlags = SWOOLE_HOOK_CURL | SWOOLE_HOOK_ALL;

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
