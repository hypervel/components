<?php

declare(strict_types=1);

namespace Hypervel\WebSocketServer;

use Hyperf\HttpMessage\Base\Response;
use Hypervel\Context\ResponseContext;
use Hypervel\HttpServer\CoreMiddleware as HttpCoreMiddleware;
use Hypervel\HttpServer\Router\Dispatched;
use Hypervel\WebSocketServer\Exception\WebSocketHandShakeException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class CoreMiddleware extends HttpCoreMiddleware
{
    public const HANDLER_NAME = 'class';

    /**
     * Handle the response when found.
     */
    protected function handleFound(Dispatched $dispatched, ServerRequestInterface $request): ResponseInterface
    {
        [$controller] = $this->prepareHandler($dispatched->handler->callback);
        if (! $this->container->has($controller)) {
            throw new WebSocketHandShakeException('Router not exist.');
        }

        /** @var Response $response */
        $response = ResponseContext::get();

        $security = $this->container->make(Security::class);

        $key = $request->getHeaderLine(Security::SEC_WEBSOCKET_KEY);
        $response = $response->setStatus(101)->setHeaders($security->handshakeHeaders($key));
        if ($wsProtocol = $request->getHeaderLine(Security::SEC_WEBSOCKET_PROTOCOL)) {
            $response = $response->setHeader(Security::SEC_WEBSOCKET_PROTOCOL, $wsProtocol);
        }

        return $response->setAttribute(self::HANDLER_NAME, $controller);
    }
}
