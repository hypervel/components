<?php

declare(strict_types=1);

namespace Hypervel\Server;

use Closure;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Server\BootstrapsForServer;
use Hypervel\Core\Bootstrap;
use Hypervel\Core\Events\BeforeMainServerStart;
use Hypervel\Core\Events\BeforeServerStart;
use Hypervel\Server\Exceptions\RuntimeException;
use Psr\Log\LoggerInterface;
use Swoole\Coroutine\CanceledException;
use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response as SwooleResponse;
use Swoole\Http\Server as SwooleHttpServer;
use Swoole\Server as SwooleServer;
use Swoole\Server\Port as SwoolePort;
use Swoole\WebSocket\Server as SwooleWebSocketServer;
use Throwable;

class Server implements ServerInterface
{
    protected ?SwooleServer $server = null;

    protected array $onRequestCallbacks = [];

    public function __construct(protected Container $container, protected LoggerInterface $logger, protected Dispatcher $eventDispatcher)
    {
    }

    /**
     * Initialize the server with the given configuration.
     *
     * Boot-only. Mutates the underlying Swoole server and registered callbacks;
     * runtime use races with active workers and request handling.
     */
    public function init(ServerConfig $config): ServerInterface
    {
        $this->initServers($config);

        return $this;
    }

    /**
     * Start the server.
     */
    public function start(): void
    {
        $this->server->start();
    }

    /**
     * Get the underlying Swoole server instance.
     */
    public function getServer(): SwooleServer
    {
        return $this->server;
    }

    /**
     * Initialize all server ports from the configuration.
     */
    protected function initServers(ServerConfig $config): void
    {
        $servers = $this->sortServers($config->getServers());

        foreach ($servers as $server) {
            $name = $server->getName();
            $type = $server->getType();
            $host = $server->getHost();
            $port = $server->getPort();
            $sockType = $server->getSockType();
            $callbacks = $server->getCallbacks();

            if (! $this->server instanceof SwooleServer) {
                $this->server = $this->makeServer($type, $host, $port, $config->getMode(), $sockType);
                $callbacks = array_replace($this->defaultCallbacks(), $config->getCallbacks(), $callbacks);
                $this->registerSwooleEvents($this->server, $callbacks, $name);
                $this->server->set(array_replace($config->getSettings(), $server->getSettings()));
                ServerManager::add($name, [$type, current($this->server->ports)]);

                // Trigger BeforeMainServerStart event, this event only triggers once before main server start.
                $this->eventDispatcher->dispatch(new BeforeMainServerStart($this->server, $config->toArray()));
            } else {
                /** @var bool|SwoolePort $slaveServer */
                $slaveServer = $this->server->addlistener($host, $port, $sockType);
                if (! $slaveServer) {
                    throw new \RuntimeException("Failed to listen server port [{$host}:{$port}]");
                }
                $server->getSettings() && $slaveServer->set(array_replace($config->getSettings(), $server->getSettings()));
                $this->registerSwooleEvents($slaveServer, $callbacks, $name);
                ServerManager::add($name, [$type, $slaveServer]);
            }

            // Trigger beforeStart event.
            if (isset($callbacks[Event::ON_BEFORE_START])) {
                [$class, $method] = $callbacks[Event::ON_BEFORE_START];
                if ($this->container->has($class)) {
                    $this->container->make($class)->{$method}();
                }
            }

            // Trigger BeforeServerStart event.
            $this->eventDispatcher->dispatch(new BeforeServerStart($name));
        }
    }

