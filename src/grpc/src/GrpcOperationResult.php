<?php

declare(strict_types=1);

namespace Hypervel\Grpc;

use Throwable;

final readonly class GrpcOperationResult
{
    /**
     * Create a logical gRPC operation result.
     */
    public function __construct(
        public ?Status $status,
        public ?Throwable $exception,
        public int $attemptCount,
    ) {
    }
}
