<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Exceptions\Request;

use Hypervel\Saloon\Exceptions\SaloonException;
use Hypervel\Saloon\Http\PendingRequest;
use Throwable;

/**
 * Report a transport failure that occurred before an API returned a response.
 */
class FatalRequestException extends SaloonException
{
    /**
     * Create a fatal request exception.
     */
    public function __construct(
        Throwable $originalException,
        protected readonly PendingRequest $pendingRequest,
    ) {
        parent::__construct($originalException->getMessage(), $originalException->getCode(), $originalException);
    }

    /**
     * Get the pending request that caused the exception.
     */
    public function pendingRequest(): PendingRequest
    {
        return $this->pendingRequest;
    }
}
