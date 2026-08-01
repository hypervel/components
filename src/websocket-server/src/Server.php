<?php

declare(strict_types=1);

namespace Hypervel\WebSocketServer;

use Hypervel\Context\CoroutineContext;
use Hypervel\Context\RequestContext;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Debug\ExceptionHandler as ExceptionHandlerContract;
use Hypervel\Contracts\Events\Dispatcher as EventDispatcherContract;
use Hypervel\Contracts\Http\Kernel as KernelContract;
use Hypervel\Contracts\Log\StdoutLoggerInterface;
use Hypervel\Contracts\Server\BootstrapsForServer;
use Hypervel\Contracts\Server\OnCloseInterface;
use Hypervel\Contracts\Server\OnHandshakeInterface;
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
use Hypervel\WebSocketServer\Exceptions\WebSocketHandshakeException;
use Swoole\Coroutine\CanceledException;
use Swoole\Http\Request;
use Swoole\Http\Response as SwooleResponse;
use Swoole\Server as SwooleServer;
use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server as WebSocketServer;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class Server implements BootstrapsForServer, OnHandshakeInterface, OnCloseInterface, OnMessageInterface
{
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

        $kernel = $this->container->make(KernelContract::class);
        $kernel->bootstrap();

        // Compile routes and pre-warm all static caches. WS handshake
        // routes through the Router, so this benefits WS performance too.
        // Idempotent if HTTP server already ran.
        $this->getRouter()->compileAndWarm();

        $this->handshakeHandler = new HandshakeHandler($this->container);
    }

    /**
     * Handle the WebSocket handshake request.
     *
     * Converts the Swoole request to HttpFoundation, validates the WebSocket
     * security key, dispatches through the Router for route matching and
     * middleware execution, then builds the 101 Switching Protocols response.
     */
    public function onHandshake(Request $request, SwooleResponse $response): void
    {
        $committed = false;
        $fd = null;
        $httpRequest = null;
        $handshake = null;

        try {
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
                    throw new WebSocketHandshakeException('sec-websocket-key is invalid!');
                }

                // Route matching + middleware via Router.
                // dispatchToCallback() performs the full Router context lifecycle
                // (findRoute, context setup, RouteMatched event, middleware pipeline)
                // but calls our handshake handler instead of the route's controller.
                $httpResponse = $this->getRouter()->dispatchToCallback(
                    $httpRequest,
                    fn (HttpRequest $req) => $this->handshakeHandler->handleHandshake($req)
                );

                if ($httpResponse->getStatusCode() === Response::HTTP_SWITCHING_PROTOCOLS) {
                    /** @var class-string $class */
                    $class = $httpRequest->route()->getControllerClass();
                    $instance = $this->container->make($class);
                    $server = $this->getServer();
                    $handshake = [$fd, $class, $instance, $server];
                }
            } catch (CanceledException $exception) {
                throw $exception;
            } catch (Throwable $throwable) {
                $httpResponse = $this->container->make(SafeCaller::class)->call(
                    fn () => $this->handleException($throwable),
                    static fn () => new Response(
                        Response::$statusTexts[Response::HTTP_INTERNAL_SERVER_ERROR],
                        Response::HTTP_INTERNAL_SERVER_ERROR
                    )
                );
            }

            ResponseBridge::send($httpResponse, $response, request: $httpRequest);

            if ($handshake === null) {
                return;
            }

            [$fd, $class, $instance, $server] = $handshake;

            if (! $server->isEstablished($fd)) {
                return;
            }

            // No yield may occur between the native liveness check and
            // publication, or onClose could miss the committed connection.
            $this->deferOnOpen($request, $instance, $server, $fd);
            FdCollector::set($fd, $class);
            $committed = true;
        } finally {
            if ($fd !== null && ! $committed) {
                FdCollector::del($fd);
                WebSocketContext::release($fd);
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

        try {
            $class = FdCollector::get($fd);
            if ($class === null) {
                $this->logger->warning(sprintf('WebSocket: fd[%d] does not exist.', $fd));

                return;
            }

            $instance = $this->container->make($class);
        } catch (CanceledException) {
            // Swoole 6.2 disables SW_RECOVER_CANCELED_EXCEPTION, so cancellation
            // escaping an unguarded callback coroutine terminates the worker.
            return;
        } catch (Throwable $throwable) {
            $this->reportCallbackFailure($throwable);

            return;
        }

        if (! $instance instanceof OnMessageInterface) {
            try {
                $this->logger->warning($instance::class . ' is not instanceof ' . OnMessageInterface::class);
            } catch (CanceledException) {
                return;
            } catch (Throwable $throwable) {
                $this->reportCallbackFailure($throwable);
            }

            return;
        }

        try {
            if ($this->event?->hasListeners(MessageReceived::class)) {
                $this->event->dispatch(new MessageReceived($fd, $frame, $this->serverName));
            }
        } catch (CanceledException) {
            return;
        } catch (Throwable $throwable) {
            $this->reportCallbackFailure($throwable);
        }

        try {
            $instance->onMessage($server, $frame);
        } catch (CanceledException) {
            return;
        } catch (Throwable $throwable) {
            $this->reportCallbackFailure($throwable);
        }
    }

    /**
     * Handle a WebSocket connection close.
     */
    public function onClose(SwooleServer $server, int $fd, int $reactorId): void
    {
        CoroutineContext::set(WebSocketContext::FD, $fd);

        try {
            $class = FdCollector::get($fd);
            if ($class === null) {
                return;
            }

            try {
                $this->logger->debug(sprintf('WebSocket: fd[%d] closed.', $fd));
            } catch (CanceledException) {
                return;
            } catch (Throwable $throwable) {
                $this->reportCallbackFailure($throwable);
            }

            try {
                $instance = $this->container->make($class);
            } catch (CanceledException) {
                return;
            } catch (Throwable $throwable) {
                $this->reportCallbackFailure($throwable);
                $instance = null;
            }

            if ($instance instanceof OnCloseInterface) {
                try {
                    $instance->onClose($server, $fd, $reactorId);
                } catch (CanceledException) {
                    return;
                } catch (Throwable $throwable) {
                    $this->reportCallbackFailure($throwable);
                }
            }

            try {
                if ($this->event?->hasListeners(ConnectionClosed::class)) {
                    $this->event->dispatch(new ConnectionClosed($fd, $reactorId, $this->serverName));
                }
            } catch (CanceledException) {
                return;
            } catch (Throwable $throwable) {
                $this->reportCallbackFailure($throwable);
            }
        } finally {
            FdCollector::del($fd);
            WebSocketContext::release($fd);
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
        // Keep the original in flight while it is handled, so a failure in
        // resolution or handling carries it as that failure's previous. The
        // return suppresses it once a response exists.
        try {
            /* @phpstan-ignore finally.exitPoint */
            throw $throwable;
        } finally {
            $handler = $this->container->make(WebSocketExceptionHandler::class);

            /* @phpstan-ignore finally.exitPoint */
            return $handler->handle($throwable, new Response);
        }
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
     * Boot-only. Mutates the worker-lifetime WebSocket handler before Swoole
     * starts; runtime use races across connections and changes emitted server
     * context.
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
    protected function deferOnOpen(Request $request, object $instance, WebSocketServer $server, int $fd): void
    {
        Coroutine::defer(function () use ($request, $instance, $server, $fd) {
            try {
                if ($this->event?->hasListeners(ConnectionOpened::class)) {
                    $this->event->dispatch(new ConnectionOpened($fd, $request, $this->serverName));
                }
            } catch (CanceledException) {
                return;
            } catch (Throwable $throwable) {
                $this->reportCallbackFailure($throwable);
            }

            if ($instance instanceof OnOpenInterface) {
                try {
                    $instance->onOpen($server, $request);
                } catch (CanceledException) {
                    return;
                } catch (Throwable $throwable) {
                    $this->reportCallbackFailure($throwable);
                }
            }
        });
    }

    /**
     * Report a WebSocket callback failure without escaping the native boundary.
     */
    protected function reportCallbackFailure(Throwable $throwable): void
    {
        try {
            $this->container->make(ExceptionHandlerContract::class)->report($throwable);

            return;
        } catch (Throwable) {
        }

        try {
            $this->logger->error((string) $throwable);

            return;
        } catch (Throwable) {
        }

        try {
            error_log((string) $throwable);
        } catch (Throwable) {
        }
    }
}
