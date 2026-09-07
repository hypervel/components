<?php

declare(strict_types=1);

namespace Hypervel\Grpc;

use Hypervel\Grpc\Protocol\ServiceMethod;

interface GrpcOperation
{
    /**
     * Return the recognized service method, when available.
     */
    public function serviceMethod(): ?ServiceMethod;
}
