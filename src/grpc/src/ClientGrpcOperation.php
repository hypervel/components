<?php

declare(strict_types=1);

namespace Hypervel\Grpc;

use Hypervel\Grpc\Protocol\ServiceMethod;

class ClientGrpcOperation implements GrpcOperation
{
    /**
     * Create an outbound gRPC operation description.
     */
    public function __construct(
        private readonly ServiceMethod $serviceMethod,
        public readonly string $serverAddress,
        public readonly int $serverPort,
        private Metadata $metadata,
    ) {
    }

    /**
     * Return the recognized service method.
     */
    public function serviceMethod(): ServiceMethod
    {
        return $this->serviceMethod;
    }

    /**
     * Return the current outbound metadata.
     */
    public function metadata(): Metadata
    {
        return $this->metadata;
    }

    /**
     * Replace the outbound metadata for subsequent observers and transport encoding.
     */
    public function withMetadata(Metadata $metadata): static
    {
        $this->metadata = $metadata;

        return $this;
    }
}
