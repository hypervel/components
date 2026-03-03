<?php

declare(strict_types=1);

namespace Hypervel\Testbench\Http;

use Hypervel\Foundation\Http\Kernel as HttpKernel;

/**
 * Minimal HTTP Kernel for testbench.
 *
 * Bootstrappers are empty because testbench handles application
 * bootstrapping separately via its own setUp flow.
 */
class Kernel extends HttpKernel
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
