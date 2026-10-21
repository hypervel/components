<?php

declare(strict_types=1);

namespace Hypervel\Tests\Head\Fixtures;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Contracts\Http\Kernel;

/**
 * Resolve the HTTP kernel before service providers are registered to exercise
 * integrations installed after the kernel has already been constructed.
 */
trait ResolvesHttpKernelEarly
{
    /**
     * Resolve the HTTP kernel before package providers are registered.
     */
    protected function resolveApplicationHttpKernel(Application $app): void
    {
        parent::resolveApplicationHttpKernel($app);

        $app->make(Kernel::class);
    }
}
