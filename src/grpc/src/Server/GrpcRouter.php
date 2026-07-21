<?php

declare(strict_types=1);

namespace Hypervel\Grpc\Server;

use Closure;
use Google\Protobuf\Internal\Message;
use Hypervel\Grpc\Server\Middleware\HandleCall;
use Hypervel\Http\Request;
use Hypervel\Pipeline\Pipeline as BasePipeline;
use Hypervel\Routing\Route;
use Hypervel\Routing\Router;
use Hypervel\Support\Str;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionException;
use ReflectionIntersectionType;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionType;
use ReflectionUnionType;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Isolate gRPC routes while retaining Hypervel's normal dispatch machinery.
 *
 * @internal
 */
class GrpcRouter extends Router
{
    /**
     * Synchronize application route middleware before worker warmup.
     *
     * @internal
     */
    public function syncMiddlewareFrom(Router $router): void
    {
        foreach ($router->getMiddleware() as $name => $middleware) {
            $this->aliasMiddleware($name, $middleware);
        }

        foreach ($router->getMiddlewareGroups() as $name => $middleware) {
            $this->middlewareGroup($name, $middleware);
        }

        $this->middlewarePriority = $router->middlewarePriority;
    }

    /**
     * Validate gRPC route actions before compiling and warming the collection.
     */
    public function compileAndWarm(): void
    {
        foreach ($this->getRoutes()->getRoutes() as $route) {
            $action = $this->describeAction($route->getAction('uses'));
            $parameters = $this->signatureParameters($route, $action);
            $requestParameters = [];
            $contextParameters = [];

            foreach ($parameters as $parameter) {
                $types = $this->parameterClassNames($parameter);

                if (in_array(ServerCallContext::class, $types, true)) {
                    $this->validateSpecialParameter($parameter, $action, 'call context');
                    $contextParameters[] = $parameter;
                }

                foreach ($types as $type) {
                    if ($type === Message::class || is_subclass_of($type, Message::class)) {
                        $this->validateSpecialParameter($parameter, $action, 'protobuf request');
                        $requestParameters[] = [$parameter, $type];

                        break;
                    }
                }
            }

            if (count($requestParameters) !== 1) {
                throw new InvalidArgumentException(
                    "The gRPC route action [{$action}] must declare exactly one protobuf request parameter."
                );
            }

            if (count($contextParameters) > 1) {
                throw new InvalidArgumentException(
                    "The gRPC route action [{$action}] may declare at most one call context parameter."
                );
            }

            [$requestParameter, $requestClass] = $requestParameters[0];
            $reflection = new ReflectionClass($requestClass);

            if ($requestClass === Message::class || ! $reflection->isInstantiable()) {
                throw new InvalidArgumentException(
                    "The gRPC route action [{$action}] request parameter [\${$requestParameter->getName()}] must name a concrete protobuf message class."
                );
            }

            $grpc = $route->getAction('_grpc');

            if (! is_array($grpc)) {
                throw new InvalidArgumentException("The gRPC route action [{$action}] is missing its protocol marker.");
            }

            $route->setAction([
                ...$route->getAction(),
                '_grpc' => [
                    ...$grpc,
                    'request_parameter' => $requestParameter->getName(),
                    'request_class' => $requestClass,
                ],
            ]);
        }

        parent::compileAndWarm();
    }

    /**
     * Convert a service result into a protocol-owned gRPC response.
     */
    public function prepareResponse(Request $request, mixed $response): SymfonyResponse
    {
        if ($response instanceof GrpcHttpResponse || $response instanceof GrpcStreamedResponse) {
            return $response;
        }

        return $this->container->make(ResponseFactory::class)->make($request, $response);
    }

    /**
     * Resolve a valid route action's signature parameters.
     */
    private function signatureParameters(Route $route, string $action): array
    {
        $uses = $route->getAction('uses');

        if (is_string($uses)) {
            [$class, $method] = Str::parseCallback($uses);

            if (! class_exists($class)) {
                throw new InvalidArgumentException("The gRPC route action class [{$class}] does not exist.");
            }

            if ($method === null || ! method_exists($class, $method)) {
                throw new InvalidArgumentException("The gRPC route action method [{$action}] does not exist.");
            }

            if (! (new ReflectionMethod($class, $method))->isPublic()) {
                throw new InvalidArgumentException("The gRPC route action method [{$action}] must be public.");
            }
        } elseif (! is_callable($uses)) {
            throw new InvalidArgumentException("The gRPC route action [{$action}] is not callable.");
        }

        try {
            return $route->signatureParameters();
        } catch (ReflectionException $exception) {
            throw new InvalidArgumentException(
                "Unable to inspect the gRPC route action [{$action}].",
                previous: $exception,
            );
        }
    }

    /**
     * Validate one protocol-provided action parameter.
     */
    private function validateSpecialParameter(
        ReflectionParameter $parameter,
        string $action,
        string $description,
    ): void {
        if (! $parameter->getType() instanceof ReflectionNamedType
            || $parameter->allowsNull()
            || $parameter->isVariadic()
            || $parameter->isPassedByReference()) {
            throw new InvalidArgumentException(
                "The gRPC route action [{$action}] {$description} parameter [\${$parameter->getName()}] must have one non-nullable named type and be passed by value."
            );
        }
    }

    /**
     * Resolve every class name represented by a reflected parameter type.
     *
     * @return list<class-string>
     */
    private function parameterClassNames(ReflectionParameter $parameter): array
    {
        $type = $parameter->getType();

        if ($type === null) {
            return [];
        }

        $types = $type instanceof ReflectionUnionType || $type instanceof ReflectionIntersectionType
            ? $type->getTypes()
            : [$type];

        return array_values(array_filter(array_map(
            fn (ReflectionType $type): ?string => $this->parameterClassName($parameter, $type),
            $types,
        )));
    }

    /**
     * Resolve one reflected parameter class name.
     *
     * @return null|class-string
     */
    private function parameterClassName(ReflectionParameter $parameter, ReflectionType $type): ?string
    {
        if (! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
            return null;
        }

        $name = $type->getName();
        $declaringClass = $parameter->getDeclaringClass();

        if ($name === 'self' && $declaringClass !== null) {
            return $declaringClass->getName();
        }

        if ($name === 'parent'
            && $declaringClass !== null
            && ($parentClass = $declaringClass->getParentClass()) !== false) {
            return $parentClass->getName();
        }

        return $name;
    }

    /**
     * Describe a route action in configuration errors.
     */
    private function describeAction(mixed $action): string
    {
        return match (true) {
            is_string($action) => $action,
            $action instanceof Closure => 'Closure',
            is_object($action) => $action::class . '::__invoke',
            default => get_debug_type($action),
        };
    }

    /**
     * Create the middleware pipeline used to dispatch a gRPC route.
     */
    protected function newPipeline(): BasePipeline
    {
        return new Pipeline($this->container);
    }

    /**
     * Resolve user middleware while retaining mandatory protocol handling.
     */
    protected function middlewareFor(Route $route): array
    {
        return [HandleCall::class, ...parent::middlewareFor($route)];
    }

    /**
     * Determine whether this router owns the application's global route state.
     */
    protected function ownsGlobalRouteState(): bool
    {
        return false;
    }
}
