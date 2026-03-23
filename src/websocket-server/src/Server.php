<?php

declare(strict_types=1);

namespace Hypervel\WebSocketServer;

use Hypervel\Context\Context;
use Hypervel\Context\RequestContext;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Http\Kernel as KernelContract;
use Hypervel\Contracts\Log\StdoutLoggerInterface;
use Hypervel\Contracts\Server\MiddlewareInitializerInterface;
use Hypervel\Contracts\Server\OnCloseInterface;
use Hypervel\Contracts\Server\OnHandShakeInterface;
use Hypervel\Contracts\Server\OnMessageInterface;
use Hypervel\Contracts\Server\OnOpenInterface;
use Hypervel\Coordinator\Constants;
use Hypervel\Coordinator\CoordinatorManager;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Engine\Http\FdGetter;
use Hypervel\Http\Request as HttpRequest;
use Hypervel\HttpServer\RequestBridge;
use Hypervel\HttpServer\ResponseBridge;
use Hypervel\Routing\Router;
use Hypervel\Server\Option;
use Hypervel\Server\ServerFactory;
use Hypervel\Support\SafeCaller;
use Hypervel\WebSocketServer\Collector\FdCollector;
use Hypervel\WebSocketServer\Context as WsContext;
use Hypervel\WebSocketServer\Exceptions\Handler\WebSocketExceptionHandler;
use Hypervel\WebSocketServer\Exceptions\WebSocketHandShakeException;
use Swoole\Http\Request;
use Swoole\Http\Response as SwooleResponse;
use Swoole\Server as SwooleServer;
use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server as WebSocketServer;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class Server implements MiddlewareInitializerInterface, OnHandShakeInterface, OnCloseInterface, OnMessageInterface
{
    protected ?KernelContract $kernel = null;

    protected CoreMiddleware $coreMiddleware;

    protected StdoutLoggerInterface $logger;

    protected string $serverName = 'websocket';

    protected ?Option $option = null;

    public function __construct(
        protected Container $container,
    ) {
        $this->logger = $container->make(StdoutLoggerInterface::class);
    }

    /**
     * Bootstrap the application and initialize WebSocket components.
     *
     * Called by the server boot process (Server\Server::registerSwooleEvents)
     * before $server->start(). Resolves the HTTP Kernel to ensure the
     * application is bootstrapped (routes compiled, middleware synced) even
     * in WS-only setups where HttpServer\Server may not be present.
     * The hasBeenBootstrapped() guard makes this idempotent.
     */
    public function initCoreMiddleware(string $serverName): void
    {
        $this->serverName = $serverName;

        $this->kernel = $this->container->make(KernelContract::class);
        $this->kernel->bootstrap();

        // Compile routes and pre-warm all static caches. WS handshake
        // routes through the Router, so this benefits WS performance too.
        // Idempotent if HTTP server already ran.
        $this->container->make(\Hypervel\Routing\Router::class)->compileAndWarm();

        $this->coreMiddleware = new CoreMiddleware($this->container);

        $this->initOption();
    }

    /**
     * Handle the WebSocket handshake request.
     *
     * Converts the Swoole request to HttpFoundation, validates the WebSocket
     * security key, dispatches through the Router for route matching and
     * middleware execution, then builds the 101 Switching Protocols response.
     */
    public function onHandShake(Request $request, SwooleResponse $response): void
    {
        $httpResponse = null;

        try {
            CoordinatorManager::until(Constants::WORKER_START)->yield();
            $fd = $this->getFd($response);
            Context::set(WsContext::FD, $fd);

            // Create HttpFoundation request and seed contexts.
            // RequestContext is needed for request() helper and container resolution.
            $httpRequest = RequestBridge::createFromSwoole($request);
            RequestContext::set($httpRequest);

            $this->logger->debug(sprintf('WebSocket: fd[%d] start a handshake request.', $fd));

            // Validate sec-websocket-key before routing
            $key = $httpRequest->headers->get(Security::SEC_WEBSOCKET_KEY);
            $security = $this->container->make(Security::class);
            if (! $key || $security->isInvalidSecurityKey($key)) {
                throw new WebSocketHandShakeException('sec-websocket-key is invalid!');
            }

            // Route matching + middleware via Router.
            // dispatchToCallback() performs the full Router context lifecycle
            // (findRoute, context setup, RouteMatched event, middleware pipeline)
            // but calls our handshake handler instead of the route's controller.
            $router = $this->container->make(Router::class);
            $httpResponse = $router->dispatchToCallback(
                $httpRequest,
                fn (HttpRequest $req) => $this->coreMiddleware->handleHandshake($req)
            );

            // If middleware rejected (non-101 response), don't register the fd
            if ($httpResponse->getStatusCode() !== 101) {
                return;
            }

            // Get handler class from the matched route
            $class = $httpRequest->route()->getControllerClass();

            FdCollector::set($fd, $class);
            $server = $this->getServer();
            $this->deferOnOpen($request, $class, $server, $fd);
        } catch (Throwable $throwable) {
            $httpResponse = $this->container->make(SafeCaller::class)->call(
                fn () => $this->handleException($throwable),
                static fn () => new Response('Bad Request', 400)
            );

            isset($fd) && FdCollector::del($fd);
            isset($fd) && WsContext::release($fd);
        } finally {
            if ($httpResponse instanceof Response) {
                ResponseBridge::send($httpResponse, $response);
            }
        }
    }

    /**
     * Handle a WebSocket message.
     */
    public function onMessage(WebSocketServer $server, Frame $frame): void
    {
        $fd = $frame->fd;
        Context::set(WsContext::FD, $fd);
        $fdObj = FdCollector::get($fd);
        if (! $fdObj) {
            $this->logger->warning(sprintf('WebSocket: fd[%d] does not exist.', $fd));
            return;
        }

        $instance = $this->container->make($fdObj->class);

        if (! $instance instanceof OnMessageInterface) {
            $this->logger->warning($instance::class . ' is not instanceof ' . OnMessageInterface::class);
            return;
        }

        try {
            $instance->onMessage($server, $frame);
        } catch (Throwable $exception) {
            $this->logger->error((string) $exception);
        }
    }

    /**
     * Handle a WebSocket connection close.
     */
    public function onClose(SwooleServer $server, int $fd, int $reactorId): void
    {
        $fdObj = FdCollector::get($fd);
        if (! $fdObj) {
            return;
        }

        $this->logger->debug(sprintf('WebSocket: fd[%d] closed.', $fd));

        Context::set(WsContext::FD, $fd);
        Coroutine::defer(function () use ($fd) {
            // Move those functions to defer, because onClose may throw exceptions
            FdCollector::del($fd);
            WsContext::release($fd);
        });

        $instance = $this->container->make($fdObj->class);
        if ($instance instanceof OnCloseInterface) {
            try {
                $instance->onClose($server, $fd, $reactorId);
            } catch (Throwable $exception) {
                $this->logger->error((string) $exception);
            }
        }
    }

    /**
     * Handle an exception that occurred during the handshake.
     *
     * Subclasses (e.g. Foundation\Http\WebsocketKernel) override this to
     * use the application's exception handler instead of the default.
     */
    protected function handleException(Throwable $throwable): Response
    {
        $handler = $this->container->make(WebSocketExceptionHandler::class);

        return $handler->handle($throwable, new Response());
    }

    /**
     * Get the Swoole server instance.
     */
    public function getServer(): WebSocketServer
    {
        /** @var WebSocketServer */
        return $this->container->make(SwooleServer::class);
    }

    /**
     * Get the WebSocket sender instance.
     */
    public function getSender(): Sender
    {
        return $this->container->make(Sender::class);
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
    public function setServerName(string $serverName): static
    {
        $this->serverName = $serverName;

        return $this;
    }

    /**
     * Get the file descriptor from the response.
     */
    protected function getFd(SwooleResponse $response): int
    {
        return $this->container->make(FdGetter::class)->get($response);
    }

    /**
     * Defer the onOpen callback after handshake completes.
     */
    protected function deferOnOpen(Request $request, string $class, WebSocketServer $server, int $fd): void
    {
        $instance = $this->container->make($class);
        Coroutine::defer(static function () use ($request, $instance, $server) {
            if ($instance instanceof OnOpenInterface) {
                $instance->onOpen($server, $request);
            }
        });
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
    }
}
