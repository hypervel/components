<?php

declare(strict_types=1);

namespace Hypervel\Grpc;

use Hypervel\Grpc\Protocol\ServiceMethod;
use InvalidArgumentException;

readonly class ServerGrpcOperation implements GrpcOperation
{
    private ?ServiceMethod $serviceMethod;

    /**
     * Create an inbound gRPC operation description.
     *
     * @param array<array-key, list<string>|string> $metadata
     */
    public function __construct(
        public string $httpMethod,
        public string $path,
        public array $metadata,
        public string $serverName,
        public string $serverAddress,
        public int $serverPort,
    ) {
        try {
            $serviceMethod = ServiceMethod::parse($path);
            $this->serviceMethod = $serviceMethod->path() === $path ? $serviceMethod : null;
        } catch (InvalidArgumentException) {
            $this->serviceMethod = null;
        }
    }

    /**
     * Return the recognized service method, when available.
     */
    public function serviceMethod(): ?ServiceMethod
    {
        return $this->serviceMethod;
    }
}
