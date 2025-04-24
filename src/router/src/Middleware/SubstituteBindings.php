<?php

declare(strict_types=1);

namespace Hypervel\Router\Middleware;

use BackedEnum;
use Closure;
use Hyperf\Database\Model\Model;
use Hyperf\Database\Model\ModelNotFoundException;
use Hyperf\Di\ClosureDefinitionCollectorInterface;
use Hyperf\Di\MethodDefinitionCollectorInterface;
use Hyperf\Di\ReflectionType;
use Hyperf\HttpServer\Router\Dispatched;
use Hypervel\Router\Contracts\UrlRoutable;
use Hypervel\Router\Exceptions\BackedEnumCaseNotFoundException;
use Hypervel\Router\Exceptions\UrlRoutableNotFoundException;
use Hypervel\Router\Router;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

use function Hyperf\Support\make;

class SubstituteBindings implements MiddlewareInterface
{
    /**
     * All of the resolved definitions by dispatched routes.
     */
    protected array $resolvedDefinitions = [];

    public function __construct(
        protected ContainerInterface $container,
        protected Router $router
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        /** @var Dispatched */
        $dispatched = $request->getAttribute(Dispatched::class);

        if (! $dispatched->isFound()) {
            return $handler->handle($request);
        }

        if (! $params = $dispatched->params) {
            return $handler->handle($request);
        }

        $definitions = $this->getDefinitions($dispatched->handler->callback);
        $dispatched->params = $this->substituteBindings($definitions, $params);

        return $handler->handle($request);
    }

    /**
     * @return ReflectionType[]
     */
    protected function getDefinitions(array|Closure|string $callback): array
    {
        if ($callback instanceof Closure) {
            return $this->getClosureDefinitions($callback);
        }

        if (is_string($callback)) {
            $callback = explode('@', $callback);
        }

        return $this->getMethodDefinitions($callback);
    }

    /**
     * @return ReflectionType[]
     */
    protected function getClosureDefinitions(Closure $callback): array
    {
        $signature = spl_object_hash($callback);
        if ($definitions = $this->resolvedDefinitions[$signature] ?? null) {
            return $definitions;
        }

        return $this->resolvedDefinitions[$signature] = $this->container->has(ClosureDefinitionCollectorInterface::class)
            ? $this->container->get(ClosureDefinitionCollectorInterface::class)->getParameters($callback)
            : [];
    }

    /**
     * @return ReflectionType[]
     */
    protected function getMethodDefinitions(array $callback): array
    {
        $controller = $callback[0];
        $action = $callback[1];

        $signature = "{$controller}::{$action}";
        if ($definitions = $this->resolvedDefinitions[$signature] ?? null) {
            return $definitions;
        }

        return $this->resolvedDefinitions[$signature] = $this->container->get(MethodDefinitionCollectorInterface::class)->getParameters($controller, $action);
    }

    /**
     * @param ReflectionType[] $definitions
     */
    protected function substituteBindings(array $definitions, array $params): array
    {
        foreach ($definitions as $definition) {
            $name = $definition->getMeta('name');

            if (! array_key_exists($name, $params)) {
                continue;
            }
            if ($binding = $this->router->getExplicitBinding($name)) {
                $params[$name] = $binding($params[$name]);
                continue;
            }
            if ($binding = $this->resolveBinding($definition, $params, $name)) {
                $params[$name] = $binding;
            }
        }

        return $params;
    }

    /**
     * @throws ModelNotFoundException
     * @throws BackedEnumCaseNotFoundException
     */
    protected function resolveBinding(ReflectionType $definition, array $params, string $name): mixed
    {
        $class = $definition->getName();

        if (is_a($class, UrlRoutable::class, true)) {
            return $this->resolveUrlRoutable($class, $params[$name]);
        }

        if (is_a($class, Model::class, true)) {
            return $this->resolveModel(
                $this->router->getModelBinding($name) ?: $class,
                $params[$name]
            );
        }

        if (is_a($class, BackedEnum::class, true)) {
            return $this->resolveBackedEnum($class, $params[$name]);
        }

        return null;
    }

    /**
     * @param class-string<UrlRoutable> $class
     * @throws ModelNotFoundException
     */
    protected function resolveUrlRoutable(string $class, string $routeKey): UrlRoutable
    {
        $urlRoutable = make($class)->resolveRouteBinding($routeKey);

        if (is_null($urlRoutable)) {
            throw new UrlRoutableNotFoundException($class, $routeKey);
        }

        /* @phpstan-ignore-next-line */
        return $urlRoutable;
    }

    /**
     * @param class-string<Model> $class
     * @throws ModelNotFoundException
     */
    protected function resolveModel(string $class, string $routeKey): Model
    {
        /* @phpstan-ignore-next-line */
        return $class::where(make($class)->getRouteKeyName(), $routeKey)->firstOrFail();
    }

    /**
     * @param class-string<BackedEnum> $class
     * @throws BackedEnumCaseNotFoundException
     */
    protected function resolveBackedEnum(string $class, string $value): BackedEnum
    {
        $enum = $class::tryFrom((string) $value);

        if (is_null($enum)) {
            throw new BackedEnumCaseNotFoundException($class, $value);
        }

        return $enum;
    }
}
