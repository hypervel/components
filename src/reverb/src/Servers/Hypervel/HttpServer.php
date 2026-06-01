<?php

declare(strict_types=1);

namespace Hypervel\Reverb\Servers\Hypervel;

use Hypervel\Context\RequestContext;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Contracts\Server\BootstrapsForServer;
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
class HttpServer implements OnRequestInterface, BootstrapsForServer
{
    /**
     * The default maximum HTTP request body size, in bytes.
     */
    protected const DEFAULT_MAX_REQUEST_SIZE = 10_000;

    protected ReverbRouter $router;

    protected int $maxRequestSize = self::DEFAULT_MAX_REQUEST_SIZE;

    public function __construct(
        protected Container $container,
    ) {
    }

    /**
     * Resolve the Reverb router, compile its routes, and cache server limits.
     */
    public function bootstrapForServer(string $serverName): void
    {
        $this->router = $this->container->make(ReverbRouter::class);
        $this->router->compileAndWarm();
        $this->maxRequestSize = $this->container->make('config')
            ->integer('reverb.servers.reverb.max_request_size', self::DEFAULT_MAX_REQUEST_SIZE);
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

                return;
            }

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
