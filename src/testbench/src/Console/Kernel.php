<?php

declare(strict_types=1);

namespace Hypervel\Testbench\Console;

use Hypervel\Foundation\Console\Kernel as ConsoleKernel;

/**
 * Testbench console kernel with empty bootstrappers.
 *
 * The testbench test lifecycle calls bootstrappers manually (via
 * resolveApplicationBootstrappers) so it can insert defineEnvironment()
 * between RegisterProviders and BootProviders. This kernel returns an
 * empty bootstrapper list so that ConsoleKernel::bootstrap() — called
 * at the end of the lifecycle — sets hasBeenBootstrapped without
 * re-running any bootstrappers.
 */
class Kernel extends ConsoleKernel
{
    /**
     * Get the bootstrap classes for the application.
     *
     * @return array<int, class-string>
     */
    protected function bootstrappers(): array
    {
        return [];
    }
}
