<?php

declare(strict_types=1);

namespace Hypervel\Grpc\Server;

use Closure;
use Hypervel\Grpc\Protocol\ServiceMethod;
use Hypervel\Routing\Route;
use LogicException;

class GrpcRouteRegistrar
{
    private ?string $service = null;

    public function __construct(private readonly GrpcRouter $router)
    {
    }

    /**
     * Register a unary RPC.
     */
    public function unary(string $method, array|string|callable $action): Route
    {
        return $this->register($method, $action, serverStreaming: false);
    }

    /**
     * Register a server-streaming RPC.
     */
    public function serverStream(string $method, array|string|callable $action): Route
    {
        return $this->register($method, $action, serverStreaming: true);
    }

    /**
     * Register routes within a fully qualified gRPC service.
     */
    public function service(string $service, Closure $routes): void
    {
        if ($this->service !== null) {
            throw new LogicException('Nested gRPC service groups are not supported.');
        }

        ServiceMethod::validateServiceName($service);

        $previousService = $this->service;
        $this->service = $service;

        try {
            $routes();
        } finally {
            $this->service = $previousService;
        }
    }

    /**
     * Begin a registration with shared middleware.
     */
    public function middleware(array|string $middleware): PendingGrpcRegistration
    {
        return (new PendingGrpcRegistration($this, $this->router))->middleware($middleware);
    }

    /**
     * Begin a registration with excluded middleware.
     */
    public function withoutMiddleware(array|string $middleware): PendingGrpcRegistration
    {
        return (new PendingGrpcRegistration($this, $this->router))->withoutMiddleware($middleware);
    }

    /**
     * Begin a registration with a route-name prefix.
     */
    public function name(string $name): PendingGrpcRegistration
    {
        return (new PendingGrpcRegistration($this, $this->router))->name($name);
    }

    /**
     * Register one validated RPC route.
     */
    private function register(
        string $method,
        array|string|callable $action,
        bool $serverStreaming,
    ): Route {
        $serviceMethod = $this->service === null
            ? ServiceMethod::parse($method)
            : ServiceMethod::from($this->service, $method);

        $route = $this->router->addRoute('POST', $serviceMethod->path(), $action);
        $route->setAction([
            ...$route->getAction(),
            '_grpc' => [
                'service' => $serviceMethod->service,
                'method' => $serviceMethod->method,
                'server_streaming' => $serverStreaming,
            ],
        ]);

        return $route;
    }
}
