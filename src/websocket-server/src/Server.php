<?php

declare(strict_types=1);

namespace Hypervel\WebSocketServer;

use Hypervel\Contracts\Config\Repository;
use Hyperf\Contract\StdoutLoggerInterface;
use Hypervel\Context\Context;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Server\MiddlewareInitializerInterface;
use Hypervel\Contracts\Server\OnCloseInterface;
use Hypervel\Contracts\Server\OnHandShakeInterface;
use Hypervel\Contracts\Server\OnMessageInterface;
use Hypervel\Contracts\Server\OnOpenInterface;
use Hypervel\Coordinator\Constants;
use Hypervel\Coordinator\CoordinatorManager;
use Hypervel\Dispatcher\HttpDispatcher;
use Hypervel\Engine\Http\FdGetter;
use Hypervel\ExceptionHandler\ExceptionHandlerDispatcher;
use Hypervel\HttpMessage\Base\Response;
use Hypervel\HttpMessage\Server\Request as Psr7Request;
use Hypervel\HttpMessage\Server\Response as Psr7Response;
use Hypervel\HttpServer\Contracts\CoreMiddlewareInterface;
use Hypervel\HttpServer\MiddlewareManager;
use Hypervel\HttpServer\ResponseEmitter;
use Hypervel\HttpServer\Router\Dispatched;
use Hypervel\Support\SafeCaller;
use Hypervel\WebSocketServer\Collector\FdCollector;
use Hypervel\WebSocketServer\Context as WsContext;
use Hypervel\WebSocketServer\Exception\Handler\WebSocketExceptionHandler;
use Hypervel\WebSocketServer\Exception\WebSocketHandShakeException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Swoole\Http\Request;
use Swoole\Http\Response as SwooleResponse;
use Swoole\Server as SwooleServer;
use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server as WebSocketServer;
use Throwable;

use function Hypervel\Coroutine\defer;

class Server implements MiddlewareInitializerInterface, OnHandShakeInterface, OnCloseInterface, OnMessageInterface
{
    protected ?CoreMiddlewareInterface $coreMiddleware = null;

    protected array $exceptionHandlers = [];

    protected array $middlewares = [];

    protected string $serverName = 'websocket';

    public function __construct(
        protected Container $container,
        protected HttpDispatcher $dispatcher,
        protected ExceptionHandlerDispatcher $exceptionHandlerDispatcher,
        protected ResponseEmitter $responseEmitter,
        protected StdoutLoggerInterface $logger,
    ) {
    }

    /**
     * Initialize the core middleware and load configuration.
     */
    public function initCoreMiddleware(string $serverName): void
    {
        $this->serverName = $serverName;
        $this->coreMiddleware = new CoreMiddleware($this->container, $serverName);

        $config = $this->container->make(Repository::class);
        $this->middlewares = $config->get('middlewares.' . $serverName, []);
        $this->exceptionHandlers = $config->get('exceptions.handler.' . $serverName, [
            WebSocketExceptionHandler::class,
        ]);
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
     * Handle the WebSocket handshake request.
     */
    public function onHandShake(Request $request, SwooleResponse $response): void
    {
        try {
            CoordinatorManager::until(Constants::WORKER_START)->yield();
            $fd = $this->getFd($response);
            Context::set(WsContext::FD, $fd);
            $security = $this->container->make(Security::class);

            $psr7Response = $this->initResponse();
            $psr7Request = $this->initRequest($request);

            $this->logger->debug(sprintf('WebSocket: fd[%d] start a handshake request.', $fd));

            $key = $psr7Request->getHeaderLine(Security::SEC_WEBSOCKET_KEY);
            if ($security->isInvalidSecurityKey($key)) {
                throw new WebSocketHandShakeException('sec-websocket-key is invalid!');
            }

            $psr7Request = $this->coreMiddleware->dispatch($psr7Request);
            $middlewares = $this->middlewares;
            /** @var Dispatched $dispatched */
            $dispatched = $psr7Request->getAttribute(Dispatched::class);
            if ($dispatched->isFound()) {
                $registeredMiddlewares = MiddlewareManager::get($this->serverName, $dispatched->handler->route, $psr7Request->getMethod());
                $middlewares = array_merge($middlewares, $registeredMiddlewares);
            }

            /** @var Response $psr7Response */
            $psr7Response = $this->dispatcher->dispatch($psr7Request, $middlewares, $this->coreMiddleware);

            $class = $psr7Response->getAttribute(CoreMiddleware::HANDLER_NAME);

            if (empty($class)) {
                $this->logger->warning('WebSocket handshake failed, because the class does not exists.');
                return;
            }

            FdCollector::set($fd, $class);
            $server = $this->getServer();
            $this->deferOnOpen($request, $class, $server, $fd);
        } catch (Throwable $throwable) {
            // Delegate the exception to exception handler.
            $psr7Response = $this->container->make(SafeCaller::class)->call(function () use ($throwable) {
                return $this->exceptionHandlerDispatcher->dispatch($throwable, $this->exceptionHandlers);
            }, static function () {
                return (new Psr7Response())->withStatus(400);
            });

            isset($fd) && FdCollector::del($fd);
            isset($fd) && WsContext::release($fd);
        } finally {
            // Send the Response to client.
            if (isset($psr7Response) && $psr7Response instanceof ResponseInterface) {
                $this->responseEmitter->emit($psr7Response, $response, true);
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
        defer(function () use ($fd) {
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
        defer(static function () use ($request, $instance, $server) {
            if ($instance instanceof OnOpenInterface) {
                $instance->onOpen($server, $request);
            }
        });
    }

    /**
     * Initialize PSR-7 Request from the Swoole request.
     */
    protected function initRequest(Request $request): ServerRequestInterface
    {
        $psr7Request = Psr7Request::loadFromSwooleRequest($request);
        Context::set(ServerRequestInterface::class, $psr7Request);
        WsContext::set(ServerRequestInterface::class, $psr7Request);
        return $psr7Request;
    }

    /**
     * Initialize PSR-7 Response.
     */
    protected function initResponse(): ResponseInterface
    {
        Context::set(ResponseInterface::class, $psr7Response = new Psr7Response());
        return $psr7Response;
    }
}
