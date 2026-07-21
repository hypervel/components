<?php

declare(strict_types=1);

namespace Hypervel\Grpc\Exceptions;

use Throwable;

class ConnectionException extends GrpcException
{
    public function __construct(
        private readonly string $target,
        string $message,
        private readonly ?int $transportCode = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $transportCode ?? 0, $previous);
    }

    /**
     * Return the connection target.
     */
    public function target(): string
    {
        return $this->target;
    }

    /**
     * Return the transport error code.
     */
    public function transportCode(): ?int
    {
        return $this->transportCode;
    }
}
