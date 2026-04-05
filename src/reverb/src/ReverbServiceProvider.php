<?php

declare(strict_types=1);

namespace Hypervel\Reverb;

use Hypervel\Coordinator\Timer;
use Hypervel\Framework\Events\AfterWorkerStart;
use Hypervel\Framework\Events\OnPipeMessage;
use Hypervel\Framework\Events\OnWorkerStop;
use Hypervel\Reverb\Console\Commands\InstallCommand;
use Hypervel\Reverb\Contracts\ApplicationProvider;
use Hypervel\Reverb\Contracts\Logger;
use Hypervel\Reverb\Jobs\PingInactiveConnections;
use Hypervel\Reverb\Jobs\PruneStaleConnections;
use Hypervel\Reverb\Loggers\Log;
use Hypervel\Reverb\Loggers\NullLogger;
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
use Hypervel\Reverb\Protocols\Pusher\Server as PusherServer;
use Hypervel\Reverb\Servers\Hypervel\ChannelBroadcastPipeMessage;
use Hypervel\Reverb\Servers\Hypervel\Contracts\PubSubProvider;
use Hypervel\Reverb\Servers\Hypervel\Contracts\SharedState;
use Hypervel\Reverb\Servers\Hypervel\HttpServer;
use Hypervel\Reverb\Servers\Hypervel\ReverbRouter;
use Hypervel\Reverb\Servers\Hypervel\Scaling\SwooleTableSharedState;
use Hypervel\Reverb\Servers\Hypervel\WebSocketHandler;
use Hypervel\Reverb\Servers\Hypervel\WebSocketServer;
use Hypervel\Reverb\Webhooks\Contracts\WebhookDispatcher;
use Hypervel\Reverb\Webhooks\HttpWebhookDispatcher;
use Hypervel\Reverb\Webhooks\Jobs\FlushWebhookBatchJob;
use Hypervel\Reverb\Webhooks\WebhookBatchBuffer;
use Hypervel\Server\Event;
use Hypervel\Server\ServerInterface;
use Hypervel\Support\ServiceProvider;
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

        if (! $this->app->make('config')->get('reverb.enabled', true)) {
            return;
        }

        $this->registerWebSocketServer();
        $this->registerReverbRouter();

        $this->app->singleton(ApplicationManager::class);
        $this->app->bind(
            ApplicationProvider::class,
            fn ($app) => $app->make(ApplicationManager::class)->driver()
        );

        $this->app->instance(Logger::class, new NullLogger());

        $this->app->singleton(ServerProviderManager::class);

        $this->app->singleton(ChannelManager::class, ArrayChannelManager::class);
        $this->app->bind(ChannelConnectionManager::class, ArrayChannelConnectionManager::class);

        $this->app->singleton(WebhookDispatcher::class, HttpWebhookDispatcher::class);

        $this->app->singleton(WebhookBatchBuffer::class, function ($app) {
            $connectionName = (string) $app->make('config')
                ->get('reverb.servers.reverb.scaling.connection', 'reverb');

            return new WebhookBatchBuffer(
                $app->make('redis')->connection($connectionName)
            );
        });

        $this->app->make(ServerProviderManager::class)->register();
    }

    /**
     * Register the Reverb WebSocket server in the server configuration.
     *
     * Appends a WebSocket server entry to `server.servers` so Swoole binds
     * the Reverb port alongside the main HTTP server. This runs during
     * provider registration — before ServerStartCommand reads the config.
     */
    protected function registerWebSocketServer(): void
    {
        $config = $this->app->make('config');
        $reverbServer = $config->get('reverb.servers.reverb', []);

        $servers = $config->get('server.servers', []);

        $servers[] = [
            'name' => 'reverb',
            'type' => ServerInterface::SERVER_WEBSOCKET,
            'host' => $reverbServer['host'] ?? '0.0.0.0',
            'port' => (int) ($reverbServer['port'] ?? 8080),
            'sock_type' => SWOOLE_SOCK_TCP,
            'callbacks' => [
                Event::ON_REQUEST => [HttpServer::class, 'onRequest'],
                Event::ON_HAND_SHAKE => [WebSocketServer::class, 'onHandShake'],
                Event::ON_MESSAGE => [WebSocketServer::class, 'onMessage'],
                Event::ON_CLOSE => [WebSocketServer::class, 'onClose'],
            ],
            'settings' => [
                'open_websocket_ping_frame' => true,
                'open_websocket_pong_frame' => true,
            ],
        ];

        $config->set('server.servers', $servers);
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
        $path = $this->app->make('config')->get('reverb.servers.reverb.path', '');

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

        if (! $this->app->make('config')->get('reverb.enabled', true)) {
            return;
        }

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

            $timer = new Timer();

            $timer->tick(60.0, function () {
                PruneStaleConnections::dispatch();
                PingInactiveConnections::dispatch();
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
            if (! $event->data instanceof ChannelBroadcastPipeMessage) {
                return;
            }

            $message = $event->data;
            $application = $this->app->make(ApplicationProvider::class)->findById($message->appId);
            $channels = $this->app->make(ChannelManager::class)->for($application);

            foreach ($message->channels as $channelName) {
                $channel = $channels->find($channelName);

                if (! $channel) {
                    continue;
                }

                $except = $message->exceptSocketId
                    ? $channels->connections()[$message->exceptSocketId] ?? null
                    : null;

                $payload = $message->payload;
                $payload['channel'] = $channel->name();

                if ($message->internal) {
                    $channel->broadcastInternally($payload, $except?->connection());
                } else {
                    $channel->broadcast($payload, $except?->connection());
                }
            }
        });
    }

    /**
     * Register the graceful shutdown handler for worker stop.
     */
    protected function registerShutdownHandler(): void
    {
        $events = $this->app->make('events');

        $events->listen(OnWorkerStop::class, function (OnWorkerStop $event) {
            if ($event->server->taskworker) {
                return;
            }

            try {
                $this->drainConnections();
            } catch (Throwable $e) {
                Log::error('Shutdown: connection drain failed — ' . $e->getMessage());
            }

            try {
                $this->disconnectScalingSubscriber();
            } catch (Throwable $e) {
                Log::error('Shutdown: scaling subscriber disconnect failed — ' . $e->getMessage());
            }

            try {
                $this->flushWebhookBuffers();
            } catch (Throwable $e) {
                Log::error('Shutdown: webhook buffer flush failed — ' . $e->getMessage());
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
        $pusherServer = $this->app->make(PusherServer::class);

        foreach (array_keys(WebSocketHandler::connections()) as $fd) {
            try {
                $connection = WebSocketHandler::takeConnection($fd);

                if ($connection === null) {
                    continue;
                }

                $pusherServer->close($connection);

                $connection->disconnect(1001, 'Server restarting');
            } catch (Throwable $e) {
                Log::error("Shutdown: failed to drain connection fd={$fd} — " . $e->getMessage());
            }
        }
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

        $stats = $sharedState->table()->stats();
        $total = $stats['total_slice_num'];

        if ($total === 0) {
            return;
        }

        $used = $total - $stats['available_slice_num'];
        $usage = $used / $total;

        if ($usage > 0.8) {
            Log::error(sprintf(
                'Reverb shared state table is %.0f%% full (%d/%d rows). Increase reverb.table.rows to avoid connection failures.',
                $usage * 100,
                $stats['num'],
                $sharedState->table()->getSize(),
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
