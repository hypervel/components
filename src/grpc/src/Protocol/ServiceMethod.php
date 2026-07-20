<?php

declare(strict_types=1);

namespace Hypervel\Grpc\Protocol;

use InvalidArgumentException;

/**
 * @internal
 */
final readonly class ServiceMethod
{
    private function __construct(
        public string $service,
        public string $method,
    ) {
    }

    /**
     * Parse a fully qualified gRPC method.
     */
    public static function parse(string $value): self
    {
        $method = str_starts_with($value, '/') ? substr($value, 1) : $value;
        $segments = explode('/', $method);

        if (count($segments) !== 2) {
            throw new InvalidArgumentException(
                'A gRPC method must contain exactly one service and method separator.',
            );
        }

        return self::from($segments[0], $segments[1]);
    }

    /**
     * Create a gRPC method from its service and method names.
     */
    public static function from(string $service, string $method): self
    {
        self::validateServiceName($service);

        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $method)) {
            throw new InvalidArgumentException('The gRPC method name is invalid.');
        }

        return new self($service, $method);
    }

    /**
     * Validate a fully qualified gRPC service name.
     */
    public static function validateServiceName(string $service): void
    {
        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)*$/D', $service)) {
            throw new InvalidArgumentException('The gRPC service name is invalid.');
        }
    }

    /**
     * Return the canonical gRPC method path.
     */
    public function path(): string
    {
        return "/{$this->service}/{$this->method}";
    }
}
