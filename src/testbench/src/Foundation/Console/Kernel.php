<?php

declare(strict_types=1);

namespace Hypervel\Testbench\Foundation\Console;

use Hypervel\Foundation\Console\Kernel as ConsoleKernel;
use Symfony\Component\Console\Input\InputInterface;

/**
 * Base testbench console kernel with empty bootstrappers.
 *
 * The testbench test lifecycle calls bootstrappers manually (via
 * resolveApplicationBootstrappers) so it can insert defineEnvironment()
 * between RegisterProviders and BootProviders. This kernel returns an
 * empty bootstrapper list so that ConsoleKernel::bootstrap() — called
 * at the end of the lifecycle — sets hasBeenBootstrapped without
 * re-running any bootstrappers.
 */
abstract class Kernel extends ConsoleKernel
{
    /**
     * Terminate the application.
     */
    public function terminate(InputInterface $input, int $status): void
    {
        parent::terminate($input, $status);

        TerminatingConsole::handle();
    }

    /**
     * Get the bootstrap classes for the application.
     *
     * @return array<int, class-string>
     */
    protected function bootstrappers(): array
    {
        return [];
    }

    /**
     * Determine if the kernel should discover commands.
     */
    protected function shouldDiscoverCommands(): bool
    {
        return get_class($this) === __CLASS__;
    }
}
