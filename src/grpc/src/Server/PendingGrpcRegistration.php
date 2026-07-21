<?php

declare(strict_types=1);

namespace Hypervel\Grpc\Server;

use Closure;
use Hypervel\Routing\Route;
use Hypervel\Support\Arr;

readonly class PendingGrpcRegistration
{
    /**
     * @internal
     */
    public function __construct(
        private GrpcRouteRegistrar $registrar,
        private GrpcRouter $router,
        private array $attributes = [],
    ) {
    }

    /**
     * Append middleware to the registration.
     */
    public function middleware(array|string $middleware): self
    {
        return $this->withAttributeValues('middleware', $middleware);
    }

    /**
     * Append middleware exclusions to the registration.
     */
    public function withoutMiddleware(array|string $middleware): self
    {
        return $this->withAttributeValues('excluded_middleware', $middleware);
    }

    /**
     * Append a route-name prefix to the registration.
     */
    public function name(string $name): self
    {
        return new self($this->registrar, $this->router, [
            ...$this->attributes,
            'as' => ($this->attributes['as'] ?? '') . $name,
        ]);
    }

    /**
     * Register a unary RPC.
     */
    public function unary(string $method, array|string|callable $action): Route
    {
        return $this->withAttributes(
            fn (): Route => $this->registrar->unary($method, $action),
        );
    }

    /**
     * Register a server-streaming RPC.
     */
    public function serverStream(string $method, array|string|callable $action): Route
    {
        return $this->withAttributes(
            fn (): Route => $this->registrar->serverStream($method, $action),
        );
    }

    /**
     * Register routes within a fully qualified gRPC service.
     */
    public function service(string $service, Closure $routes): void
    {
        $this->withAttributes(
            function () use ($service, $routes): void {
                $this->registrar->service($service, $routes);
            },
        );
    }

    /**
     * Append route-group attribute values.
     */
    private function withAttributeValues(string $key, array|string $values): self
    {
        $values = array_values(array_filter(array_map(
            static fn (mixed $value): string => (string) $value,
            Arr::wrap($values),
        )));

        return new self($this->registrar, $this->router, [
            ...$this->attributes,
            $key => array_merge($this->attributes[$key] ?? [], $values),
        ]);
    }

    /**
     * Run a registration within ordinary route-group attributes.
     *
     * @template TResult
     *
     * @param Closure(): TResult $routes
     * @return TResult
     */
    private function withAttributes(Closure $routes): mixed
    {
        $result = null;

        $this->router->group($this->attributes, function () use ($routes, &$result): void {
            $result = $routes();
        });

        return $result;
    }
}
