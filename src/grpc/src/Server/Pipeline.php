<?php

declare(strict_types=1);

namespace Hypervel\Grpc\Server;

use Hypervel\Pipeline\Pipeline as BasePipeline;
use Throwable;

/**
 * Map failures at the closest gRPC middleware boundary.
 *
 * @internal
 */
class Pipeline extends BasePipeline
{
    /**
     * Map the given exception to an RPC failure value.
     */
    protected function handleException(mixed $passable, Throwable $e): mixed
    {
        return $this->getContainer()->make(ExceptionMapper::class)->map($e);
    }
}
