<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Traits\Plugins;

use Hypervel\Saloon\Http\PendingRequest;

trait AcceptsJson
{
    /**
     * Apply the JSON response preference.
     */
    public function bootAcceptsJson(PendingRequest $pendingRequest): void
    {
        if (! $pendingRequest->hasHeader('Accept')) {
            $pendingRequest->acceptJson();
        }
    }
}
