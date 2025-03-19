<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Http;

use Hyperf\Context\Context;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Coordinator\Constants;
use Hyperf\Coordinator\CoordinatorManager;
use Hyperf\Engine\Constant;
use Hyperf\Engine\WebSocket\WebSocket;
use Hyperf\HttpMessage\Base\Response;
use Hyperf\HttpMessage\Server\Response as Psr7Response;
use Hyperf\Support\SafeCaller;
use Hyperf\WebSocketServer\Collector\FdCollector;
use Hyperf\WebSocketServer\Context as WsContext;
use Hyperf\WebSocketServer\CoreMiddleware;
use Hyperf\WebSocketServer\Exception\Handler\WebSocketExceptionHandler;
use Hyperf\WebSocketServer\Exception\WebSocketHandShakeException;
use Hyperf\WebSocketServer\Security;
use Hyperf\WebSocketServer\Server as WebSocketServer;
use Hypervel\Foundation\Http\Contracts\MiddlewareContract;
use Hypervel\Foundation\Http\Traits\HasMiddleware;
use Psr\Http\Message\ResponseInterface;
use Swoole\Http\Request;
use Swoole\Http\Response as SwooleResponse;
use Swow\Psr7\Server\ServerConnection as SwowServerConnection;
use Throwable;

class WebsocketKernel extends WebSocketServer implements MiddlewareContract
{
    use HasMiddleware;

    public function initCoreMiddleware(string $serverName): void
    {
        $this->serverName = $serverName;
        $this->coreMiddleware = new CoreMiddleware($this->container, $serverName);

        $config = $this->container->get(ConfigInterface::class);
        $this->exceptionHandlers = $config->get('exceptions.handler.' . $serverName, [
            WebSocketExceptionHandler::class,
        ]);
    }

    /**
     * @param Request|\Swow\Http\Server\Request $request
     * @param SwooleResponse|SwowServerConnection $response
     */
    public function onHandShake($request, $response): void
    {
        try {
            CoordinatorManager::until(Constants::WORKER_START)->yield();
            $fd = $this->getFd($response);
            Context::set(WsContext::FD, $fd);
            $security = $this->container->get(Security::class);

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
            if (Constant::isCoroutineServer($server)) {
                $upgrade = new WebSocket($response, $request, $this->logger);

                $this->getSender()->setResponse($fd, $response);
                $this->deferOnOpen($request, $class, $response, $fd);

                $upgrade->on(WebSocket::ON_MESSAGE, $this->getOnMessageCallback());
                $upgrade->on(WebSocket::ON_CLOSE, $this->getOnCloseCallback());
                $upgrade->start();
            } else {
                $this->deferOnOpen($request, $class, $server, $fd);
            }
        } catch (Throwable $throwable) {
            // Delegate the exception to exception handler.
            $psr7Response = $this->container->get(SafeCaller::class)->call(function () use ($throwable) {
                return $this->exceptionHandlerDispatcher->dispatch($throwable, $this->exceptionHandlers);
            }, static function () {
                return (new Psr7Response())->withStatus(400);
            });

            isset($fd) && FdCollector::del($fd);
            isset($fd) && WsContext::release($fd);
        } finally {
            isset($fd) && $this->getSender()->setResponse($fd, null);
            // Send the Response to client.
            if (isset($psr7Response) && $psr7Response instanceof ResponseInterface) {
                $this->responseEmitter->emit($psr7Response, $response, true);
            }
        }
    }
}