    /**
     * Sort servers so websocket/http servers are initialized first.
     *
     * @param Port[] $servers
     * @return Port[]
     */
    protected function sortServers(array $servers): array
    {
        $prioritizedServers = [];

        foreach (array_values($servers) as $index => $server) {
            $priority = match ($server->getType()) {
                ServerInterface::SERVER_WEBSOCKET => 0,
                ServerInterface::SERVER_HTTP => 1,
                default => 2,
            };

            $prioritizedServers[] = [$priority, $index, $server];
        }

        usort(
            $prioritizedServers,
            static fn (array $left, array $right): int => [$left[0], $left[1]] <=> [$right[0], $right[1]],
        );

        return array_map(
            static fn (array $prioritizedServer): Port => $prioritizedServer[2],
            $prioritizedServers,
        );
    }

    /**
     * Create the appropriate Swoole server instance based on type.
     */
    protected function makeServer(int $type, string $host, int $port, int $mode, int $sockType): SwooleServer
    {
        switch ($type) {
            case ServerInterface::SERVER_HTTP:
                return new SwooleHttpServer($host, $port, $mode, $sockType);
            case ServerInterface::SERVER_WEBSOCKET:
                return new SwooleWebSocketServer($host, $port, $mode, $sockType);
            case ServerInterface::SERVER_BASE:
                return new SwooleServer($host, $port, $mode, $sockType);
        }

        throw new RuntimeException('Server type is invalid.');
    }

    /**
     * Register Swoole event callbacks on the server or port.
     */
    protected function registerSwooleEvents(SwoolePort|SwooleServer $server, array $events, string $serverName): void
    {
        foreach ($events as $event => $callback) {
            if (! Event::isSwooleEvent($event)) {
                continue;
            }
            if (is_array($callback)) {
                [$className, $method] = $callback;
                if (array_key_exists($className . $method, $this->onRequestCallbacks)) {
                    $this->logger->warning(sprintf('%s will be replaced by %s. Each server should have its own onRequest callback. Please check your configs.', $this->onRequestCallbacks[$className . $method], $serverName));
                }

                $this->onRequestCallbacks[$className . $method] = $serverName;
                $class = $this->container->make($className);
                if (method_exists($class, 'setServerName')) {
                    // Override the server name.
                    $class->setServerName($serverName);
                }
                if ($class instanceof BootstrapsForServer) {
                    $class->bootstrapForServer($serverName);
                }
                $callback = [$class, $method];
            }

            if (
                ($event === Event::ON_REQUEST || $event === Event::ON_HANDSHAKE)
                && is_callable($callback)
            ) {
                $callback = $this->guardResponseCallback($callback);
            }

            $server->on($event, $callback);
        }
    }

    /**
     * Guard a native response callback from escaping its transport boundary.
     */
    protected function guardResponseCallback(callable $callback): Closure
    {
        return function (SwooleRequest $request, SwooleResponse $response) use ($callback): void {
            try {
                $callback($request, $response);
            } catch (Throwable $throwable) {
                if (! $throwable instanceof CanceledException) {
                    try {
                        $this->container->make(ExceptionHandler::class)->report($throwable);
                    } catch (Throwable) {
                        try {
                            error_log((string) $throwable);
                        } catch (Throwable) {
                        }
                    }
                }

                try {
                    if ($response->isWritable()) {
                        $response->end();
                    }
                } catch (Throwable) {
                }
            }
        };
    }

    /**
     * Get the default server callbacks for the bootstrap lifecycle.
     */
    protected function defaultCallbacks(): array
    {
        $callbacks = [
            Event::ON_MANAGER_START => [Bootstrap\ManagerStartCallback::class, 'onManagerStart'],
            Event::ON_WORKER_START => [Bootstrap\WorkerStartCallback::class, 'onWorkerStart'],
            Event::ON_WORKER_STOP => [Bootstrap\WorkerStopCallback::class, 'onWorkerStop'],
            Event::ON_WORKER_EXIT => [Bootstrap\WorkerExitCallback::class, 'onWorkerExit'],
        ];

        if ($this->server->mode === SWOOLE_BASE) {
            return $callbacks;
        }

        return array_merge([
            Event::ON_START => [Bootstrap\StartCallback::class, 'onStart'],
        ], $callbacks);
    }
}
