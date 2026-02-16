<?php

declare(strict_types=1);

namespace Hypervel\HttpServer;

use Closure;
use FastRoute\Dispatcher;
use Hypervel\Support\Json;
use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Contracts\Support\Jsonable;
use Hyperf\Contract\NormalizerInterface;
use Hyperf\Di\ClosureDefinitionCollectorInterface;
use Hyperf\Di\MethodDefinitionCollectorInterface;
use Hyperf\Di\ReflectionType;
use Hypervel\Context\RequestContext;
use Hypervel\Context\ResponseContext;
use Hypervel\Contracts\Http\ResponsePlusInterface;
use Hypervel\HttpMessage\Exceptions\MethodNotAllowedHttpException;
use Hypervel\HttpMessage\Exceptions\NotFoundHttpException;
use Hypervel\HttpMessage\Exceptions\ServerErrorHttpException;
use Hypervel\HttpMessage\Server\ResponsePlusProxy;
use Hypervel\HttpMessage\Stream\SwooleStream;
use Hypervel\HttpServer\Contracts\CoreMiddlewareInterface;
use Hypervel\HttpServer\Router\Dispatched;
use Hypervel\HttpServer\Router\DispatcherFactory;
use Hypervel\Server\Exceptions\ServerException;
use InvalidArgumentException;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;

/**
 * Core middleware, main responsibility is to handle route info
 * and then delegate to the specified handler (controller) to handle the request,
 * generate a response object and delegate to the next middleware.
 */
class CoreMiddleware implements CoreMiddlewareInterface
{
    protected Dispatcher $dispatcher;

    private MethodDefinitionCollectorInterface $methodDefinitionCollector;

    private ?ClosureDefinitionCollectorInterface $closureDefinitionCollector = null;

    private NormalizerInterface $normalizer;

    public function __construct(protected ContainerInterface $container, private string $serverName)
    {
        $this->dispatcher = $this->createDispatcher($serverName);
        $this->normalizer = $this->container->make(NormalizerInterface::class);
        $this->methodDefinitionCollector = $this->container->make(MethodDefinitionCollectorInterface::class);
        if ($this->container->has(ClosureDefinitionCollectorInterface::class)) {
            $this->closureDefinitionCollector = $this->container->make(ClosureDefinitionCollectorInterface::class);
        }
    }

    public function dispatch(ServerRequestInterface $request): ServerRequestInterface
    {
        $routes = $this->dispatcher->dispatch($request->getMethod(), $request->getUri()->getPath());

        $dispatched = new Dispatched($routes, $this->serverName);

        return RequestContext::set($request)->setAttribute(Dispatched::class, $dispatched);
    }

    /**
     * Process an incoming server request and return a response, optionally delegating
     * response creation to a handler.
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $request = RequestContext::set($request);

        $dispatched = $request->getAttribute(Dispatched::class);

        if (! $dispatched instanceof Dispatched) {
            throw new ServerException(sprintf('The dispatched object is not a %s object.', Dispatched::class));
        }

        $response = match ($dispatched->status) {
            Dispatcher::NOT_FOUND => $this->handleNotFound($request),
            Dispatcher::METHOD_NOT_ALLOWED => $this->handleMethodNotAllowed($dispatched->params, $request),
            Dispatcher::FOUND => $this->handleFound($dispatched, $request),
            default => null,
        };

        if (! $response instanceof ResponsePlusInterface) {
            $response = $this->transferToResponse($response, $request);
        }

        return $response->addHeader('Server', 'Hypervel');
    }

    public function getMethodDefinitionCollector(): MethodDefinitionCollectorInterface
    {
        return $this->methodDefinitionCollector;
    }

    public function getClosureDefinitionCollector(): ClosureDefinitionCollectorInterface
    {
        return $this->closureDefinitionCollector;
    }

    public function getNormalizer(): NormalizerInterface
    {
        return $this->normalizer;
    }

    protected function createDispatcher(string $serverName): Dispatcher
    {
        $factory = $this->container->make(DispatcherFactory::class);
        return $factory->getDispatcher($serverName);
    }

    /**
     * Handle the response when found.
     *
     * @return array|Arrayable|mixed|ResponseInterface|string
     */
    protected function handleFound(Dispatched $dispatched, ServerRequestInterface $request): mixed
    {
        if ($dispatched->handler->callback instanceof Closure) {
            $parameters = $this->parseClosureParameters($dispatched->handler->callback, $dispatched->params);
            $callback = $dispatched->handler->callback;
            $response = $callback(...$parameters);
        } else {
            [$controller, $action] = $this->prepareHandler($dispatched->handler->callback);
            $controllerInstance = $this->container->make($controller);
            if (! method_exists($controllerInstance, $action)) {
                // Route found, but the handler does not exist.
                throw new ServerErrorHttpException('Method of class does not exist.');
            }
            $parameters = $this->parseMethodParameters($controller, $action, $dispatched->params);
            $response = $controllerInstance->{$action}(...$parameters);
        }
        return $response;
    }

