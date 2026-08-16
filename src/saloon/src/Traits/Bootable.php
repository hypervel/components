<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Traits;

use Hypervel\Saloon\Http\PendingRequest;

trait Bootable
{
    /**
     * Configure a pending request for this resource.
     */
    public function boot(PendingRequest $pendingRequest): void
    {
    }
}
