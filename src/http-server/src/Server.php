<?php

declare(strict_types=1);

namespace Hypervel\HttpServer;

use Hypervel\Context\RequestContext;
use Hypervel\Context\ResponseContext;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Events\Dispatcher as EventDispatcherContract;
use Hypervel\Contracts\Http\Kernel as KernelContract;
use Hypervel\Contracts\Server\MiddlewareInitializerInterface;
use Hypervel\Contracts\Server\OnRequestInterface;
use Hypervel\Coordinator\Constants;
use Hypervel\Coordinator\CoordinatorManager;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Engine\Http\WritableConnection;
use Hypervel\Http\Response;
use Hypervel\HttpServer\Events\RequestHandled;
use Hypervel\HttpServer\Events\RequestReceived;
use Hypervel\HttpServer\Events\RequestTerminated;
use Hypervel\Server\Option;
use Hypervel\Server\ServerFactory;
use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response as SwooleResponse;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Throwable;

class Server implements OnRequestInterface, MiddlewareInitializerInterface
{
    protected ?KernelContract $kernel = null;

    protected ?string $serverName = null;

    protected ?EventDispatcherContract $event = null;

    protected ?Option $option = null;

    public function __construct(
        protected Container $container,
    ) {
        if ($this->container->has(EventDispatcherContract::class)) {
            $this->event = $this->container->make(EventDispatcherContract::class);
        }
    }

    /**
     * Resolve the Kernel, sync middleware to Router, and trigger route compilation.
     *
     * Called by the server boot process (Server\Server::registerSwooleEvents),
     * before $server->start(). In SWOOLE_PROCESS mode this runs in the main
     * process — workers inherit the compiled state via copy-on-write fork.
     */
    public function initCoreMiddleware(string $serverName): void
    {
        $this->serverName = $serverName;

        // Resolve the Kernel via Contracts\Http\Kernel binding (set in bootstrap/app.php)
        $this->kernel = $this->container->make(KernelContract::class);

        // Trigger middleware sync + route compilation/pre-warming
        $this->kernel->bootstrap();

        // Compile routes and pre-warm all static caches for HTTP serving
        // performance. Runs in the main process before fork — workers
        // inherit via copy-on-write. Idempotent if WS server already ran.
        $this->container->make(\Hypervel\Routing\Router::class)->compileAndWarm();

        $this->initOption();
    }

    /**
     * Handle an incoming Swoole HTTP request.
     *
     * Transport only: Swoole → Bridge → Kernel → Bridge → Swoole.
     * Also dispatches request lifecycle events (used by Telescope, etc.)
     * when enabled via the server's `enable_request_lifecycle` option.
     */
    public function onRequest(SwooleRequest $swooleRequest, SwooleResponse $swooleResponse): void
    {
        try {
            CoordinatorManager::until(Constants::WORKER_START)->yield();

            // Capture the raw transport method before any Symfony method-override
            // processing. This avoids SuspiciousOperationException from malformed
            // _method overrides and ensures HEAD body suppression uses the actual
            // HTTP method, not the application-level override.
            $rawMethod = strtoupper($swooleRequest->server['request_method'] ?? 'GET');

            // Convert Swoole request to HttpFoundation and store in coroutine context
            // so request() helper, RequestContext::get(), and container aliases all
            // resolve the current request for this coroutine.
            $request = RequestBridge::createFromSwoole($swooleRequest);
            RequestContext::set($request);

            // Create a response with the Swoole connection and store in coroutine context.
            // This is needed for the direct streaming path: Response::stream() accesses the
            // WritableConnection to write chunks directly to the Swoole socket. Controllers
            // reach it via ResponseContext::get()->getConnection()->getSocket().
            $response = new Response();
            $response->setConnection(new WritableConnection($swooleResponse));
            if ($rawMethod === 'HEAD') {
                $response->withoutBody();
            }
            ResponseContext::set($response);

            $this->option?->isEnableRequestLifecycle() && $this->event?->dispatch(new RequestReceived(
                request: $request,
                response: $response,
                server: $this->serverName
            ));

            // Dispatch through the Kernel (global middleware → Router → response)
            $response = $this->kernel->handle($request);
        } catch (Throwable $throwable) {
            // If Kernel::handle() itself throws (shouldn't normally — it catches internally),
            // we still need to send something back to the client.
            $response = new SymfonyResponse('Internal Server Error', 500);
        } finally {
            if (isset($request) && $this->option?->isEnableRequestLifecycle()) {
                Coroutine::defer(fn () => $this->event?->dispatch(new RequestTerminated(
                    request: $request,
                    response: $response ?? null,
                    exception: $throwable ?? null,
                    server: $this->serverName
                )));

                $this->event?->dispatch(new RequestHandled(
                    request: $request,
                    response: $response ?? null,
                    exception: $throwable ?? null,
                    server: $this->serverName
                ));
            }

            // Send HttpFoundation response back through Swoole
            if (isset($response)) {
                ResponseBridge::send(
                    $response,
                    $swooleResponse,
                    withBody: ! isset($rawMethod) || $rawMethod !== 'HEAD'
                );
            }

            // Terminable middleware
            if (isset($request, $response)) {
                $this->kernel->terminate($request, $response);
            }
        }
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
