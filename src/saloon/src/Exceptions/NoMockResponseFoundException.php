<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Exceptions;

use Hypervel\Saloon\Http\PendingRequest;

class NoMockResponseFoundException extends SaloonException
{
    /**
     * Create a missing mock response exception.
     */
    public function __construct(PendingRequest $pendingRequest)
    {
        parent::__construct(sprintf('Saloon was unable to guess a mock response for your request [%s], consider using a wildcard url mock or a connector mock.', $pendingRequest->uri()));
    }
}
