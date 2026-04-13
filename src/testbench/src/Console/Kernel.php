<?php

declare(strict_types=1);

namespace Hypervel\Testbench\Console;

use Hypervel\Testbench\Foundation\Console\Kernel as ConsoleKernel;
use Throwable;

final class Kernel extends ConsoleKernel
{
    /**
     * The Artisan commands provided by your application.
     *
     * @var array<int, class-string>
     */
    protected array $commands = [];

    /**
     * Report the exception to the exception handler.
     *
     * @throws Throwable
     */
    protected function reportException(Throwable $e): void
    {
        throw $e;
    }

    /**
     * Determine if the kernel should discover commands.
     */
    protected function shouldDiscoverCommands(): bool
    {
        return get_class($this) === __CLASS__;
    }
}
