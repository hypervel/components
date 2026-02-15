<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Http;

use Hypervel\HttpMessage\Base\Response;
use Hypervel\HttpMessage\Server\Response as Psr7Response;
use Hypervel\Context\Context;
use Hypervel\Contracts\Debug\ExceptionHandler as ExceptionHandlerContract;
use Hypervel\Coordinator\Constants;
use Hypervel\Coordinator\CoordinatorManager;
use Hypervel\Foundation\Exceptions\Handler as ExceptionHandler;
use Hypervel\Foundation\Http\Contracts\MiddlewareContract;
use Hypervel\Foundation\Http\Traits\HasMiddleware;
use Hypervel\Support\SafeCaller;
use Hypervel\WebSocketServer\Collector\FdCollector;
use Hypervel\WebSocketServer\Context as WsContext;
use Hypervel\WebSocketServer\CoreMiddleware;
use Hypervel\WebSocketServer\Exception\WebSocketHandShakeException;
use Hypervel\WebSocketServer\Security;
use Hypervel\WebSocketServer\Server as WebSocketServer;
use Psr\Http\Message\ResponseInterface;
use Swoole\Http\Request;
use Swoole\Http\Response as SwooleResponse;
use Throwable;

class WebsocketKernel extends WebSocketServer implements MiddlewareContract
{
    use HasMiddleware;

    public function initCoreMiddleware(string $serverName): void
    {
        $this->serverName = $serverName;
        $this->coreMiddleware = $this->container->make(CoreMiddleware::class, [
            'container' => $this->container,
            'serverName' => $serverName,
        ]);

        $this->initExceptionHandlers();
    }

    protected function initExceptionHandlers(): void
    {
        /* @phpstan-ignore-next-line */
        $this->exceptionHandlers = $this->container->bound(ExceptionHandlerContract::class)
            ? [ExceptionHandlerContract::class]
            : [ExceptionHandler::class];
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

            /** @var Response $psr7Response */
            $psr7Response = $this->dispatcher->dispatch(
                $psr7Request = $this->coreMiddleware->dispatch($psr7Request),
                $this->getMiddlewareForRequest($psr7Request),
                $this->coreMiddleware
            );

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
}
