<?php

declare(strict_types=1);

namespace Hypervel\Reverb\Servers\Hypervel;

use Hypervel\Context\RequestContext;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Contracts\Events\Dispatcher as EventDispatcherContract;
use Hypervel\Contracts\Server\BootstrapsForServer;
use Hypervel\Contracts\Server\OnRequestInterface;
use Hypervel\Coordinator\Constants;
use Hypervel\Coordinator\CoordinatorManager;
use Hypervel\Http\Request;
use Hypervel\HttpServer\Events\RequestHandled;
use Hypervel\HttpServer\Events\RequestReceived;
use Hypervel\HttpServer\Events\ResponseSent;
use Hypervel\HttpServer\RequestBridge;
use Hypervel\HttpServer\ResponseBridge;
use Swoole\Coroutine\CanceledException;
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
class HttpServer implements OnRequestInterface, BootstrapsForServer
{
    protected ReverbRouter $router;

    protected int $maxRequestSize;

    protected string $serverName = 'reverb';

    protected ?EventDispatcherContract $event = null;

    public function __construct(
        protected Container $container,
    ) {
        if ($this->container->bound('events')) {
            $this->event = $this->container->make('events');
        }
    }

    /**
     * Resolve the Reverb router, compile its routes, and cache server limits.
     */
    public function bootstrapForServer(string $serverName): void
    {
        $this->serverName = $serverName;
        $this->router = $this->container->make(ReverbRouter::class);
        $this->router->compileAndWarm();
        $this->maxRequestSize = $this->container->make('config')
            ->integer('reverb.servers.reverb.max_request_size');
    }

    /**
     * Handle an incoming HTTP request on the Reverb port.
     */
    public function onRequest(SwooleRequest $swooleRequest, SwooleResponse $swooleResponse): void
    {
        $request = null;

        try {
            CoordinatorManager::until(Constants::WORKER_START)->yield();

            if ($this->exceedsMaxRequestSize($swooleRequest)) {
                $response = new Response('Payload Too Large', 413);
            } else {
                $request = RequestBridge::createFromSwoole($swooleRequest);
                RequestContext::set($request);

                if ($this->event?->hasListeners(RequestReceived::class)) {
                    $this->event->dispatch(new RequestReceived(
                        request: $request,
                        response: null,
                        server: $this->serverName,
                    ));
                }

                $response = $this->router->dispatch($request);
            }
        } catch (CanceledException $cancellation) {
            throw $cancellation;
        } catch (Throwable $throwable) {
            // Keep the original in flight while it is handled and emitted, so
            // any failure at that boundary carries the root cause as previous.
            // The return suppresses it once the response has been emitted.
            try {
                /* @phpstan-ignore finally.exitPoint */
                throw $throwable;
            } finally {
                $handler = $this->container->make(ExceptionHandler::class);
                $handler->report($throwable);
                $response = $request !== null
                    ? $handler->render($request, $throwable)
                    : new Response('Internal Server Error', 500);
                $this->sendResponse($request, $response, $swooleResponse, $throwable);

                /* @phpstan-ignore finally.exitPoint */
                return;
            }
        }

        $this->sendResponse($request, $response, $swooleResponse);
    }

    /**
     * Send a routed response through its observable transport boundary.
     */
    protected function sendResponse(
        ?Request $request,
        Response $response,
        SwooleResponse $swooleResponse,
        ?Throwable $exception = null,
    ): void {
        $terminalException = null;

        if ($request !== null) {
            try {
                if ($this->event?->hasListeners(RequestHandled::class)) {
                    $this->event->dispatch(new RequestHandled(
                        request: $request,
                        response: $response,
                        exception: $exception,
                        server: $this->serverName,
                    ));
                }
            } catch (CanceledException $throwable) {
                throw $throwable;
            } catch (Throwable $throwable) {
                $terminalException = $throwable;
            }
        }

        try {
            ResponseBridge::send($response, $swooleResponse, request: $request);
        } catch (CanceledException $throwable) {
            throw $throwable;
        } catch (Throwable $throwable) {
            $terminalException ??= $throwable;
        }

        if ($request !== null) {
            try {
                if ($this->event?->hasListeners(ResponseSent::class)) {
                    $this->event->dispatch(new ResponseSent(
                        request: $request,
                        response: $response,
                        exception: $exception ?? $terminalException,
                        server: $this->serverName,
                    ));
                }
            } catch (CanceledException $throwable) {
                throw $throwable;
            } catch (Throwable $throwable) {
                $terminalException ??= $throwable;
            }
        }

        if ($terminalException !== null) {
            throw $terminalException;
        }
    }

    /**
     * Determine if the HTTP request exceeds Reverb's request-size limit.
     */
    protected function exceedsMaxRequestSize(SwooleRequest $request): bool
    {
        $contentLength = $request->header['content-length'] ?? null;

        if (is_string($contentLength) && ctype_digit($contentLength)) {
            return (int) $contentLength > $this->maxRequestSize;
        }

        // Requests without a valid Content-Length have already been bounded
        // by Swoole's package limits; this fallback covers that uncommon path.
        $content = $request->rawContent();

        return is_string($content) && strlen($content) > $this->maxRequestSize;
    }
}
