<?php

declare(strict_types=1);

namespace Hypervel\WebSocketServer;

use Hypervel\Context\CoroutineContext;
use Hypervel\Context\RequestContext;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Events\Dispatcher as EventDispatcherContract;
use Hypervel\Contracts\Http\Kernel as KernelContract;
use Hypervel\Contracts\Log\StdoutLoggerInterface;
use Hypervel\Contracts\Server\BootstrapsForServer;
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
use Hypervel\Support\SafeCaller;
use Hypervel\WebSocketServer\Collector\FdCollector;
use Hypervel\WebSocketServer\Context as WebSocketContext;
use Hypervel\WebSocketServer\Events\ConnectionClosed;
use Hypervel\WebSocketServer\Events\ConnectionOpened;
use Hypervel\WebSocketServer\Events\MessageReceived;
use Hypervel\WebSocketServer\Exceptions\Handler\WebSocketExceptionHandler;
use Hypervel\WebSocketServer\Exceptions\WebSocketHandShakeException;
use Swoole\Http\Request;
use Swoole\Http\Response as SwooleResponse;
use Swoole\Server as SwooleServer;
use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server as WebSocketServer;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class Server implements BootstrapsForServer, OnHandShakeInterface, OnCloseInterface, OnMessageInterface
{
    protected ?KernelContract $kernel = null;

    protected HandshakeHandler $handshakeHandler;

    protected ?EventDispatcherContract $event = null;

    protected StdoutLoggerInterface $logger;

    protected string $serverName = 'websocket';

    public function __construct(
        protected Container $container,
    ) {
        $this->logger = $container->make(StdoutLoggerInterface::class);

        if ($this->container->bound('events')) {
            $this->event = $this->container->make('events');
        }
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
    public function bootstrapForServer(string $serverName): void
    {
        $this->serverName = $serverName;

        $this->kernel = $this->container->make(KernelContract::class);
        $this->kernel->bootstrap();

        // Compile routes and pre-warm all static caches. WS handshake
        // routes through the Router, so this benefits WS performance too.
        // Idempotent if HTTP server already ran.
        $this->container->make('router')->compileAndWarm();

        $this->handshakeHandler = new HandshakeHandler($this->container);
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
            CoroutineContext::set(WebSocketContext::FD, $fd);

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
            $httpResponse = $this->getRouter()->dispatchToCallback(
                $httpRequest,
                fn (HttpRequest $req) => $this->handshakeHandler->handleHandshake($req)
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
            isset($fd) && WebSocketContext::release($fd);
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
        CoroutineContext::set(WebSocketContext::FD, $fd);
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

        if ($this->event?->hasListeners(MessageReceived::class)) {
            $this->event->dispatch(new MessageReceived($fd, $frame, $this->serverName));
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

        CoroutineContext::set(WebSocketContext::FD, $fd);
        Coroutine::defer(function () use ($fd) {
            // Move those functions to defer, because onClose may throw exceptions
            FdCollector::del($fd);
            WebSocketContext::release($fd);
        });

        $instance = $this->container->make($fdObj->class);
        if ($instance instanceof OnCloseInterface) {
            try {
                $instance->onClose($server, $fd, $reactorId);
            } catch (Throwable $exception) {
                $this->logger->error((string) $exception);
            }
        }

        if ($this->event?->hasListeners(ConnectionClosed::class)) {
            $this->event->dispatch(new ConnectionClosed($fd, $reactorId, $this->serverName));
        }
    }

    /**
     * Handle an exception that occurred during the handshake.
     *
     * Subclasses (e.g. Foundation\Http\WebSocketKernel) override this to
     * use the application's exception handler instead of the default.
     */
    protected function handleException(Throwable $throwable): Response
    {
        $handler = $this->container->make(WebSocketExceptionHandler::class);

        return $handler->handle($throwable, new Response);
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
     * Get the router instance for WebSocket handshake route matching.
     *
     * Override in subclasses to use an isolated router for packages
     * that register their own server entry (e.g. Reverb).
     */
    protected function getRouter(): Router
    {
        return $this->container->make('router');
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
        Coroutine::defer(function () use ($request, $instance, $server, $fd) {
            if ($this->event?->hasListeners(ConnectionOpened::class)) {
                $this->event->dispatch(new ConnectionOpened($fd, $request, $this->serverName));
            }

            if ($instance instanceof OnOpenInterface) {
                try {
                    $instance->onOpen($server, $request);
                } catch (Throwable $exception) {
                    $this->logger->error((string) $exception);
                }
            }
        });
    }
}