    /**
     * Handle the response when cannot found any routes.
     */
    protected function handleNotFound(ServerRequestInterface $request): mixed
    {
        throw new NotFoundHttpException();
    }

    /**
     * Handle the response when the routes found but doesn't match any available methods.
     */
    protected function handleMethodNotAllowed(array $methods, ServerRequestInterface $request): mixed
    {
        throw new MethodNotAllowedHttpException('Allow: ' . implode(', ', $methods));
    }

    protected function prepareHandler(array|string $handler): array
    {
        if (is_string($handler)) {
            if (str_contains($handler, '@')) {
                return explode('@', $handler);
            }
            if (str_contains($handler, '::')) {
                return explode('::', $handler);
            }
            return [$handler, '__invoke'];
        }
        if (isset($handler[0], $handler[1])) {
            return $handler;
        }
        throw new RuntimeException('Handler not exist.');
    }

    /**
     * Transfer the non-standard response content to a standard response object.
     *
     * @param null|array|Arrayable|Jsonable|ResponseInterface|string $response
     */
    protected function transferToResponse($response, ServerRequestInterface $request): ResponsePlusInterface
    {
        if (is_string($response)) {
            return $this->response()->addHeader('content-type', 'text/plain')->setBody(new SwooleStream($response));
        }

        if ($response instanceof ResponseInterface) {
            return new ResponsePlusProxy($response);
        }

        if (is_array($response) || $response instanceof Arrayable) {
            return $this->response()
                ->addHeader('content-type', 'application/json')
                ->setBody(new SwooleStream(Json::encode($response)));
        }

        if ($response instanceof Jsonable) {
            return $this->response()
                ->addHeader('content-type', 'application/json')
                ->setBody(new SwooleStream($response->toJson()));
        }

        if ($this->response()->hasHeader('content-type')) {
            return $this->response()->setBody(new SwooleStream((string) $response));
        }

        return $this->response()->addHeader('content-type', 'text/plain')->setBody(new SwooleStream((string) $response));
    }

    /**
     * Get response instance from context.
     */
    protected function response(): ResponsePlusInterface
    {
        return ResponseContext::get();
    }

    /**
     * Parse the parameters of method definitions, and then bind the specified arguments or
     * get the value from DI container, combine to an argument array that should be injected
     * and return the array.
     */
    protected function parseMethodParameters(string $controller, string $action, array $arguments): array
    {
        $definitions = $this->getMethodDefinitionCollector()->getParameters($controller, $action);
        return $this->getInjections($definitions, "{$controller}::{$action}", $arguments);
    }

    /**
     * Parse the parameters of closure definitions, and then bind the specified arguments or
     * get the value from DI container, combine to an argument array that should be injected
     * and return the array.
     */
    protected function parseClosureParameters(Closure $closure, array $arguments): array
    {
        if (! $this->container->has(ClosureDefinitionCollectorInterface::class)) {
            return [];
        }
        $definitions = $this->getClosureDefinitionCollector()->getParameters($closure);
        return $this->getInjections($definitions, 'Closure', $arguments);
    }

    /**
     * @param ReflectionType[] $definitions
     */
    private function getInjections(array $definitions, string $callableName, array $arguments): array
    {
        $injections = [];
        foreach ($definitions as $pos => $definition) {
            $value = $arguments[$pos] ?? $arguments[$definition->getMeta('name')] ?? null;
            if ($value === null) {
                if ($definition->getMeta('defaultValueAvailable')) {
                    $injections[] = $definition->getMeta('defaultValue');
                } elseif ($this->container->has($definition->getName())) {
                    $injections[] = $this->container->make($definition->getName());
                } elseif ($definition->allowsNull()) {
                    $injections[] = null;
                } else {
                    throw new InvalidArgumentException("Parameter '{$definition->getMeta('name')}' "
                        . "of {$callableName} should not be null");
                }
            } else {
                $injections[] = $this->getNormalizer()->denormalize($value, $definition->getName());
            }
        }
        return $injections;
    }
}
