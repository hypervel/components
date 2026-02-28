<?php

declare(strict_types=1);

namespace Hypervel\Tests\Console\Command;

use Hypervel\Console\Command;

class SwooleFlagsCommand extends Command
{
    protected int $hookFlags = SWOOLE_HOOK_CURL | SWOOLE_HOOK_ALL;

    public function handle(): void
    {
    }

    public function getHookFlags(): int
    {
        return $this->hookFlags;
    }
}
