<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Fixtures\Exceptions;

use Exception;
use Hypervel\Saloon\Http\PendingRequest;

class TestResponseException extends Exception
{
    /**
     * The pending request.
     */
    protected PendingRequest $pendingRequest;

    /**
     * Create a test response exception.
     */
    public function __construct(string $message, PendingRequest $pendingRequest)
    {
        $this->pendingRequest = $pendingRequest;

        parent::__construct($message);
    }

    /**
     * Get the pending request.
     */
    public function getPendingRequest(): PendingRequest
    {
        return $this->pendingRequest;
    }
}
