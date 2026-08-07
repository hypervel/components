<?php

declare(strict_types=1);

namespace Hypervel\Reverb;

use Hypervel\Contracts\Bus\Dispatcher as BusDispatcher;
use Hypervel\Contracts\Redis\Factory as RedisFactory;
use Hypervel\Coordinator\Timer;
use Hypervel\Core\Events\AfterWorkerStart;
use Hypervel\Core\Events\OnPipeMessage;
use Hypervel\Core\Events\OnWorkerExit;
use Hypervel\RateLimiter\KeyResolver;
use Hypervel\RateLimiter\Limiter;
use Hypervel\RateLimiter\WorkerArrayStore;
use Hypervel\Reverb\Console\Commands\InstallCommand;
use Hypervel\Reverb\Contracts\ApplicationProvider;
use Hypervel\Reverb\Contracts\Logger;
use Hypervel\Reverb\Jobs\PingInactiveConnections;
use Hypervel\Reverb\Jobs\PruneStaleConnections;
use Hypervel\Reverb\Loggers\NullLogger;
use Hypervel\Reverb\Protocols\Pusher\Channels\CacheChannel;
use Hypervel\Reverb\Protocols\Pusher\Contracts\ChannelConnectionManager;
use Hypervel\Reverb\Protocols\Pusher\Contracts\ChannelManager;
use Hypervel\Reverb\Protocols\Pusher\Http\Controllers\ChannelController;
use Hypervel\Reverb\Protocols\Pusher\Http\Controllers\ChannelsController;
use Hypervel\Reverb\Protocols\Pusher\Http\Controllers\ChannelUsersController;
use Hypervel\Reverb\Protocols\Pusher\Http\Controllers\ConnectionsController;
use Hypervel\Reverb\Protocols\Pusher\Http\Controllers\EventsBatchController;
use Hypervel\Reverb\Protocols\Pusher\Http\Controllers\EventsController;
use Hypervel\Reverb\Protocols\Pusher\Http\Controllers\HealthCheckController;
use Hypervel\Reverb\Protocols\Pusher\Http\Controllers\UsersTerminateController;
use Hypervel\Reverb\Protocols\Pusher\Managers\ArrayChannelConnectionManager;
use Hypervel\Reverb\Protocols\Pusher\Managers\ArrayChannelManager;
use Hypervel\Reverb\Protocols\Pusher\MetricsHandler;
use Hypervel\Reverb\Protocols\Pusher\MetricType;
use Hypervel\Reverb\Protocols\Pusher\PendingMetric;
use Hypervel\Reverb\Protocols\Pusher\Server as PusherServer;
use Hypervel\Reverb\Protocols\Pusher\UserConnectionTerminator;
use Hypervel\Reverb\Servers\Hypervel\ChannelBroadcastPipeMessage;
use Hypervel\Reverb\Servers\Hypervel\Contracts\PubSubProvider;
use Hypervel\Reverb\Servers\Hypervel\Contracts\SharedState;
use Hypervel\Reverb\Servers\Hypervel\HttpServer;
use Hypervel\Reverb\Servers\Hypervel\MetricsRequestPipeMessage;
use Hypervel\Reverb\Servers\Hypervel\MetricsResponsePipeMessage;
use Hypervel\Reverb\Servers\Hypervel\ReverbRouter;
use Hypervel\Reverb\Servers\Hypervel\Scaling\SwooleTableSharedState;
use Hypervel\Reverb\Servers\Hypervel\TerminateUserPipeMessage;
use Hypervel\Reverb\Servers\Hypervel\WebSocketHandler;
use Hypervel\Reverb\Servers\Hypervel\WebSocketServer;
use Hypervel\Reverb\Webhooks\Contracts\WebhookDispatcher;
use Hypervel\Reverb\Webhooks\DeferredWebhookManager;
use Hypervel\Reverb\Webhooks\HttpWebhookDispatcher;
use Hypervel\Reverb\Webhooks\Jobs\FlushWebhookBatchJob;
use Hypervel\Reverb\Webhooks\WebhookBatchBuffer;
use Hypervel\Server\Event;
use Hypervel\Server\ServerInterface;
use Hypervel\Server\TlsOptions;
use Hypervel\Support\Facades\Log;
use Hypervel\Support\ServiceProvider;
use RuntimeException;
use Swoole\Table;
use Throwable;

class ReverbServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/reverb.php',
            'reverb'
        );

        if (! $this->app->make('config')->boolean('reverb.enabled')) {
            return;
        }

        $this->registerWebSocketServer();
        $this->registerReverbRouter();

        $this->app->singleton(ApplicationManager::class);
        $this->app->bind(
            ApplicationProvider::class,
            fn ($app) => $app->make(ApplicationManager::class)->driver()
        );

        if (! $this->app->bound(Logger::class)) {
            $this->app->instance(Logger::class, new NullLogger);
        }

        $this->app->singleton(ServerProviderManager::class);

        if (! $this->app->bound(ChannelManager::class)) {
            $this->app->alias(ArrayChannelManager::class, ChannelManager::class);
        }

        if (! $this->app->bound(ChannelConnectionManager::class)) {
            $this->app->bind(ChannelConnectionManager::class, ArrayChannelConnectionManager::class);
        }

        // WebSocketHandler keeps each connection in its owning worker for its lifetime,
        // so worker-local state reaches every message without shared I/O. Construct the
        // limiter directly so application rate-limiter config cannot replace the store.
        $this->app->when(PusherServer::class)
            ->needs(Limiter::class)
            ->give(static fn (): Limiter => new Limiter(
                new WorkerArrayStore,
                new KeyResolver('reverb-message-rate-limiter'),
            ));

        $this->app->singleton(WebhookDispatcher::class, HttpWebhookDispatcher::class);
        $this->app->singleton(DeferredWebhookManager::class);

        $this->app->singleton(WebhookBatchBuffer::class, function ($app) {
            $connectionName = $app->make('config')
                ->string('reverb.servers.reverb.scaling.connection');

            /** @var RedisFactory $redis */
            $redis = $app->make('redis');

            return new WebhookBatchBuffer(
                $redis->connection($connectionName)
            );
        });

        $this->app->make(ServerProviderManager::class)->register();

        // Laravel's standalone development commands are omitted; Hypervel's Swoole server owns this lifecycle.
    }

    /**
     * Register the Reverb WebSocket server in the server configuration.
     *
     * Appends a WebSocket server entry to `server.servers` so Swoole binds
     * the Reverb port alongside the main HTTP server. This is an intentional
     * register-time config mutation: it must run before ServerStartCommand
     * reads `server.servers` and before workers or coroutines exist. Do not
     * move this into boot/runtime code; config is process-global in Swoole
     * workers. This replaces Laravel Reverb's standalone ReactPHP server.
     */
    protected function registerWebSocketServer(): void
    {
        $config = $this->app->make('config');
        $reverbServer = $config->array('reverb.servers.reverb');

        $servers = $config->array('server.servers', []);
        /** @var array<string, mixed> $tlsConfiguration */
        $tlsConfiguration = $reverbServer['options']['tls'];
        $tls = TlsOptions::fromArray($tlsConfiguration);

        $servers[] = [
            'name' => 'reverb',
            'type' => ServerInterface::SERVER_WEBSOCKET,
            'host' => $reverbServer['host'],
            'port' => (int) $reverbServer['port'],
            'sock_type' => $tls->socketType(),
            'callbacks' => [
                Event::ON_REQUEST => [HttpServer::class, 'onRequest'],
                Event::ON_HANDSHAKE => [WebSocketServer::class, 'onHandshake'],
                Event::ON_MESSAGE => [WebSocketServer::class, 'onMessage'],
                Event::ON_CLOSE => [WebSocketServer::class, 'onClose'],
            ],
            'settings' => $this->resolveServerSettings($tls),
        ];

        $config->set('server.servers', $servers);
    }

    /**
     * Resolve Swoole settings for the Reverb server.
     *
     * TLS uses the server package's options instead of package-owned
     * certificate discovery.
     */
    protected function resolveServerSettings(TlsOptions $tls): array
    {
        return array_replace([
            'open_websocket_ping_frame' => true,
            'open_websocket_pong_frame' => true,
        ], $tls->settings());
    }

    /**
     * Register the isolated Reverb router singleton.
     *
     * Routes are registered later in boot() so config overrides
     * (e.g. path prefix) are applied before route registration.
     */
    protected function registerReverbRouter(): void
    {
        $this->app->singleton(ReverbRouter::class, fn ($app) => new ReverbRouter(
            $app->make('events'),
            $app,
        ));
    }

    /**
     * Register all Reverb routes on the isolated router.
     */
    protected function registerRoutes(ReverbRouter $router): void
    {
        $path = $this->app->make('config')->string('reverb.servers.reverb.path');

        $router->prefix($path)->group(function () use ($router) {
            $router->get('/app/{appKey}', WebSocketHandler::class);

            $router->post('/apps/{appId}/events', EventsController::class);
            $router->post('/apps/{appId}/batch_events', EventsBatchController::class);
            $router->get('/apps/{appId}/connections', ConnectionsController::class);
            $router->get('/apps/{appId}/channels', ChannelsController::class);
            $router->get('/apps/{appId}/channels/{channel}', ChannelController::class);
            $router->get('/apps/{appId}/channels/{channel}/users', ChannelUsersController::class);
            $router->post('/apps/{appId}/users/{userId}/terminate_connections', UsersTerminateController::class);

            $router->get('/up', HealthCheckController::class);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([InstallCommand::class]);

            $this->publishes([
                __DIR__ . '/../config/reverb.php' => config_path('reverb.php'),
            ], ['reverb', 'reverb-config']);
        }

        if (! $this->app->make('config')->boolean('reverb.enabled')) {
            return;
        }

        // Laravel Pulse integration is omitted; Reverb activity is recorded by Telescope.
        $this->registerRoutes($this->app->make(ReverbRouter::class));

        $this->app->make(ServerProviderManager::class)->boot();

        $this->registerPeriodicTasks();
        $this->registerPipeMessageListener();
        $this->registerShutdownHandler();
    }

    /**
     * Register periodic tasks for connection cleanup and table monitoring.
     */
    protected function registerPeriodicTasks(): void
    {
        $events = $this->app->make('events');

        $events->listen(AfterWorkerStart::class, function (AfterWorkerStart $event) {
            if ($event->server->taskworker) {
                return;
            }

            $timer = new Timer;

            $timer->tick(60.0, function () {
                $bus = $this->app->make(BusDispatcher::class);

                $bus->dispatch(new PruneStaleConnections);
                $bus->dispatch(new PingInactiveConnections);
                $this->checkTableCapacity();
                $this->recoverStaleWebhookBatches();
            });
        });
    }

    /**
     * Register the pipe message listener for intra-node broadcast fan-out.
     */
    protected function registerPipeMessageListener(): void
    {
        $events = $this->app->make('events');

        $events->listen(OnPipeMessage::class, function (OnPipeMessage $event) {
            if ($event->data instanceof MetricsResponsePipeMessage) {
                $this->app->make(MetricsHandler::class)->receive($event->data);

                return;
            }

            if ($event->data instanceof MetricsRequestPipeMessage) {
                $message = $event->data;
                $application = $this->app->make(ApplicationProvider::class)->findById($message->appId);
                $metric = new PendingMetric(
                    $message->requestId,
                    $application,
                    MetricType::from($message->metricType),
                    $message->options,
                );
                $response = new MetricsResponsePipeMessage(
                    $message->requestId,
                    $this->app->make(MetricsHandler::class)->get($metric),
                );

                if (! $event->server->sendMessage($response, $event->fromWorkerId)) {
                    throw new RuntimeException(
                        "Unable to return Reverb metrics to worker [{$event->fromWorkerId}].",
                    );
                }

                return;
            }

            if ($event->data instanceof TerminateUserPipeMessage) {
                $message = $event->data;
                $application = $this->app->make(ApplicationProvider::class)->findById($message->appId);
                $this->app->make(UserConnectionTerminator::class)->terminate($application, $message->userId);

                return;
            }

            if (! $event->data instanceof ChannelBroadcastPipeMessage) {
                return;
            }

            $message = $event->data;
            $application = $this->app->make(ApplicationProvider::class)->findById($message->appId);
            $channels = $this->app->make(ChannelManager::class)->for($application);
            $except = $message->exceptSocketId
                ? $channels->findConnection($message->exceptSocketId)
                : null;
            $exception = null;

            foreach ($message->channels as $channelName) {
                try {
                    $channel = $channels->find($channelName);

                    if (! $channel) {
                        continue;
                    }

                    $payload = $message->payload;
                    $payload['channel'] = $channel->name();

                    if ($message->internal) {
                        $channel->broadcastInternally($payload, $except?->connection());
                    } else {
                        $channel->broadcast($payload, $except?->connection());

                        if ($channel instanceof CacheChannel) {
                            $this->app->make(SharedState::class)->clearCacheMissLock($application->id(), $channel->name());
                        }
                    }
                } catch (Throwable $throwable) {
                    $exception ??= $throwable;
                }
            }

            if ($exception !== null) {
                throw $exception;
            }
        });
    }

    /**
     * Register the graceful shutdown handler for worker exit.
     *
     * Runs during onWorkerExit while the event loop is still active, so
     * coroutine-dependent operations (Redis pub/sub for scaling, webhook
     * dispatch) still work. This fires before WORKER_EXIT is resumed,
     * meaning periodic timers and the Redis subscriber are still running
     * — the drain does not conflict with them.
     */
    protected function registerShutdownHandler(): void
    {
        $events = $this->app->make('events');

        $events->listen(OnWorkerExit::class, function (OnWorkerExit $event) {
            if ($event->server->taskworker) {
                return;
            }

            $this->app->make(DeferredWebhookManager::class)->setDraining(true);

            try {
                $this->drainConnections();
            } catch (Throwable $throwable) {
                $this->reportShutdownFailure($throwable);
            }

            try {
                $this->disconnectScalingSubscriber();
            } catch (Throwable $throwable) {
                $this->reportShutdownFailure($throwable);
            }

            try {
                $this->flushWebhookBuffers();
            } catch (Throwable $throwable) {
                $this->reportShutdownFailure($throwable);
            }
        });
    }

    /**
     * Drain all active WebSocket connections on this worker.
     *
     * Atomically takes each connection from the registry, runs Reverb
     * cleanup (channel unsubscribe, slot release, presence events),
     * then closes the transport with a 1001 Going Away close code.
     */
    public function drainConnections(): void
    {
        $handler = $this->app->make(WebSocketHandler::class);
        $exception = null;

        foreach (array_keys(WebSocketHandler::connections()) as $fd) {
            try {
                $lifecycle = WebSocketHandler::takeConnection($fd);

                if ($lifecycle === null) {
                    continue;
                }

                $handler->closeLifecycle(
                    $lifecycle,
                    fn () => $lifecycle->connection()?->disconnect(1001, 'Server restarting'),
                );
            } catch (Throwable $throwable) {
                $exception ??= $throwable;
            }
        }

        if ($exception !== null) {
            throw $exception;
        }
    }

    /**
     * Report a shutdown failure without interrupting remaining cleanup.
     */
    protected function reportShutdownFailure(Throwable $throwable): void
    {
        FailureReporter::report($throwable);
    }

    /**
     * Disconnect the Redis pub/sub subscriber if scaling is enabled.
     */
    protected function disconnectScalingSubscriber(): void
    {
        $serverProvider = $this->app->make(ServerProviderManager::class);

        if ($serverProvider->subscribesToEvents()) {
            $this->app->make(PubSubProvider::class)->disconnect();
        }
    }

    /**
     * Flush any buffered webhook events to the queue.
     *
     * Reduces recovery delay from 60s to immediate by scheduling
     * flush jobs before the worker dies.
     */
    protected function flushWebhookBuffers(): void
    {
        $apps = $this->app->make(ApplicationProvider::class)->all();
        $buffer = $this->app->make(WebhookBatchBuffer::class);

        foreach ($apps as $app) {
            if (! $app->hasWebhooks()) {
                continue;
            }

            $webhooks = $app->webhooks();

            if (! ($webhooks['batching']['enabled'] ?? false)) {
                continue;
            }

            $buffer->clearFlushLock($app->id());

            if ($buffer->hasRemaining($app->id())) {
                FlushWebhookBatchJob::dispatch($app->id(), $webhooks)
                    ->onQueue('reverb-webhook-flush');
            }
        }
    }

    /**
     * Check Swoole Table capacity and log a warning if nearing the limit.
     */
    protected function checkTableCapacity(): void
    {
        $sharedState = $this->app->make(SharedState::class);

        if (! $sharedState instanceof SwooleTableSharedState) {
            return;
        }

        $this->checkSwooleTableUsage(
            $sharedState->table(),
            'Reverb shared state table is %.0f%% full (%d/%d rows). Increase reverb.servers.reverb.swoole_shared_state.rows to avoid connection failures.',
        );

        $this->checkSwooleTableUsage(
            $sharedState->lockTable(),
            'Reverb webhook lock table is %.0f%% full (%d/%d rows). Increase reverb.servers.reverb.swoole_shared_state.lock_rows to avoid missed webhook throttling.',
        );
    }

    /**
     * Check a Swoole Table's capacity and log a warning if above 80%.
     */
    protected function checkSwooleTableUsage(Table $table, string $messageFormat): void
    {
        $stats = $table->stats();
        $total = $stats['total_slice_num'];

        if ($total === 0) {
            return;
        }

        $used = $total - $stats['available_slice_num'];
        $usage = $used / $total;

        if ($usage > 0.8) {
            Log::warning(sprintf(
                $messageFormat,
                $usage * 100,
                $stats['num'],
                $table->getSize(),
            ));
        }
    }

    /**
     * Recover stale webhook batch processing keys from crashed flush jobs.
     *
     * Iterates all apps with batching enabled and checks for orphaned
     * processing hashes. If recovered, schedules an immediate flush.
     */
    protected function recoverStaleWebhookBatches(): void
    {
        $buffer = $this->app->make(WebhookBatchBuffer::class);
        $apps = $this->app->make(ApplicationProvider::class)->all();

        foreach ($apps as $app) {
            if (! $app->hasWebhooks()) {
                continue;
            }

            $webhooks = $app->webhooks();

            if (! ($webhooks['batching']['enabled'] ?? false)) {
                continue;
            }

            $recovered = $buffer->recoverStaleProcessingKeys($app->id());

            if ($recovered) {
                FlushWebhookBatchJob::dispatch($app->id(), $webhooks)
                    ->onQueue('reverb-webhook-flush');
            }
        }
    }
}
