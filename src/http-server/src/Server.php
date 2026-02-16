<?php

declare(strict_types=1);

namespace Hypervel\HttpServer;

use FastRoute\Dispatcher;
use Hypervel\Contracts\Config\Repository;
use Hypervel\Context\RequestContext;
use Hypervel\Context\ResponseContext;
use Hypervel\Contracts\Server\MiddlewareInitializerInterface;
use Hypervel\Contracts\Server\OnRequestInterface;
use Hypervel\Coordinator\Constants;
use Hypervel\Coordinator\CoordinatorManager;
use Hypervel\Dispatcher\HttpDispatcher;
use Hypervel\Engine\Http\WritableConnection;
use Hypervel\ExceptionHandler\ExceptionHandlerDispatcher;
use Hypervel\HttpMessage\Server\Request as Psr7Request;
use Hypervel\HttpMessage\Server\Response as Psr7Response;
use Hypervel\HttpServer\Contracts\CoreMiddlewareInterface;
use Hypervel\HttpServer\Events\RequestHandled;
use Hypervel\HttpServer\Events\RequestReceived;
use Hypervel\HttpServer\Events\RequestTerminated;
use Hypervel\HttpServer\Exceptions\Handler\HttpExceptionHandler;
use Hypervel\HttpServer\Router\Dispatched;
use Hypervel\HttpServer\Router\DispatcherFactory;
use Hypervel\Server\Option;
use Hypervel\Server\ServerFactory;
use Hypervel\Support\SafeCaller;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseInterface;
use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response as SwooleResponse;
use Throwable;

use function Hypervel\Coroutine\defer;

class Server implements OnRequestInterface, MiddlewareInitializerInterface
{
    protected array $middlewares = [];

    protected ?CoreMiddlewareInterface $coreMiddleware = null;

    protected array $exceptionHandlers = [];

    protected ?string $serverName = null;

    protected ?EventDispatcherInterface $event = null;

    protected ?Option $option = null;

    public function __construct(
        protected ContainerInterface $container,
        protected HttpDispatcher $dispatcher,
        protected ExceptionHandlerDispatcher $exceptionHandlerDispatcher,
        protected ResponseEmitter $responseEmitter
    ) {
        if ($this->container->has(EventDispatcherInterface::class)) {
            $this->event = $this->container->make(EventDispatcherInterface::class);
        }
    }

    /**
     * Initialize the core middleware, global middlewares, and exception handlers.
     */
    public function initCoreMiddleware(string $serverName): void
    {
        $this->serverName = $serverName;
        $this->coreMiddleware = $this->createCoreMiddleware();

        $config = $this->container->make(Repository::class);
        $this->middlewares = $config->get('middlewares.' . $serverName, []);
        $this->exceptionHandlers = $config->get('exceptions.handler.' . $serverName, $this->getDefaultExceptionHandler());

        $this->initOption();
    }

    /**
     * Handle an incoming HTTP request.
     */
    public function onRequest(SwooleRequest $request, SwooleResponse $response): void
    {
        try {
            CoordinatorManager::until(Constants::WORKER_START)->yield();

            [$psr7Request, $psr7Response] = $this->initRequestAndResponse($request, $response);
            $psr7Request = $this->coreMiddleware->dispatch($psr7Request);

            $this->option?->isEnableRequestLifecycle() && $this->event?->dispatch(new RequestReceived(
                request: $psr7Request,
                response: $psr7Response,
                server: $this->serverName
            ));

            /** @var Dispatched $dispatched */
            $dispatched = $psr7Request->getAttribute(Dispatched::class);
            $middlewares = $this->middlewares;

            $registeredMiddlewares = [];
            if ($dispatched->isFound()) {
                $registeredMiddlewares = MiddlewareManager::get($this->serverName, $dispatched->handler->route, $psr7Request->getMethod());
                $middlewares = array_merge($middlewares, $registeredMiddlewares);
            }

            if ($this->option?->isMustSortMiddlewares() || $registeredMiddlewares) {
                $middlewares = MiddlewareManager::sortMiddlewares($middlewares);
            }

            $psr7Response = $this->dispatcher->dispatch($psr7Request, $middlewares, $this->coreMiddleware);
        } catch (Throwable $throwable) {
            // Delegate the exception to exception handler.
            $psr7Response = $this->container->make(SafeCaller::class)->call(function () use ($throwable) {
                return $this->exceptionHandlerDispatcher->dispatch($throwable, $this->exceptionHandlers);
            }, static function () {
                return (new Psr7Response())->withStatus(400);
            });
        } finally {
            if (isset($psr7Request) && $this->option?->isEnableRequestLifecycle()) {
                defer(fn () => $this->event?->dispatch(new RequestTerminated(
                    request: $psr7Request,
                    response: $psr7Response ?? null,
                    exception: $throwable ?? null,
                    server: $this->serverName
                )));

                $this->event?->dispatch(new RequestHandled(
                    request: $psr7Request,
                    response: $psr7Response ?? null,
                    exception: $throwable ?? null,
                    server: $this->serverName
                ));
            }

            // Send the Response to client.
            if (! isset($psr7Response) || ! $psr7Response instanceof ResponseInterface) {
                return;
            }
            if (isset($psr7Request) && $psr7Request->getMethod() === 'HEAD') {
                $this->responseEmitter->emit($psr7Response, $response, false);
            } else {
                $this->responseEmitter->emit($psr7Response, $response);
            }
        }
    }

    /**
     * Get the server name.
     */
    public function getServerName(): string
    {
        return $this->serverName;
    }

    /**
     * Set the server name.
     *
     * @return $this
     */
    public function setServerName(string $serverName)
    {
        $this->serverName = $serverName;
        return $this;
    }

    /**
     * Initialize the server option from the server config.
     */
    protected function initOption(): void
    {
        $ports = $this->container->make(ServerFactory::class)->getConfig()?->getServers();
        if (! $ports) {
            return;
        }

        foreach ($ports as $port) {
            if ($port->getName() === $this->serverName) {
                $this->option = $port->getOptions();
            }
        }

        $this->option ??= Option::make([]);
        $this->option->setMustSortMiddlewaresByMiddlewares($this->middlewares);
    }

    /**
     * Create the route dispatcher for the given server.
     */
    protected function createDispatcher(string $serverName): Dispatcher
    {
        $factory = $this->container->make(DispatcherFactory::class);
        return $factory->getDispatcher($serverName);
    }

    /**
     * Get the default exception handler classes.
     */
    protected function getDefaultExceptionHandler(): array
    {
        return [
            HttpExceptionHandler::class,
        ];
    }

    /**
     * Create the core middleware instance.
     */
    protected function createCoreMiddleware(): CoreMiddlewareInterface
    {
        return $this->container->make(CoreMiddleware::class, [
            'container' => $this->container,
            'serverName' => $this->serverName,
        ]);
    }

    /**
     * Initialize PSR-7 Request and Response objects from Swoole primitives.
     */
    protected function initRequestAndResponse(SwooleRequest $request, SwooleResponse $response): array
    {
        ResponseContext::set($psr7Response = new Psr7Response());

        $psr7Response->setConnection(new WritableConnection($response));

        $psr7Request = Psr7Request::loadFromSwooleRequest($request);

        RequestContext::set($psr7Request);

        return [$psr7Request, $psr7Response];
    }
}
