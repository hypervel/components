<?php

declare(strict_types=1);

namespace Hypervel\Reverb\Servers\Hypervel;

use Hypervel\Context\RequestContext;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Contracts\Server\MiddlewareInitializerInterface;
use Hypervel\Contracts\Server\OnRequestInterface;
use Hypervel\Coordinator\Constants;
use Hypervel\Coordinator\CoordinatorManager;
use Hypervel\HttpServer\RequestBridge;
use Hypervel\HttpServer\ResponseBridge;
use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response as SwooleResponse;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Isolated HTTP request handler for the Reverb WebSocket server port.
 *
 * Dispatches requests through the ReverbRouter (which only contains
 * Reverb routes), not the global app Router. This ensures app routes
 * are inaccessible on the Reverb port.
 */
class HttpServer implements OnRequestInterface, MiddlewareInitializerInterface
{
    protected ReverbRouter $router;

    public function __construct(
        protected Container $container,
    ) {
    }

    /**
     * Resolve the Reverb router and compile its routes.
     */
    public function initCoreMiddleware(string $serverName): void
    {
        $this->router = $this->container->make(ReverbRouter::class);
        $this->router->compileAndWarm();
    }

    /**
     * Handle an incoming HTTP request on the Reverb port.
     */
    public function onRequest(SwooleRequest $swooleRequest, SwooleResponse $swooleResponse): void
    {
        $request = null;

        try {
            CoordinatorManager::until(Constants::WORKER_START)->yield();

            $request = RequestBridge::createFromSwoole($swooleRequest);
            RequestContext::set($request);

            $response = $this->router->dispatch($request);
        } catch (Throwable $throwable) {
            $handler = $this->container->make(ExceptionHandler::class);
            $handler->report($throwable);
            $response = $request
                ? $handler->render($request, $throwable)
                : new Response('Internal Server Error', 500);
        } finally {
            if (isset($response)) {
                ResponseBridge::send($response, $swooleResponse);
            }
        }
    }
}
