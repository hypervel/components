<?php

declare(strict_types=1);

namespace Hypervel\Http;

use FastRoute\Dispatcher;
use Hyperf\Codec\Json;
use Hyperf\Context\RequestContext;
use Hyperf\Contract\Arrayable;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Contract\Jsonable;
use Hyperf\HttpMessage\Server\ResponsePlusProxy;
use Hyperf\HttpMessage\Stream\SwooleStream;
use Hyperf\HttpServer\Contract\CoreMiddlewareInterface;
use Hyperf\HttpServer\Router\Dispatched;
use Hyperf\HttpServer\Router\DispatcherFactory;
use Hyperf\Server\Exception\ServerException;
use Hyperf\View\RenderInterface;
use Hyperf\ViewEngine\Contract\Renderable;
use Hyperf\ViewEngine\Contract\ViewInterface;
use Hypervel\Context\ResponseContext;
use Hypervel\HttpMessage\Exceptions\MethodNotAllowedHttpException;
use Hypervel\HttpMessage\Exceptions\NotFoundHttpException;
use Hypervel\HttpMessage\Exceptions\ServerErrorHttpException;
use Hypervel\View\Events\ViewRendered;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Swow\Psr7\Message\ResponsePlusInterface;

class CoreMiddleware implements CoreMiddlewareInterface
{
    protected Dispatcher $dispatcher;

    protected RouteDependency $routeDependency;

    public function __construct(
        protected ContainerInterface $container,
        protected string $serverName
    ) {
        $this->dispatcher = $this->createDispatcher($serverName);
        $this->routeDependency = $container->get(RouteDependency::class);
    }

    /**
     * Transfer the non-standard response content to a standard response object.
     *
     * @param null|array|Arrayable|Jsonable|Renderable|ResponseInterface|string|ViewInterface $response
     */
    protected function transferToResponse($response, ServerRequestInterface $request): ResponsePlusInterface
    {
        if ($response instanceof Renderable) {
            if ($response instanceof ViewInterface) {
                if ($this->container->get(ConfigInterface::class)->get('view.event.enable', false)) {
                    $this->container->get(EventDispatcherInterface::class)
                        ->dispatch(new ViewRendered($response));
                }
            }

            return $this->response()
                ->setHeader('Content-Type', $this->container->get(RenderInterface::class)->getContentType())
                ->setBody(new SwooleStream($response->render()));
        }

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
                ->setBody(new SwooleStream((string) $response));
        }

        if ($this->response()->hasHeader('content-type')) {
            return $this->response()->setBody(new SwooleStream((string) $response));
        }

        return $this->response()
            ->addHeader('content-type', 'text/plain')
            ->setBody(new SwooleStream((string) $response));
    }

    /**
     * Get response instance from context.
     */
    protected function response(): ResponsePlusInterface
    {
        return ResponseContext::get();
    }

    protected function createDispatcher(string $serverName): Dispatcher
    {
        return $this->container->get(DispatcherFactory::class)
            ->getDispatcher($serverName);
    }

    public function dispatch(ServerRequestInterface $request): ServerRequestInterface
    {
        $route = new DispatchedRoute(
            $this->dispatcher->dispatch($request->getMethod(), $request->getUri()->getPath()),
            $this->serverName
        );

        return RequestContext::set($request)
            ->setAttribute(Dispatched::class, $route);
    }

    /**
     * Handle the response when found.
     *
     * @return array|Arrayable|mixed|ResponseInterface|string
     */
    protected function handleFound(DispatchedRoute $route, ServerRequestInterface $request): mixed
    {
        if ($route->isClosure()) {
            if ($parameters = $this->routeDependency->getClosureParameters($route->getCallback(), $route->parameters())) {
                $this->routeDependency->fireAfterResolvingCallbacks($parameters, $route);
            }

            return ($route->getCallback())(...$parameters);
        }

        [$controller, $action] = $route->getControllerCallback();
        $controllerInstance = $this->container->get($controller);
        if (! method_exists($controllerInstance, $action)) {
            throw new ServerErrorHttpException("{$controller}@{$action} does not exist.");
        }

        if ($parameters = $this->routeDependency->getMethodParameters($controller, $action, $route->parameters())) {
            $this->routeDependency->fireAfterResolvingCallbacks($parameters, $route);
        }

        if (method_exists($controllerInstance, 'callAction')) {
            return $controllerInstance->callAction($action, $parameters);
        }

        return $controllerInstance->{$action}(...$parameters);
    }

    /**
     * Process an incoming server request and return a response, optionally delegating
     * response creation to a handler.
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $request = RequestContext::set($request);

        $dispatched = $request->getAttribute(Dispatched::class);

        if (! $dispatched instanceof DispatchedRoute) {
            throw new ServerException(sprintf('The dispatched object is not a %s object.', DispatchedRoute::class));
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
        $allowedMethods = implode(', ', $methods);
        if ($request->getMethod() === 'OPTIONS') {
            return $this->response()
                ->withHeader('Allow', $allowedMethods);
        }

        throw new MethodNotAllowedHttpException("Allow: {$allowedMethods}");
    }
}
