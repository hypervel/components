<?php

declare(strict_types=1);

namespace Hypervel\Grpc\Contracts;

use Hypervel\Grpc\GrpcOperation;
use Hypervel\Grpc\GrpcOperationResult;

interface GrpcOperationObserver
{
    /**
     * Start observing a logical gRPC operation.
     */
    public function starting(GrpcOperation $operation): mixed;

    /**
     * Finish observing a logical gRPC operation.
     */
    public function finished(
        GrpcOperation $operation,
        mixed $token,
        GrpcOperationResult $result,
    ): void;
}
